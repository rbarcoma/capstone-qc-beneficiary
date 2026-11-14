<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WeatherRecord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class NasaPowerWeather
{
    public const ENDPOINT = 'https://power.larc.nasa.gov/api/temporal/daily/point';

    public const UNITS = ['T2M' => 'C', 'RH2M' => '%', 'PRECTOTCORR' => 'mm/day', 'ALLSKY_SFC_SW_DWN' => 'kW-hr/m^2/day'];

    public const AGGREGATION = 'complete-daily-calendar-month-v1';

    public function locationKey(): string
    {
        return DataFingerprint::hash(['source' => 'NASA_POWER', 'community' => 'RE', 'time_standard' => 'LST', 'latitude' => config('weather.latitude'), 'longitude' => config('weather.longitude'), 'aggregation' => self::AGGREGATION]);
    }

    /** @return array{created: int, updated: int, unchanged: int, incomplete: int} */
    public function sync(string $start, string $end, ?User $actor = null): array
    {
        Validator::make(['start' => $start, 'end' => $end, 'latitude' => config('weather.latitude'), 'longitude' => config('weather.longitude')], [
            'start' => ['required', 'date_format:Y-m', 'after_or_equal:1984-01'],
            'end' => ['required', 'date_format:Y-m', 'after_or_equal:start', 'before:'.now('Asia/Manila')->startOfMonth()->format('Y-m')],
            'latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'],
        ])->validate();
        $first = Carbon::parse($start.'-01');
        $last = Carbon::parse($end.'-01');
        if ($first->diffInMonths($last) > 35) {
            throw ValidationException::withMessages(['end' => 'Sync at most 36 completed months per request.']);
        }

        $rows = [];
        $cursor = $first->copy();
        // One request per intersecting year, never one request per building/day.
        while ($cursor->lte($last)) {
            $until = $cursor->copy()->endOfYear()->min($last->copy()->endOfMonth());
            $query = [
                'parameters' => implode(',', array_keys(self::UNITS)), 'community' => 'RE',
                'latitude' => config('weather.latitude'), 'longitude' => config('weather.longitude'),
                'start' => $cursor->format('Ymd'), 'end' => $until->format('Ymd'), 'format' => 'JSON', 'time-standard' => 'LST',
            ];
            try {
                $response = Http::acceptJson()->connectTimeout(10)->timeout(45)->retry(2, 500, throw: false)->get(self::ENDPOINT, $query);
            } catch (ConnectionException|RequestException) {
                throw ValidationException::withMessages(['weather' => 'NASA POWER could not be reached. No weather records were changed.']);
            }
            if (! $response->successful()) {
                throw ValidationException::withMessages(['weather' => 'NASA POWER returned HTTP '.$response->status().'. No weather records were changed.']);
            }
            $payload = $response->json();
            if (! is_array($payload)) {
                throw ValidationException::withMessages(['weather' => 'NASA POWER returned an invalid JSON response.']);
            }
            $this->validateMetadata($payload);
            while ($cursor->lte($until)) {
                $rows[] = $this->aggregateMonth($cursor, $payload, $query);
                $cursor->addMonth()->startOfMonth();
            }
        }

        // All requests/metadata validation finish before the first DB write.
        return DB::transaction(function () use ($rows, $actor) {
            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'incomplete' => 0];
            foreach ($rows as $row) {
                $existing = WeatherRecord::where('location_key', $row['location_key'])->where('month', $row['month'])->first();
                $counts[$existing === null ? 'created' : ($existing->content_hash === $row['content_hash'] ? 'unchanged' : 'updated')]++;
                $counts['incomplete'] += $row['is_complete'] ? 0 : 1;
                WeatherRecord::updateOrCreate(['location_key' => $row['location_key'], 'month' => $row['month']], $row);
            }
            AuditLog::create(['actor_id' => $actor?->id, 'event' => 'weather.synced', 'after' => $counts]);

            return $counts;
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    private function validateMetadata(array $payload): void
    {
        $valid = ($payload['type'] ?? null) === 'Feature'
            && data_get($payload, 'header.time_standard') === 'LST'
            && is_numeric(data_get($payload, 'header.fill_value'))
            && (float) data_get($payload, 'header.fill_value') < -273.15
            && data_get($payload, 'header.api.name') === 'POWER Daily API'
            && is_string(data_get($payload, 'header.api.version'))
            && is_array(data_get($payload, 'header.sources'))
            && is_array(data_get($payload, 'properties.parameter'));
        $coordinates = data_get($payload, 'geometry.coordinates');
        $valid = $valid && is_array($coordinates) && isset($coordinates[0], $coordinates[1])
            && is_numeric($coordinates[0]) && is_numeric($coordinates[1])
            && abs((float) $coordinates[0] - (float) config('weather.longitude')) <= 0.001
            && abs((float) $coordinates[1] - (float) config('weather.latitude')) <= 0.001;
        foreach (self::UNITS as $parameter => $unit) {
            $valid = $valid && data_get($payload, 'parameters.'.$parameter.'.units') === $unit;
        }
        if (! $valid) {
            throw ValidationException::withMessages(['weather' => 'NASA POWER metadata, units or coordinates do not match the verified Daily RE/LST contract. No records were changed.']);
        }
    }

    /**
     * Preserve every returned daily value, but never compute a partial-month
     * mean/total. Null/fill/absent/invalid days invalidate that parameter.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function aggregateMonth(Carbon $month, array $payload, array $query): array
    {
        $values = [];
        $raw = [];
        $missing = [];
        $totals = [];
        foreach (self::UNITS as $parameter => $unit) {
            $series = data_get($payload, 'properties.parameter.'.$parameter, []);
            $series = is_array($series) ? $series : [];
            $sum = 0.0;
            $missingDays = [];
            $raw[$parameter] = [];
            for ($day = 1; $day <= $month->daysInMonth; $day++) {
                $key = $month->format('Ym').str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                $value = $series[$key] ?? null;
                $raw[$parameter][$key] = $value;
                $valid = (is_int($value) || is_float($value)) && is_finite((float) $value)
                    && (float) $value !== (float) data_get($payload, 'header.fill_value')
                    && abs((float) $value) <= 1000000
                    && ($parameter === 'T2M' || $value >= 0)
                    && ($parameter !== 'RH2M' || $value <= 100);
                if (! $valid) {
                    $missingDays[] = $key;
                } else {
                    $sum += $value;
                }
            }
            $missing[$parameter] = $missingDays;
            $values[$parameter] = $missingDays === [] ? round($sum / $month->daysInMonth, 6) : null;
            $totals[$parameter] = $missingDays === [] ? round($sum, 6) : null;
        }
        $values['rainfall_total_mm'] = $totals['PRECTOTCORR'];
        $values['solar_total_kwh_m2'] = $totals['ALLSKY_SFC_SW_DWN'];
        $quality = ['expected_days' => $month->daysInMonth, 'missing_days' => $missing, 'partial_averages_used' => false];
        $provenance = [
            'source_temporal' => 'daily', 'community' => 'RE', 'time_standard' => 'LST', 'aggregation' => self::AGGREGATION,
            'means' => array_keys(self::UNITS), 'totals' => ['rainfall_total_mm' => 'sum(PRECTOTCORR daily × 1 day)', 'solar_total_kwh_m2' => 'sum(ALLSKY_SFC_SW_DWN daily × 1 day)'],
            'endpoint' => self::ENDPOINT, 'request' => $query, 'header' => $payload['header'], 'parameters' => $payload['parameters'],
            'geometry' => $payload['geometry'], 'messages' => $payload['messages'] ?? [],
            'coordinate_reference' => config('weather.coordinate_reference'), 'location_name' => config('weather.location_name'),
        ];
        $hash = DataFingerprint::hash([
            'values' => $values, 'raw' => $raw, 'quality' => $quality, 'units' => self::UNITS,
            'aggregation' => self::AGGREGATION, 'api' => data_get($payload, 'header.api'),
            'sources' => data_get($payload, 'header.sources'), 'fill_value' => data_get($payload, 'header.fill_value'),
        ]);

        return [
            'location_key' => $this->locationKey(), 'source' => 'NASA_POWER', 'month' => $month->toDateString(),
            'latitude' => config('weather.latitude'), 'longitude' => config('weather.longitude'),
            'weather_values' => $values, 'units' => [...self::UNITS, 'rainfall_total_mm' => 'mm', 'solar_total_kwh_m2' => 'kW-hr/m^2'],
            'quality' => $quality, 'is_complete' => ! in_array(null, $values, true), 'raw_values' => $raw,
            'source_metadata' => $provenance, 'content_hash' => $hash, 'fetched_at' => now(),
        ];
    }
}
