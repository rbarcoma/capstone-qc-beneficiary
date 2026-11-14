<?php

namespace App\Services;

use App\Models\Dataset;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ForecastResultValidator
{
    /** @param array<string, mixed> $result */
    public function validate(array $result, Dataset $dataset, string $target, string $workerHash): void
    {
        // Reject non-finite values before Laravel's numeric rules stringify them.
        foreach (['prediction_kwh', 'baseline_kwh', 'error_band.lower_kwh', 'error_band.upper_kwh'] as $field) {
            $this->number(data_get($result, $field));
        }
        $validator = Validator::make($result, [
            'contract_version' => ['required', 'in:1'], 'eligible' => ['required', 'accepted'],
            'model' => ['required', 'in:linear_regression'], 'baseline' => ['required', 'in:previous_3_month_average'],
            'target_month' => ['required', 'in:'.$target], 'model_version' => ['required', 'in:'.$workerHash],
            'dataset_id' => ['required', 'in:'.$dataset->id], 'dataset_version' => ['required', 'in:'.$dataset->version],
            'dataset_fingerprint' => ['required', 'in:'.$dataset->fingerprint],
            'prediction_kwh' => ['required', 'numeric', 'min:0', 'max:999999999999999'],
            'baseline_kwh' => ['required', 'numeric', 'min:0', 'max:999999999999999'],
            'error_band.lower_kwh' => ['required', 'numeric', 'min:0'], 'error_band.upper_kwh' => ['required', 'numeric', 'max:999999999999999'],
            'error_band.method' => ['required', 'in:observed_max_absolute_walk_forward_error'],
            'evidence' => ['required', 'array', 'size:3'], 'metrics' => ['required', 'array'],
            'runtime.python' => ['required', 'string'], 'runtime.numpy' => ['required', 'string'], 'runtime.scikit_learn' => ['required', 'string'],
        ]);
        if ($validator->fails() || ($result['eligible'] ?? null) !== true || ! array_key_exists('calibrated_confidence_level', $result['error_band'] ?? [])
            || $result['error_band']['calibrated_confidence_level'] !== null) {
            $this->fail();
        }
        $this->number($result['prediction_kwh']);
        $source = $dataset->records()->orderBy('month')->get()->values();
        $evidence = $result['evidence'];
        $maxError = 0.0;
        foreach ($evidence as $index => $row) {
            $position = $source->count() - count($evidence) + $index;
            $expected = $source->get($position);
            if (! is_array($row) || ! $expected || ($row['month'] ?? null) !== $expected->month->toDateString()
                || ($row['train_end_month'] ?? null) !== $expected->month->copy()->subMonth()->toDateString()
                || ($row['training_rows'] ?? null) !== $position - 3) {
                $this->fail();
            }
            $this->same($row['actual_kwh'] ?? null, (float) $expected->kwh);
            $this->number($row['linear_kwh'] ?? null);
            if (abs($row['linear_kwh']) > 999999999999999) {
                $this->fail();
            }
            $baseline = $source->slice($position - 3, 3)->sum(fn ($item) => (float) $item->kwh) / 3;
            $this->same($row['baseline_kwh'] ?? null, $baseline);
            $maxError = max($maxError, abs($row['actual_kwh'] - $row['linear_kwh']));
        }
        foreach (['linear_regression' => 'linear_kwh', 'previous_3_month_average' => 'baseline_kwh'] as $model => $field) {
            $actual = array_column($evidence, 'actual_kwh');
            $errors = array_map(fn ($row) => $row['actual_kwh'] - $row[$field], $evidence);
            $squared = array_sum(array_map(fn ($error) => $error * $error, $errors));
            $mean = array_sum($actual) / count($actual);
            $variance = array_sum(array_map(fn ($value) => ($value - $mean) ** 2, $actual));
            $metrics = $result['metrics'][$model] ?? [];
            if (($metrics['test_months'] ?? null) !== count($evidence)) {
                $this->fail();
            }
            $this->same($metrics['mae'] ?? null, array_sum(array_map(abs(...), $errors)) / count($errors));
            $this->same($metrics['rmse'] ?? null, sqrt($squared / count($errors)));
            if ($variance === 0.0) {
                if (! array_key_exists('r2', $metrics) || $metrics['r2'] !== null) {
                    $this->fail();
                }
            } else {
                $this->same($metrics['r2'] ?? null, 1 - $squared / $variance);
            }
        }
        $this->same($result['baseline_kwh'], $source->slice(-3)->sum(fn ($row) => (float) $row->kwh) / 3);
        $this->same($result['error_band']['lower_kwh'], max(0, $result['prediction_kwh'] - $maxError));
        $this->same($result['error_band']['upper_kwh'], $result['prediction_kwh'] + $maxError);
        if (($result['error_band']['validation_months'] ?? null) !== count($evidence)) {
            $this->fail();
        }
    }

    private function number(mixed $number): void
    {
        if ((! is_float($number) && ! is_int($number)) || ! is_finite((float) $number)) {
            $this->fail();
        }
    }

    private function same(mixed $actual, float $expected): void
    {
        $this->number($actual);
        if (abs($actual - $expected) > max(0.000001, abs($expected) * 0.00000001)) {
            $this->fail();
        }
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['forecast' => 'The forecast response failed its source/evidence contract. No run was issued.']);
    }
}
