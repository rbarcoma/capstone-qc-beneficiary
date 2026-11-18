<?php

namespace App\Services;

use App\Models\Building;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DecisionSupportInformation
{
    /**
     * DSS descriptions/catalogue are read-time information, never stored advice.
     * Read from one database snapshot; do not reuse historical run values.
     *
     * @return array<string, mixed>
     */
    public function read(Building $building, User $user): array
    {
        return DB::transaction(function () use ($building, $user) {
            $actor = User::findOrFail($user->id);
            abort_unless($actor->canAccessModule('decision_support'), 403);
            $building = Building::findOrFail($building->id);
            Gate::forUser($actor)->authorize('view', $building);
            $inspection = app(BuildingDataset::class)->inspect($building);
            $forecast = app(ForecastRead::class)->read($building, $actor);
            $comparison = $this->comparison($forecast, $inspection);
            $history = app(DecisionSupportHistory::class)->read($inspection);

            return [
                'forecast' => $forecast,
                'comparison' => $comparison,
                'readiness' => $this->readiness($forecast, $inspection),
                'exploratoryHistory' => $history,
                'conservationCatalogue' => app(ConservationCatalogue::class)->read($comparison, $history),
                'historicalRates' => app(HistoricalEffectiveRates::class)->read($building, $actor),
            ];
        });
    }

    /** @param array<string, mixed> $forecast
     * @param  array<string, mixed>  $inspection
     * @return array<string, mixed>
     */
    private function comparison(array $forecast, array $inspection): array
    {
        $run = $forecast['current'];
        if (! $run || ! $run['sourceCurrent'] || $run['predictionKwh'] === null) {
            return ['state' => 'current_forecast_unavailable'];
        }
        $reference = $inspection['rows'] === [] ? null : $inspection['rows'][count($inspection['rows']) - 1];
        if (! $reference || $reference['month'] !== $run['trainingEndMonth']
            || Carbon::parse($reference['month'])->addMonth()->toDateString() !== $run['targetMonth']) {
            return ['state' => 'reference_unavailable'];
        }
        $predicted = (float) $run['predictionKwh'];
        $actual = (float) $reference['kwh'];
        if (! is_finite($predicted) || ! is_finite($actual) || $predicted < 0 || $actual < 0) {
            return ['state' => 'reference_unavailable'];
        }

        return [
            'state' => 'available', 'forecastId' => $run['id'], 'datasetId' => $forecast['eligibility']['dataset_id'],
            'datasetVersion' => $run['datasetVersion'], 'datasetFingerprint' => $forecast['eligibility']['datasetFingerprint'],
            'modelVersion' => $run['modelVersion'], 'targetMonth' => $run['targetMonth'], 'referenceMonth' => $reference['month'],
            'forecastKwh' => $predicted, 'referenceKwh' => $actual, 'differenceKwh' => round($predicted - $actual, 3),
            'differencePercent' => $actual > 0 ? round(($predicted - $actual) / $actual * 100, 3) : null,
            'referenceRecords' => array_map(fn ($source) => ['recordId' => $source['id'], 'meterId' => $source['meter_id'], 'version' => $source['version']], $reference['source_snapshot']),
        ];
    }

    /** @param array<string, mixed> $forecast
     * @param  array<string, mixed>  $inspection
     * @return array<string, mixed>
     */
    private function readiness(array $forecast, array $inspection): array
    {
        $eligibility = $forecast['eligibility'];
        $coverage = $this->coverage($inspection);
        $messages = [
            'missing_current_dataset' => 'No current processed dataset is available. Process complete verified building months before forecasting.',
            'stale_dataset' => 'Source inputs changed. Reprocess and review current inputs; historical forecast values are not used for R2.',
            'latest_period_not_completely_verified' => 'The latest recorded period is not completely verified for every required meter.',
            'insufficient_history' => 'There are fewer complete observations than the existing technical forecast requirement.',
            'non_contiguous_verified_history' => 'Verified history has a calendar gap. No missing bill values are filled automatically.',
            'missing_or_unverified_weather' => 'Required weather evidence is incomplete or has invalid units/values. Forecasting remains unavailable.',
        ];
        $notices = [];
        if ($coverage['requiredMeterCount'] === 0) {
            $notices[] = ['code' => 'no_registered_meters', 'message' => 'No registered non-removed meters are available for this building.'];
        }
        if ($coverage['fromMonth'] === null) {
            $notices[] = ['code' => 'no_billing_history', 'message' => 'No billing history is recorded. An expected coverage window cannot yet be determined.'];
        }
        if ($coverage['incompleteMonths'] !== []) {
            $notices[] = ['code' => 'incomplete_billing', 'message' => 'Recorded history contains missing or unverified meter bills. Partial building totals are not used.'];
        }
        if ($eligibility['reason'] !== null) {
            $notices[] = ['code' => $eligibility['reason'], 'message' => $messages[$eligibility['reason']] ?? 'Current inputs do not meet the existing technical forecast requirements.'];
        }
        $weatherMonths = array_values(array_map(fn ($row) => $row['month'], array_filter($inspection['rows'], fn ($row) => ! $row['weather_complete'])));
        if ($weatherMonths !== []) {
            $notices[] = ['code' => 'incomplete_source_weather', 'message' => 'Some complete billing months have incomplete observed weather. Required predictor weather is checked separately by forecast eligibility.'];
        }
        $state = $forecast['current'] ? 'current_forecast' : ($eligibility['eligible'] ? 'eligible_not_issued' : 'not_eligible');
        if ($eligibility['eligible']) {
            $notices[] = ['code' => $state, 'message' => $forecast['current']
                ? 'A current eligible forecast is issued. This is technical readiness, not a confidence or accuracy rating.'
                : 'Inputs meet the existing technical guard, but no forecast is issued for the current dataset and runtime. This notice does not issue one.'];
        }

        return [
            'state' => $state, 'asOf' => now()->toIso8601String(), 'eligible' => $eligibility['eligible'],
            'eligibilityReason' => $eligibility['reason'], 'observations' => $eligibility['observations'], 'requiredObservations' => $eligibility['required'],
            'latestCompleteMonth' => $forecast['latestVerifiedPeriod'], 'coverage' => $coverage,
            'incompleteWeatherMonths' => $weatherMonths, 'notices' => $notices,
        ];
    }

    /** @param array<string, mixed> $inspection
     * @return array<string, mixed>
     */
    private function coverage(array $inspection): array
    {
        $meterIds = array_column($inspection['manifest']['required_meters'], 'id');
        $periods = $inspection['manifest']['coverage'];
        $months = array_keys($periods);
        sort($months);
        $base = ['fromMonth' => $months[0] ?? null, 'throughMonth' => $months === [] ? null : $months[count($months) - 1],
            'requiredMeterCount' => count($meterIds), 'expectedMeterPeriods' => null, 'verifiedMeterPeriods' => null,
            'missingMeterPeriods' => null, 'unverifiedMeterPeriods' => null, 'incompleteMonths' => []];
        if ($months === []) {
            return $base;
        }
        $expected = $verified = $missing = $unverified = 0;
        $incomplete = [];
        // Only the recorded first-to-last window: no invented upload deadline,
        // pre-onboarding coverage or calendar-age/overdue policy.
        for ($cursor = Carbon::parse($base['fromMonth']); $cursor->toDateString() <= $base['throughMonth']; $cursor->addMonth()) {
            $month = $cursor->toDateString();
            $records = $periods[$month] ?? [];
            $recordMeterIds = array_column($records, 'meter_id');
            $verifiedIds = array_column(array_filter($records, fn ($record) => $record['status'] === 'verified'), 'meter_id');
            $missingIds = array_values(array_diff($meterIds, $recordMeterIds));
            $unverifiedIds = array_values(array_diff($recordMeterIds, $verifiedIds));
            $expected += count($meterIds);
            $verified += count($verifiedIds);
            $missing += count($missingIds);
            $unverified += count($unverifiedIds);
            if ($missingIds !== [] || $unverifiedIds !== []) {
                $incomplete[] = ['month' => $month, 'verifiedMeterCount' => count($verifiedIds),
                    'missingMeterIds' => $missingIds, 'unverifiedMeterIds' => $unverifiedIds];
            }
        }

        return [...$base, 'expectedMeterPeriods' => $expected, 'verifiedMeterPeriods' => $verified,
            'missingMeterPeriods' => $missing, 'unverifiedMeterPeriods' => $unverified, 'incompleteMonths' => $incomplete];
    }
}
