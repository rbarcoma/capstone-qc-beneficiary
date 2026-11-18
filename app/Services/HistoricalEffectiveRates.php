<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\BillingEvidence;
use App\Models\Building;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class HistoricalEffectiveRates
{
    /** @return array<string, mixed>|null */
    public function read(Building $building, User $user): ?array
    {
        return DB::transaction(function () use ($building, $user) {
            $actor = User::findOrFail($user->id);
            abort_unless($actor->canAccessModule('decision_support'), 403);
            $building = Building::findOrFail($building->id);
            Gate::forUser($actor)->authorize('view', $building);
            if (! $actor->hasRole(UserRole::Admin, UserRole::EnergyManager, UserRole::BudgetOfficer, UserRole::Viewer)) {
                return null;
            }
            $meters = Meter::where('building_id', $building->id)->orderBy('id')->get();
            $records = MonthlyRecord::whereIn('meter_id', $meters->modelKeys())
                ->where('month', '<', now('Asia/Manila')->startOfMonth()->toDateString())->orderBy('month')->orderBy('id')->get();
            $evidence = BillingEvidence::whereIn('monthly_record_id', $records->modelKeys())->orderByDesc('version')->get()->unique('monthly_record_id')->keyBy('monthly_record_id');
            $groups = $records->groupBy(fn (MonthlyRecord $record) => $record->month->toDateString());
            $rows = [];
            $gross = BigDecimal::zero();
            $net = BigDecimal::zero();
            $energy = BigDecimal::zero();
            $included = [];
            $excluded = [];
            $math = app(BillingEvidenceMath::class);
            $workflow = app(BillingEvidenceWorkflow::class);
            $from = $records->first()?->month;
            $through = $records->last()?->month;
            if ($from && $through) {
                for ($month = $from->copy(); $month->lte($through); $month = $month->addMonth()) {
                    $period = $month->toDateString();
                    $sources = [];
                    $sumE = BigDecimal::zero();
                    $sumX = BigDecimal::zero();
                    $sumI = BigDecimal::zero();
                    $sumO = BigDecimal::zero();
                    $sumA = BigDecimal::zero();
                    foreach ($meters as $meter) {
                        $record = $groups->get($period)?->firstWhere('meter_id', $meter->id);
                        $bill = $record ? $evidence->get($record->id) : null;
                        $reason = ! $record ? 'monthly_record_missing' : (! $bill ? 'billing_evidence_missing' : $workflow->sourceReason($bill, $record));
                        if ($reason === null && $bill) {
                            if ($bill->verification_status !== 'verified' || ! $bill->verified_at || ! $bill->verified_by || $bill->verified_by === $bill->author_id) {
                                $reason = 'independent_verification_required';
                            } elseif ($bill->verified_fingerprint !== $math->fingerprint($bill)) {
                                $reason = 'evidence_changed';
                            } else {
                                $result = $math->assess($bill);
                                $reason = $result['valid'] ? null : $result['reason'];
                            }
                        }
                        $sources[] = ['meterId' => $meter->id, 'recordId' => $record?->id, 'recordVersion' => $record?->version,
                            'evidenceId' => $bill?->id, 'evidenceVersion' => $bill?->version, 'state' => $reason ?? 'verified'];
                        if ($reason === null && $bill) {
                            $sumE = $sumE->plus($bill->energy_amount_php);
                            $sumX = $sumX->plus($bill->export_credit_php);
                            $sumI = $sumI->plus($bill->import_kwh);
                            $sumO = $sumO->plus($bill->other_charges_php);
                            $sumA = $sumA->plus($bill->applied_credits_php);
                            $sources[array_key_last($sources)] += ['periodStart' => $bill->period_start->toDateString(), 'periodEnd' => $bill->period_end->toDateString(), 'pages' => $bill->source_pages];
                        }
                    }
                    $complete = $meters->isNotEmpty() && count(array_filter($sources, fn ($source) => $source['state'] !== 'verified')) === 0;
                    $serviceNet = $sumE->plus($sumX);
                    $rows[] = [
                        'month' => $period, 'state' => $complete ? ($sumI->isZero() ? 'zero_import' : 'available') : 'incomplete',
                        'importKwh' => $complete ? (string) $sumI->toScale(3) : null,
                        'grossPhp' => $complete ? $math::money($sumE) : null, 'netServicePhp' => $complete ? $math::money($serviceNet) : null,
                        'exportCreditPhp' => $complete ? $math::money($sumX) : null,
                        'excludedOtherPhp' => $complete ? $math::money($sumO) : null, 'excludedCreditsPhp' => $complete ? $math::money($sumA) : null,
                        'grossRate' => $complete ? $math::rate((string) $sumE, (string) $sumI) : null,
                        'netServiceRate' => $complete ? $math::rate((string) $serviceNet, (string) $sumI) : null,
                        'sources' => $sources,
                    ];
                    if ($complete) {
                        $included[] = $period;
                        $gross = $gross->plus($sumE);
                        $net = $net->plus($serviceNet);
                        $energy = $energy->plus($sumI);
                    } else {
                        $excluded[] = $period;
                    }
                }
            }

            return [
                'asOf' => now('Asia/Manila')->toIso8601String(), 'method' => 'reconciled-import-effective-rate-v1',
                'requiredMeterCount' => $meters->count(), 'fromMonth' => $from?->toDateString(), 'throughMonth' => $through?->toDateString(),
                'rows' => $rows, 'includedMonths' => $included, 'excludedMonths' => $excluded,
                'weighted' => $included === [] ? null : ['importKwh' => (string) $energy->toScale(3), 'grossPhp' => $math::money($gross), 'netServicePhp' => $math::money($net),
                    'grossRate' => $math::rate((string) $gross, (string) $energy), 'netServiceRate' => $math::rate((string) $net, (string) $energy)],
            ];
        });
    }
}
