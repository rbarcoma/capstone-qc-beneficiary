<?php

namespace Tests\Support;

use App\Models\AuditLog;
use App\Models\Building;
use App\Models\DssConfiguration;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\BuildingDataset;
use App\Services\DataFingerprint;
use App\Services\DecisionSupportInformation;
use App\Services\DssConfigurationWorkflow;
use App\Services\ForecastScenario;
use App\Services\RecommendationWorkflow;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Dev-autoload only. No route, job, command, scheduler or production registration. */
final class SyntheticRecommendationEvaluator
{
    public const FIXTURE = 'Synthetic DSS evaluator fixture v1';

    public function evaluate(Building $building, User $user, ?array $solarInputs = null): array
    {
        return $this->withCandidates($building, $user, function ($candidates, $information, $actor) use ($building, $solarInputs) {
            $ids = [];
            foreach ($candidates as [$identity, $attributes]) {
                $row = Recommendation::firstOrCreate($identity, $attributes);
                $ids[] = $row->id;
                if ($row->wasRecentlyCreated) {
                    AuditLog::create(['event' => 'recommendation.synthetic_evaluated', 'actor_id' => $actor->id,
                        'forecast_id' => $row->forecast_id, 'after' => ['recommendation_id' => $row->id, ...$identity]]);
                }
            }

            return ['ids' => $ids, 'readiness' => $information['readiness'], 'solar' => $solarInputs === null ? null
                : app(ForecastScenario::class)->calculate($building, $actor, $solarInputs)];
        });
    }

    /** Re-resolve the reviewed contract under lock before exercising the real decision service. */
    public function decide(Recommendation $row, User $user, array $input): void
    {
        $this->guard();
        $this->withCandidates($row->forecast->building, $user, function ($candidates, $_information, $actor) use ($row, $input) {
            foreach ($candidates as [$identity, $attributes]) {
                $match = Recommendation::where($identity)->whereKey($row->id)->lockForUpdate()->first();
                // Use the model's JSON casts on both sides (e.g. 420.0 stored as 420).
                $expected = (new Recommendation($attributes))->only(array_keys($attributes));
                if (! $match || DataFingerprint::hash($match->only(array_keys($attributes))) !== DataFingerprint::hash($expected)) {
                    continue;
                }
                FixtureReviewPolicy::install();
                config(['recommendations.policy_fingerprint' => $identity['policy_fingerprint']]);
                try {
                    app(RecommendationWorkflow::class)->decide($match, $actor, $input);
                } finally {
                    config(['recommendations.policy_fingerprint' => null]);
                }

                return;
            }
            throw ValidationException::withMessages(['recommendation' => 'The synthetic evidence or reviewed contract is no longer current.']);
        });
    }

    public static function guard(): void
    {
        if (! app()->environment('testing') || ! app()->runningInConsole() || config('recommendations.policy_fingerprint') !== null || config('recommendations.activations') !== []
            || DB::connection()->getDatabaseName() !== 'testing' || DB::scalar('SELECT DATABASE()') !== 'testing') {
            throw new LogicException('Synthetic evaluation requires the isolated testing database and disabled runtime policy.');
        }
    }

