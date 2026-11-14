<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Forecast;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ForecastScenario
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function calculate(Building $building, User $user, array $input): array
    {
        abort_unless($user->hasBuildingAccess($building), 403);
        foreach (['reduction_percent', 'capacity_kwp', 'performance_ratio'] as $field) {
            if (isset($input[$field]) && is_float($input[$field]) && ! is_finite($input[$field])) {
                throw ValidationException::withMessages([$field => 'Use a finite scenario assumption.']);
            }
        }
        $data = Validator::make($input, [
            'expected_forecast_id' => ['required', 'integer', 'min:1'], 'reduction_percent' => ['required', 'numeric', 'between:0,100'],
            'capacity_kwp' => ['nullable', 'required_with:performance_ratio', 'numeric', 'between:0,1000000'],
            'performance_ratio' => ['nullable', 'required_with:capacity_kwp', 'numeric', 'between:0,1'],
            'tariff_php_per_kwh' => ['prohibited'], 'target_month' => ['prohibited'], 'solar_irradiance' => ['prohibited'],
        ])->validate();

        return DB::transaction(function () use ($building, $user, $data) {
            $view = app(ForecastRead::class)->read($building, $user);
            $current = $view['current'];
            if (! $current || (int) $data['expected_forecast_id'] !== $current['id']) {
                throw ValidationException::withMessages(['scenario' => 'A current issued forecast is required. Refresh the reviewed source run first.']);
            }
            $forecast = Forecast::with('dataset')->findOrFail($current['id']);
            Gate::forUser($user)->authorize('view', $forecast);
            $last = $forecast->dataset->records()->orderByDesc('month')->firstOrFail();
            $source = $last->weather_snapshot['observed'] ?? null;
            $dailySolar = $source['weather_values']['ALLSKY_SFC_SW_DWN'] ?? null;
            $hasSolar = ($source['is_complete'] ?? false) && ($source['units']['ALLSKY_SFC_SW_DWN'] ?? null) === NasaPowerWeather::UNITS['ALLSKY_SFC_SW_DWN']
                && is_numeric($dailySolar) && is_finite((float) $dailySolar) && $dailySolar >= 0;
            $result = $this->arithmetic((float) $forecast->predicted_kwh, $forecast->target_month->daysInMonth, (float) $data['reduction_percent'],
                isset($data['capacity_kwp']) ? (float) $data['capacity_kwp'] : null, isset($data['performance_ratio']) ? (float) $data['performance_ratio'] : null,
                $hasSolar ? (float) $dailySolar : null);
            $result += ['forecastId' => $forecast->id, 'buildingId' => $building->id, 'targetMonth' => $forecast->target_month->toDateString(),
                'climateProxyMonth' => $forecast->training_end_month->toDateString(), 'modelVersion' => $forecast->model_version,
                'solarSource' => $hasSolar ? ['source' => $source['source'], 'latitude' => $source['latitude'], 'longitude' => $source['longitude'], 'units' => $source['units']['ALLSKY_SFC_SW_DWN'], 'contentHash' => $source['content_hash']] : null];
            AuditLog::create(['actor_id' => $user->id, 'forecast_id' => $forecast->id, 'event' => 'scenario.calculated',
                'after' => ['arithmetic_only' => true, 'assumptions' => $data]]);

            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function arithmetic(float $forecast, int $days, float $reduction, ?float $capacity = null, ?float $ratio = null, ?float $dailySolar = null): array
    {
        foreach ([$forecast, $reduction, $capacity, $ratio, $dailySolar] as $number) {
            if ($number !== null && ! is_finite($number)) {
                throw ValidationException::withMessages(['scenario' => 'All assumptions must be finite.']);
            }
        }
        if ($forecast < 0 || $days < 28 || $days > 31 || $reduction < 0 || $reduction > 100 || ($capacity === null) !== ($ratio === null)
            || ($capacity !== null && ($capacity < 0 || $capacity > 1000000 || $ratio < 0 || $ratio > 1)) || ($dailySolar !== null && $dailySolar < 0)) {
            throw ValidationException::withMessages(['scenario' => 'Use valid monthly units and explicit physical assumptions.']);
        }
        $adjusted = $forecast * (1 - $reduction / 100);
        $solar = null;
        if ($capacity !== null && $ratio !== null && $dailySolar !== null) {
            // Daily kWh/m² divided by standard 1 kW/m² gives equivalent sun hours.
            $energy = $capacity * $dailySolar * $days * $ratio;
            if (! is_finite($energy)) {
                throw ValidationException::withMessages(['scenario' => 'The solar assumptions exceed supported finite arithmetic.']);
            }
            $ceiling = min($forecast, $energy);
            $solar = ['capacityKwp' => $capacity, 'performanceRatio' => $ratio, 'historicalDailySolar' => $dailySolar, 'targetCalendarDays' => $days,
                'generationKwh' => round($energy, 3), 'offsetCeilingKwh' => round($ceiling, 3),
                'offsetCeilingPercent' => $forecast > 0 ? round($ceiling / $forecast * 100, 3) : null,
                'gridEnergyLowerBoundKwh' => round(max(0, $forecast - $ceiling), 3),
                'formula' => 'capacity_kWp × (historical_daily_kWh/m² ÷ 1 kW/m²) × target_days × performance_ratio'];
        }

        return ['issuedKwh' => $forecast, 'reductionPercent' => $reduction, 'scenarioKwh' => round($adjusted, 3),
            'assumedDifferenceKwh' => round($forecast - $adjusted, 3), 'costDifferencePhp' => null, 'costState' => 'approved_tariff_unavailable',
            'solar' => $solar, 'solarState' => $solar ? 'assumption_only_not_feasibility' : ($capacity === null ? 'explicit_capacity_and_ratio_required' : 'complete_source_solar_unavailable')];
    }
}
