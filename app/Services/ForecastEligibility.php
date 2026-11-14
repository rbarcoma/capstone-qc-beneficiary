<?php

namespace App\Services;

use App\Models\Building;
use App\Models\Dataset;
use App\Models\ProcessedRecord;
use Illuminate\Support\Carbon;

class ForecastEligibility
{
    public const MINIMUM_OBSERVATIONS = 16;

    /** @param array<string, mixed>|null $inspection
     * @return array{eligible: bool, reason: string|null, observations: int, required: int, target_month: string|null, last_month: string|null, dataset_id: int|null}
     */
    public function check(Building $building, ?Dataset $dataset, ?array $inspection = null): array
    {
        $inspection ??= app(BuildingDataset::class)->inspect($building);
        $rows = $dataset?->records()->orderBy('month')->get();
        $last = $rows?->last()?->month->toDateString();
        $base = ['eligible' => false, 'reason' => null, 'observations' => $rows?->count() ?? $inspection['verified_periods'], 'required' => self::MINIMUM_OBSERVATIONS,
            'target_month' => $last ? Carbon::parse($last)->addMonth()->toDateString() : null, 'last_month' => $last, 'dataset_id' => $dataset?->id];
        if (! $dataset || ! $rows || ! $last) {
            return [...$base, 'reason' => 'missing_current_dataset'];
        }
        if ($dataset->fingerprint !== $inspection['fingerprint']) {
            return [...$base, 'reason' => 'stale_dataset'];
        }
        $coverage = array_keys($inspection['manifest']['coverage']);
        sort($coverage);
        if ($coverage !== [] && end($coverage) !== $last) {
            return [...$base, 'reason' => 'latest_period_not_completely_verified'];
        }
        if ($rows->count() < self::MINIMUM_OBSERVATIONS) {
            return [...$base, 'reason' => 'insufficient_history'];
        }
        $previous = null;
        foreach ($rows as $index => $row) {
            if ($previous && $previous->copy()->addMonth()->toDateString() !== $row->month->toDateString()) {
                return [...$base, 'reason' => 'non_contiguous_verified_history'];
            }
            $previous = $row->month;
            if ($index >= 2 && ! $this->weatherComplete($row)) {
                return [...$base, 'reason' => 'missing_or_unverified_weather'];
            }
        }

        return [...$base, 'eligible' => true];
    }

    private function weatherComplete(ProcessedRecord $row): bool
    {
        $snapshot = $row->weather_snapshot['observed'] ?? [];
        if (! $row->weather_complete || array_intersect_key($snapshot['units'] ?? [], NasaPowerWeather::UNITS) != NasaPowerWeather::UNITS) {
            return false;
        }
        foreach (NasaPowerWeather::UNITS as $key => $unit) {
            $value = $snapshot['weather_values'][$key] ?? null;
            if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value === -999.0
                || ($key === 'T2M' && $value < -273.15) || ($key !== 'T2M' && $value < 0) || ($key === 'RH2M' && $value > 100)) {
                return false;
            }
        }

        return true;
    }
}
