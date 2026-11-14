<?php

namespace App\Services;

use App\Models\Forecast;

class ForecastHistory
{
    public function trainingCurrent(Forecast $forecast, bool $locked = false): bool
    {
        $through = $forecast->training_end_month->toDateString();
        $pipeline = app(BuildingDataset::class);
        $fresh = $locked ? $pipeline->inspectLocked($forecast->building, $through) : $pipeline->inspect($forecast->building->fresh(), $through);
        // Later actuals must not invalidate earlier causal training evidence.
        // Both manifests are compared only through the original training end.
        $original = $forecast->dataset->input_manifest;
        $original['cutoff'] = $through;

        return DataFingerprint::hash($original) === $fresh['fingerprint'];
    }
}
