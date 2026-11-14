<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Forecast;
use App\Models\ForecastError;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ForecastActuals
{
    /** @param array<string, mixed> $inspection
     * @return array<string, mixed>
     */
    public function state(Forecast $forecast, array $inspection, bool $locked = false): array
    {
        $trainingCurrent = app(ForecastHistory::class)->trainingCurrent($forecast, $locked);
        $actual = null;
        foreach ($inspection['rows'] as $row) {
            if ($row['month'] === $forecast->target_month->toDateString()) {
                $actual = $row;
                break;
            }
        }
        $valid = $trainingCurrent && $actual !== null;
        $reason = ! $trainingCurrent ? 'training_source_changed' : ($actual === null ? 'awaiting_complete_verified_actual' : 'verified_actual');
        $snapshot = $valid ? $actual['source_snapshot'] : null;
        $state = ['valid' => $valid, 'reason' => $reason, 'source_snapshot' => $snapshot];
        $state['state_hash'] = DataFingerprint::hash($state);
        if ($valid) {
            $error = abs($actual['kwh'] - (float) $forecast->predicted_kwh);
            $state += ['actual_kwh' => $actual['kwh'], 'absolute_error' => $error, 'squared_error' => $error ** 2,
                'percentage_error' => $actual['kwh'] > 0 ? $error / $actual['kwh'] * 100 : null];
        }

        return $state;
    }

    public function reconcile(Building $building, ?User $actor = null): void
    {
        if (! Forecast::where('building_id', $building->id)->exists()) {
            return;
        }
        DB::transaction(function () use ($building, $actor) {
            $inspection = app(BuildingDataset::class)->inspectLocked($building);
            $forecasts = Forecast::with(['building', 'dataset'])->where('building_id', $building->id)->orderBy('id')->lockForUpdate()->get();
            foreach ($forecasts as $forecast) {
                $state = $this->state($forecast, $inspection, true);
                $existing = ForecastError::where('forecast_id', $forecast->id)->lockForUpdate()->first();
                if ($existing && $existing->state_hash === $state['state_hash']) {
                    continue;
                }
                $before = $existing?->toArray();
                // On retraction retain the last measured values/snapshot; valid=false
                // excludes them from displays/metrics, with complete audit history.
                if (! $state['valid'] && $existing) {
                    unset($state['source_snapshot']);
                }
                $error = ForecastError::updateOrCreate(['forecast_id' => $forecast->id], [...$state, 'evaluated_at' => now()]);
                AuditLog::create(['actor_id' => $actor?->id, 'forecast_id' => $forecast->id, 'event' => 'forecast.actual_reconciled', 'before' => $before, 'after' => $error->toArray()]);
            }
        }, 3);
    }
}
