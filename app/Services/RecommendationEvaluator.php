<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Building;
use App\Models\DssConfiguration;
use App\Models\Recommendation;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Application evaluator for activated R1-R5 conditional proposals.
 *
 * Inputs come only from the current authorized inspection and the pinned,
 * approved configuration versions named by the deployment activation. It
 * creates no action and mutates no billing data. R6 stays on request and R7
 * remains a read-time readiness notice, so neither is ever stored here.
 */
final class RecommendationEvaluator
{
    /**
     * @return array{created: int, existing: int, targetMonth: string, fingerprint: string, message: string}
     */
    public function evaluate(Building $building, User $user): array
    {
        return DB::transaction(function () use ($building, $user) {
            $workflow = app(DssConfigurationWorkflow::class);
            $building = Building::lockForUpdate()->findOrFail($building->id);
            app(BuildingDataset::class)->inspectLocked($building);
            $actor = $workflow->actor(User::lockForUpdate()->findOrFail($user->id));
            Gate::forUser($actor)->authorize('view', $building);
            $info = app(DecisionSupportInformation::class)->read($building, $actor);
            $run = $info['forecast']['current'];
            if ($run === null) {
                throw ValidationException::withMessages(['evaluation' => 'A current issued forecast is required before evaluating.']);
            }
            $activation = app(RecommendationPolicy::class)->resolve($building, $run['targetMonth'], $actor, lock: true);
            if ($activation['state'] !== 'active') {
                throw ValidationException::withMessages(['evaluation' => $activation['reason']]);
            }
            $comparison = $info['comparison'];
            if ($comparison['state'] !== 'available') {
                throw ValidationException::withMessages(['evaluation' => 'The current forecast comparison is unavailable. Verify current inputs before evaluating.']);
            }

            $created = 0;
            $existing = 0;
            foreach ($activation['rules'] as $code) {
                $rule = $activation['rulesByCode'][$code] ?? null;
                if (! $rule instanceof DssConfiguration || ($rule->payload['mode'] ?? null) !== 'conditional_proposal') {
                    continue;
                }
                foreach ($this->measurements($rule, $activation, $info) as $sample) {
                    if ($sample['value'] === null || ! $this->matches($sample['value'], (string) ($rule->payload['operator'] ?? ''), (string) ($rule->payload['threshold'] ?? ''))) {
                        continue;
                    }
                    $identity = ['forecast_id' => $run['id'], 'rule_code' => $code, 'subject_key' => $sample['subject'],
                        'policy_fingerprint' => $activation['fingerprint']];
                    $row = Recommendation::firstOrCreate($identity, [
                        'subject_snapshot' => $sample['snapshot'],
                        'source_fingerprint' => $comparison['datasetFingerprint'],
                        'title' => $this->string($rule->payload['title'] ?? null) ?? $code.' review',
                        'message' => $this->message($rule, $sample, $comparison),
                        'evidence' => [...$sample['evidence'], 'targetMonth' => $run['targetMonth'],
                            'measure' => $rule->payload['measure'] ?? null, 'operator' => $rule->payload['operator'] ?? null,
                            'threshold' => $rule->payload['threshold'] ?? null, 'referenceMethod' => $rule->payload['reference_method'] ?? null,
                            'bindings' => $sample['bindings']],
                        'priority' => in_array($rule->payload['priority'] ?? null, ['low', 'medium', 'high'], true) ? $rule->payload['priority'] : null,
                        'contains_cost_data' => $code === 'R1',
                        'evaluation_status' => 'evaluated',
                    ]);
                    if ($row->wasRecentlyCreated) {
                        $created++;
                        AuditLog::create(['actor_id' => $actor->id, 'forecast_id' => $row->forecast_id, 'event' => 'recommendation.evaluated',
                            'after' => ['recommendation_id' => $row->id, ...$identity, 'subject_snapshot' => $sample['snapshot'],
                                'bindings' => $sample['bindings']], 'reason' => 'Activation '.$activation['authorization']]);
                    } else {
                        $existing++;
                    }
                }
            }

            AuditLog::create(['actor_id' => $actor->id, 'forecast_id' => $run['id'], 'event' => 'recommendation.evaluation',
                'after' => ['target_month' => $run['targetMonth'], 'policy_fingerprint' => $activation['fingerprint'],
                    'authorization' => $activation['authorization'], 'rules' => $activation['rules'],
                    'created' => $created, 'existing' => $existing], 'reason' => $building->code]);

            return ['created' => $created, 'existing' => $existing, 'targetMonth' => $run['targetMonth'],
                'fingerprint' => $activation['fingerprint'],
                'message' => $created + $existing > 0 ? "Evaluation complete: {$created} new, {$existing} previously evaluated."
                    : 'No activated rule condition was met. Existing recommendations were not changed.'];
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $activation
     * @param  array<string, mixed>  $info
     * @return list<array{subject: string, snapshot: array<string, mixed>|null, value: string|null, evidence: array<string, mixed>, bindings: list<array<string, mixed>>}>
     */
    private function measurements(DssConfiguration $rule, array $activation, array $info): array
    {
        $comparison = $info['comparison'];
        $history = $info['exploratoryHistory'];
        $measure = (string) ($rule->payload['measure'] ?? '');
        $percent = str_ends_with($measure, '_percent');
        $samples = [];
        switch ($rule->subject_key) {
            case 'R1':
                $tariff = $activation['tariffs']['R1'] ?? null;
                $basis = $tariff?->payload['tariff_basis'] ?? null;
                $rate = $tariff?->payload['rate_php_per_kwh'] ?? null;
                if ($tariff instanceof DssConfiguration && in_array($basis, ['energy_only', 'blended_planning'], true)
                    && is_string($rate) && ($tariff->source_confirmation['contentHash'] ?? null) === $tariff->approved_hash) {
                    $cost = BigDecimal::of((string) $comparison['forecastKwh'])->multipliedBy($rate)->toScale(2, RoundingMode::HalfUp);
                    $samples[] = ['subject' => 'building', 'snapshot' => null, 'value' => (string) $cost,
                        'evidence' => ['forecastKwh' => $comparison['forecastKwh'], 'ratePhpPerKwh' => $rate, 'tariffBasis' => $basis,
                            'includedCharges' => $tariff->payload['included_charges'] ?? null, 'excludedCharges' => $tariff->payload['excluded_charges'] ?? null,
                            'value' => (string) $cost, 'unit' => 'PHP'],
                        'bindings' => [$this->binding($rule), $this->binding($tariff)]];
                }
                break;
            case 'R2':
                $value = $comparison[$percent ? 'differencePercent' : 'differenceKwh'];
                $samples[] = ['subject' => 'building', 'snapshot' => null, 'value' => $value === null ? null : (string) $value,
                    'evidence' => ['referenceMonth' => $comparison['referenceMonth'], 'forecastKwh' => $comparison['forecastKwh'],
                        'referenceKwh' => $comparison['referenceKwh'], 'differenceKwh' => $comparison['differenceKwh'],
                        'differencePercent' => $comparison['differencePercent'], 'value' => $value === null ? null : (string) $value,
                        'unit' => $percent ? '%' : 'kWh'],
                    'bindings' => [$this->binding($rule)]];
                break;
            case 'R3':
                $sample = $history['consumption'];
                $value = $sample[$percent ? 'differencePercent' : 'difference'];
                $samples[] = ['subject' => 'building', 'snapshot' => null, 'value' => $value === null ? null : (string) $value,
                    'evidence' => ['assessedMonth' => $sample['actual']['month'], 'referenceMonths' => $history['referenceMonths'],
                        'actualKwh' => $sample['actual']['value'], 'referenceAverageKwh' => $sample['referenceAverage'],
                        'differenceKwh' => $sample['difference'], 'differencePercent' => $sample['differencePercent'],
                        'value' => $value === null ? null : (string) $value, 'unit' => $percent ? '%' : 'kWh'],
                    'bindings' => [$this->binding($rule)]];
                break;
            case 'R4':
                foreach ($history['meters'] as $sample) {
                    $value = $sample[$percent ? 'differencePercent' : 'difference'];
                    $samples[] = ['subject' => 'meter:'.$sample['meterId'], 'snapshot' => ['meterId' => $sample['meterId']],
                        'value' => $value === null ? null : (string) $value,
                        'evidence' => ['meterId' => $sample['meterId'], 'assessedMonth' => $sample['actual']['month'],
                            'referenceMonths' => $history['referenceMonths'], 'actualKw' => $sample['actual']['value'],
                            'referenceAverageKw' => $sample['referenceAverage'], 'differenceKw' => $sample['difference'],
                            'differencePercent' => $sample['differencePercent'], 'value' => $value === null ? null : (string) $value,
                            'unit' => $percent ? '%' : 'kW'],
                        'bindings' => [$this->binding($rule)]];
                }
                break;
            case 'R5':
                foreach ($info['conservationCatalogue']['entries'] as $entry) {
                    $key = $entry['id'];
                    $pair = $activation['catalogues'][$key] ?? null;
                    if (! is_array($pair) || ($entry['requirements'][0]['state'] ?? null) !== 'available') {
                        continue;
                    }
                    $catalogue = $pair['catalogue'];
                    $review = $pair['review'];
                    $samples[] = ['subject' => 'catalogue:'.$key,
                        'snapshot' => ['catalogueEntry' => $key, 'catalogueConfigurationId' => $catalogue->id,
                            'catalogueVersion' => $catalogue->version, 'catalogueHash' => $catalogue->approved_hash,
                            'reviewConfigurationId' => $review->id, 'reviewVersion' => $review->version, 'reviewHash' => $review->approved_hash],
                        'value' => '1',
                        'evidence' => ['catalogueEntry' => $key, 'catalogueTitle' => $entry['title'], 'catalogueVersion' => $catalogue->version,
                            'reviewVersion' => $review->version, 'requirement' => $entry['requirements'][0]['label'],
                            'value' => '1', 'unit' => 'review'],
                        'bindings' => [$this->binding($rule), $this->binding($catalogue), $this->binding($review)]];
                }
                break;
        }

        return $samples;
    }

    private function matches(string|int|float $value, string $operator, string $threshold): bool
    {
        if ($threshold === '') {
            return false;
        }
        $number = BigDecimal::of((string) $value);
        $cmp = ($operator === 'abs_gt' ? $number->abs() : $number)->compareTo($threshold);

        return match ($operator) {
            'gt', 'abs_gt' => $cmp > 0, 'gte' => $cmp >= 0, 'lt' => $cmp < 0, 'lte' => $cmp <= 0, default => false,
        };
    }

    /** @param array<string, mixed> $sample
     * @param  array<string, mixed>  $comparison
     */
    private function message(DssConfiguration $rule, array $sample, array $comparison): string
    {
        $amount = $sample['value'];
        $difference = ($amount > 0 ? '+' : '').$amount.($sample['evidence']['unit'] === '%' ? '%' : ' '.$sample['evidence']['unit']);

        return match ($rule->subject_key) {
            'R1' => 'Estimated PHP '.$amount.' for '.$comparison['targetMonth'].': '.$this->number($comparison['forecastKwh']).' kWh at PHP '
                .$sample['evidence']['ratePhpPerKwh'].'/kWh ('.($sample['evidence']['tariffBasis'] === 'energy_only' ? 'energy-only' : 'blended planning').' basis). '
                .'Excluded charges: '.($this->string($sample['evidence']['excludedCharges'] ?? null) ?? 'none stated').'.',
            'R2' => 'Forecast '.$comparison['targetMonth'].' differs from verified '.$comparison['referenceMonth'].' by '.$difference.'.',
            'R3' => 'Verified '.$sample['evidence']['assessedMonth'].' consumption differs from the three-month average by '.$difference.'.',
            'R4' => 'Meter '.$sample['evidence']['meterId'].' billed demand for '.$sample['evidence']['assessedMonth'].' differs from the three-month average by '
                .$difference.'.',
            'R5' => $this->string($sample['evidence']['catalogueTitle'] ?? null).' — exact reviewed case '.$sample['evidence']['catalogueEntry']
                .' (v'.$sample['evidence']['catalogueVersion'].') is available. '.$sample['evidence']['requirement'],
            default => 'Activated rule condition met.',
        };
    }

    private function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return array{kind: string, subject_key: string, id: int, version: int, hash: string|null} */
    private function binding(DssConfiguration $row): array
    {
        return ['kind' => $row->kind, 'subject_key' => $row->subject_key, 'id' => $row->id,
            'version' => $row->version, 'hash' => $row->approved_hash];
    }
}