    private function withCandidates(Building $building, User $user, callable $consume): mixed
    {
        $this->guard();

        return DB::transaction(function () use ($building, $user, $consume) {
            $workflow = app(DssConfigurationWorkflow::class);
            $building = Building::lockForUpdate()->findOrFail($building->id);
            $actor = $workflow->actor(User::lockForUpdate()->findOrFail($user->id));
            Gate::forUser($actor)->authorize('view', $building);
            app(BuildingDataset::class)->inspectLocked($building);
            $info = app(DecisionSupportInformation::class)->read($building, $actor);
            $run = $info['forecast']['current'];
            $candidates = [];
            if ($run && $info['comparison']['state'] === 'available') {
                // Select latest first: a newer draft, expired version or revocation cannot revive old advice.
                $versions = DssConfiguration::where('building_id', $building->id)->orderByDesc('version')->lockForUpdate()->get()
                    ->unique(fn ($c) => $c->kind.':'.$c->subject_key)->filter(fn ($c) => $c->status === 'approved' && $c->reviewer_id !== null
                        && $c->reviewer_id !== $c->author_id && $c->payload['rationale'] === self::FIXTURE && $c->approved_hash === $c->content_hash
                        && $workflow->hash($c) === $c->approved_hash && $c->valid_from !== null && $c->valid_from->toDateString() <= $run['targetMonth']
                        && $c->valid_to?->toDateString() >= $run['targetMonth'] && $workflow->fresh($c, $actor))
                    ->keyBy(fn ($c) => $c->kind.':'.$c->subject_key);
                foreach ($versions->where('kind', 'rule') as $rule) {
                    $p = $rule->payload;
                    if ($p['mode'] !== 'conditional_proposal') {
                        continue;
                    }
                    foreach ($this->measurements($rule, $versions, $info) as [$subject, $snapshot, $value, $facts, $dependencies]) {
                        if ($value === null || ! $this->matches($value, $p['operator'], $p['threshold'])) {
                            continue;
                        }
                        $bindings = array_map(fn ($c) => ['id' => $c->id, 'version' => $c->version, 'hash' => $c->approved_hash], [$rule, ...$dependencies]);
                        $identity = ['forecast_id' => $run['id'], 'rule_code' => $rule->subject_key, 'subject_key' => $subject,
                            'policy_fingerprint' => DataFingerprint::hash([self::FIXTURE, $bindings])];
                        $attributes = ['subject_snapshot' => $snapshot, 'source_fingerprint' => $info['comparison']['datasetFingerprint'],
                            'title' => 'Synthetic '.$rule->subject_key.' review', 'message' => 'Fictional policy condition met.', 'evaluation_status' => 'evaluated',
                            'contains_cost_data' => $rule->subject_key === 'R1', 'priority' => $p['priority'], 'evidence' => ['synthetic' => true,
                                'targetMonth' => $run['targetMonth'], 'measure' => $p['measure'], 'value' => (string) $value, 'operator' => $p['operator'],
                                'threshold' => $p['threshold'], 'bindings' => $bindings, ...$facts]];
                        $candidates[] = [$identity, $attributes];
                    }
                }
            }

            return $consume($candidates, $info, $actor);
        }, 3);
    }

    private function matches(mixed $value, string $operator, string $threshold): bool
    {
        $number = BigDecimal::of((string) $value);
        $cmp = ($operator === 'abs_gt' ? $number->abs() : $number)->compareTo($threshold);

        return match ($operator) {
            'gt', 'abs_gt' => $cmp > 0, 'gte' => $cmp >= 0, 'lt' => $cmp < 0, 'lte' => $cmp <= 0, default => false,
        };
    }

    private function measurements(DssConfiguration $rule, Collection $versions, array $info): iterable
    {
        $comparison = $info['comparison'];
        $history = $info['exploratoryHistory'];
        $measure = $rule->payload['measure'];
        $percent = str_ends_with($measure, '_percent');
        switch ($rule->subject_key) {
            case 'R1':
                $tariff = $versions->get('tariff:R1');
                if ($tariff && $tariff->payload['tariff_basis'] === 'energy_only' && ($tariff->source_confirmation['contentHash'] ?? null) === $tariff->approved_hash) {
                    $rate = $tariff->payload['rate_php_per_kwh'];
                    yield ['building', null, (string) BigDecimal::of((string) $comparison['forecastKwh'])->multipliedBy($rate),
                        ['forecastKwh' => $comparison['forecastKwh'], 'energyOnlyRatePhpPerKwh' => $rate], [$tariff]];
                }
                break;
            case 'R2':
                yield ['building', null, $comparison[$percent ? 'differencePercent' : 'differenceKwh'], ['comparison' => $comparison], []];
                break;
            case 'R3':
            case 'R4':
                $meter = $rule->subject_key === 'R4';
                foreach ($meter ? $history['meters'] : [$history['consumption']] as $sample) {
                    yield [$meter ? 'meter:'.$sample['meterId'] : 'building', $meter ? ['meterId' => $sample['meterId']] : null,
                        $sample[$percent ? 'differencePercent' : 'difference'] ?? null, ['history' => $sample], []];
                }
                break;
            case 'R5':
                foreach ($info['conservationCatalogue']['entries'] as $entry) {
                    $key = $entry['id'];
                    $catalogue = $versions->get('catalogue:'.$key);
                    $review = $versions->get('review:'.$key);
                    if ($catalogue && $review && (int) $review->payload['catalogue_configuration_id'] === $catalogue->id
                        && $entry['requirements'][0]['state'] === 'available') {
                        // Fixture measure: presence of an exact independently reviewed case, never a savings score.
                        yield ['catalogue:'.$key, ['catalogueEntry' => $key, 'catalogueConfigurationId' => $catalogue->id,
                            'catalogueVersion' => $catalogue->version, 'catalogueHash' => $catalogue->approved_hash], 1,
                            ['reviewVersion' => $review->version], [$catalogue, $review]];
                    }
                }
                break;
                // R6 is on request; R7 is a read-time notice even without an issued forecast.
        }
    }
}
