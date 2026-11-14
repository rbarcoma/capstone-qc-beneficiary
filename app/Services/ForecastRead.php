<?php

namespace App\Services;

use App\Models\Building;
use App\Models\Dataset;
use App\Models\Forecast;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ForecastRead
{
    /** @return array<string, mixed> */
    public function read(Building $building, User $user, ?int $selectedRun = null): array
    {
        return DB::transaction(fn () => $this->snapshot(Building::findOrFail($building->id), $user, $selectedRun));
    }

    /** @return array<string, mixed> */
    private function snapshot(Building $building, User $user, ?int $selectedRun): array
    {
        abort_unless($user->hasBuildingAccess($building), 403);
        $inspection = app(BuildingDataset::class)->inspect($building);
        $dataset = Dataset::where('building_id', $building->id)->where('fingerprint', $inspection['fingerprint'])->first();
        $candidate = app(ForecastEligibility::class)->check($building, $dataset, $inspection);
        if (! $dataset && Dataset::where('building_id', $building->id)->exists()) {
            $candidate['reason'] = 'stale_dataset';
        }
        $candidate['datasetFingerprint'] = $dataset?->fingerprint;
        $engine = app(ForecastIssuance::class)->engineSignature();
        $current = $candidate['eligible'] && $dataset ? Forecast::where('issuance_key', app(ForecastIssuance::class)->key($dataset, $engine))->first() : null;
        $history = Forecast::where('building_id', $building->id)->with(['building', 'dataset', 'actualError'])->latest('id')->limit(20)->get();
        if ($current && ! $history->contains('id', $current->id)) {
            $history->push($current->load(['building', 'dataset', 'actualError']));
        }
        if ($selectedRun !== null && ! $history->contains('id', $selectedRun)) {
            $selected = Forecast::with(['building', 'dataset', 'actualError'])->where('building_id', $building->id)->whereKey($selectedRun)->firstOrFail();
            $history->push($selected);
        }
        $items = $history->map(fn (Forecast $forecast) => $this->project($forecast, $inspection, $current?->id, $engine))->values();
        if ($selectedRun !== null) {
            abort_unless($history->contains('id', $selectedRun), 404);
        }
        $evaluation = $selectedRun !== null ? $items->firstWhere('id', $selectedRun) : ($current ? $items->firstWhere('id', $current->id) : $items->firstWhere('sourceCurrent', true));

        return [
            'eligibility' => $candidate, 'current' => $current ? $items->firstWhere('id', $current->id) : null,
            'history' => $items, 'evaluation' => $evaluation,
            'canRun' => $user->can('create', Forecast::class),
            'latestVerifiedPeriod' => $inspection['rows'] === [] ? null : $inspection['rows'][count($inspection['rows']) - 1]['month'],
            'observations' => $dataset ? $dataset->records()->orderBy('month')->get()->map(fn ($row) => ['month' => $row->month->toDateString(), 'kwh' => (float) $row->kwh]) : [],
        ];
    }

    /** @param array<string, mixed> $inspection
     * @return array<string, mixed>
     */
    private function project(Forecast $forecast, array $inspection, ?int $currentId, string $engine): array
    {
        $sourceCurrent = app(ForecastHistory::class)->trainingCurrent($forecast);
        $expectedActual = app(ForecastActuals::class)->state($forecast, $inspection);
        $stored = $forecast->actualError;
        $actualValid = $expectedActual['valid'] && $stored?->valid && $stored->state_hash === $expectedActual['state_hash'];

        return [
            'id' => $forecast->id, 'datasetVersion' => $forecast->dataset->version,
            'targetMonth' => $forecast->target_month->toDateString(), 'trainingEndMonth' => $forecast->training_end_month->toDateString(),
            'issuedAt' => $forecast->issued_at, 'modelVersion' => $forecast->model_version,
            'sourceCurrent' => $sourceCurrent,
            'state' => ! $sourceCurrent ? 'superseded_source' : ($forecast->id === $currentId ? 'current' : ($forecast->engine_signature !== $engine ? 'historical_engine' : 'historical')),
            'predictionKwh' => $sourceCurrent ? (float) $forecast->predicted_kwh : null,
            'baselineKwh' => $sourceCurrent ? (float) $forecast->baseline_kwh : null,
            'lowerKwh' => $sourceCurrent ? (float) $forecast->lower_kwh : null, 'upperKwh' => $sourceCurrent ? (float) $forecast->upper_kwh : null,
            'validationLabel' => $sourceCurrent ? $forecast->worker_result['validation_label'] : 'Source changed; evidence retained in audit history, excluded here.',
            'metrics' => $sourceCurrent ? $forecast->worker_result['metrics'] : null,
            'evidence' => $sourceCurrent ? $forecast->worker_result['evidence'] : [],
            'actual' => ['valid' => $actualValid, 'reason' => $actualValid ? 'verified_actual' : ($expectedActual['valid'] ? 'reconciliation_required' : $expectedActual['reason']),
                'kwh' => $actualValid ? (float) $stored->actual_kwh : null,
                'absoluteError' => $actualValid ? $stored->absolute_error : null,
                'percentageError' => $actualValid ? $stored->percentage_error : null],
        ];
    }
}
