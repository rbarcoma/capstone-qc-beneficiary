<?php

namespace App\Services;

use App\Models\Dataset;
use App\Models\ProcessedRecord;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Process\Process;

class PythonForecast
{
    /** @return array<string, mixed> */
    public function evaluate(Dataset $dataset, User $user): array
    {
        Gate::forUser($user)->authorize('create', Dataset::class);
        Gate::forUser($user)->authorize('view', $dataset);
        $inspection = app(BuildingDataset::class)->inspect($dataset->building);
        if ($inspection['fingerprint'] !== $dataset->fingerprint) {
            throw ValidationException::withMessages(['dataset' => 'Reprocess current verified data before evaluating a forecast.']);
        }
        $records = $dataset->records()->orderBy('month')->get();
        $lineage = ['dataset_id' => $dataset->id, 'dataset_version' => $dataset->version, 'dataset_fingerprint' => $dataset->fingerprint];
        $latestCoverage = array_keys($inspection['manifest']['coverage']);
        sort($latestCoverage);
        if ($latestCoverage !== [] && end($latestCoverage) !== $records->last()?->month->toDateString()) {
            return [...$lineage, 'contract_version' => 1, 'eligible' => false, 'reason' => 'latest_period_not_completely_verified'];
        }
        $payload = [
            'contract_version' => 1,
            'records' => $records->map(fn (ProcessedRecord $row) => [
                'month' => $row->month->toDateString(), 'kwh' => (float) $row->kwh,
                'weather' => $row->weather_snapshot['observed']['weather_values'] ?? null,
                'weather_complete' => $row->weather_complete,
                'weather_units' => array_intersect_key($row->weather_snapshot['observed']['units'] ?? [], NasaPowerWeather::UNITS),
            ])->values()->all(),
        ];
        $process = new Process([(string) config('forecasting.python'), base_path('python/forecast.py')], base_path(), ['OMP_NUM_THREADS' => '1', 'OPENBLAS_NUM_THREADS' => '1']);
        $process->setTimeout((float) config('forecasting.timeout_seconds'));
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Python forecast contract failed. Check the configured isolated runtime and contract; no forecast was issued.');
        }
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($result) || ($result['contract_version'] ?? null) !== 1 || ! is_bool($result['eligible'] ?? null)) {
            throw new RuntimeException('Python returned an unsupported forecast response.');
        }

        return [...$result, ...$lineage];
    }
}
