<?php

namespace Tests\Feature;

use App\Models\WeatherRecord;
use App\Services\NasaPowerWeather;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NasaPowerWeatherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 12:00:00', 'UTC'));
        Http::preventStrayRequests();
    }

    /** @return array<string, mixed> */
    private function payload(string $month = '2025-01'): array
    {
        $date = Carbon::parse($month.'-01');
        $series = [];
        foreach (['T2M' => 25.0, 'RH2M' => 75.0, 'PRECTOTCORR' => 2.0, 'ALLSKY_SFC_SW_DWN' => 4.0] as $parameter => $value) {
            for ($day = 1; $day <= $date->daysInMonth; $day++) {
                $series[$parameter][$date->format('Ym').str_pad((string) $day, 2, '0', STR_PAD_LEFT)] = $value;
            }
        }

        return [
            'type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [121.05, 14.647, 201.16]],
            'properties' => ['parameter' => $series],
            'header' => ['api' => ['name' => 'POWER Daily API', 'version' => 'test-contract'], 'time_standard' => 'LST', 'fill_value' => -999.0, 'sources' => ['MERRA2', 'SYN1DEG'], 'start' => $date->format('Ymd'), 'end' => $date->copy()->endOfMonth()->format('Ymd')],
            'parameters' => array_map(fn ($unit) => ['units' => $unit], NasaPowerWeather::UNITS), 'messages' => [],
        ];
    }

    public function test_daily_data_is_aggregated_into_explicit_means_and_totals_with_provenance(): void
    {
        Http::fake(['power.larc.nasa.gov/*' => Http::response($this->payload())]);
        $result = app(NasaPowerWeather::class)->sync('2025-01', '2025-01');
        $this->assertSame(['created' => 1, 'updated' => 0, 'unchanged' => 0, 'incomplete' => 0], $result);
        $record = WeatherRecord::firstOrFail();
        $this->assertEquals(25.0, $record->weather_values['T2M']);
        $this->assertEquals(2.0, $record->weather_values['PRECTOTCORR']);
        $this->assertEquals(62.0, $record->weather_values['rainfall_total_mm']);
        $this->assertEquals(4.0, $record->weather_values['ALLSKY_SFC_SW_DWN']);
        $this->assertEquals(124.0, $record->weather_values['solar_total_kwh_m2']);
        $this->assertSame('mm/day', $record->units['PRECTOTCORR']);
        $this->assertSame('mm', $record->units['rainfall_total_mm']);
        $this->assertSame('LST', $record->source_metadata['time_standard']);
        $this->assertSame('test-contract', $record->source_metadata['header']['api']['version']);
        $this->assertSame(31, $record->quality['expected_days']);
        $this->assertCount(31, $record->raw_values['T2M']);
        Http::assertSent(fn ($request) => $request['community'] === 'RE' && $request['time-standard'] === 'LST' && $request['start'] === '20250101' && $request['end'] === '20250131');
    }

    public function test_fill_null_absent_and_invalid_days_never_become_zero_or_partial_averages(): void
    {
        $payload = $this->payload('2024-02');
        $payload['properties']['parameter']['ALLSKY_SFC_SW_DWN']['20240201'] = -999;
        $payload['properties']['parameter']['RH2M']['20240202'] = null;
        $payload['properties']['parameter']['RH2M']['20240203'] = 101;
        unset($payload['properties']['parameter']['PRECTOTCORR']['20240204']);
        $payload['properties']['parameter']['T2M']['20240205'] = -5;
        Http::fake(['*' => Http::response($payload)]);
        $result = app(NasaPowerWeather::class)->sync('2024-02', '2024-02');
        $record = WeatherRecord::firstOrFail();
        $this->assertSame(1, $result['incomplete']);
        $this->assertFalse($record->is_complete);
        $this->assertSame(29, $record->quality['expected_days']);
        $this->assertNull($record->weather_values['RH2M']);
        $this->assertNull($record->weather_values['rainfall_total_mm']);
        $this->assertNull($record->weather_values['ALLSKY_SFC_SW_DWN']);
        $this->assertNotNull($record->weather_values['T2M']);
        $this->assertCount(2, $record->quality['missing_days']['RH2M']);
        $this->assertSame(-999, $record->raw_values['ALLSKY_SFC_SW_DWN']['20240201']);
    }

    public function test_a_zero_rainfall_month_is_valid(): void
    {
        $payload = $this->payload();
        $payload['properties']['parameter']['PRECTOTCORR'] = array_fill_keys(array_keys($payload['properties']['parameter']['T2M']), 0);
        Http::fake(['*' => Http::response($payload)]);
        app(NasaPowerWeather::class)->sync('2025-01', '2025-01');
        $record = WeatherRecord::firstOrFail();
        $this->assertTrue($record->is_complete);
        $this->assertEquals(0.0, $record->weather_values['rainfall_total_mm']);
    }

    public function test_repeated_sync_is_idempotent_and_updated_source_values_are_detected(): void
    {
        $payload = $this->payload();
        Http::fake(function () use (&$payload) {
            return Http::response($payload);
        });
        $weather = app(NasaPowerWeather::class);
        $weather->sync('2025-01', '2025-01');
        $id = WeatherRecord::firstOrFail()->id;
        $hash = WeatherRecord::firstOrFail()->content_hash;
        $this->assertSame(1, $weather->sync('2025-01', '2025-01')['unchanged']);
        $this->assertDatabaseCount('weather_records', 1);
        $this->assertSame($hash, WeatherRecord::firstOrFail()->content_hash);
        $payload['properties']['parameter']['T2M']['20250101'] = 26;
        $this->assertSame(1, $weather->sync('2025-01', '2025-01')['updated']);
        $this->assertSame($id, WeatherRecord::firstOrFail()->id);
        $this->assertNotSame($hash, WeatherRecord::firstOrFail()->content_hash);
    }

    public function test_unexpected_units_or_metadata_abort_without_changing_existing_weather(): void
    {
        $payload = $this->payload();
        Http::fake(function () use (&$payload) {
            return Http::response($payload);
        });
        app(NasaPowerWeather::class)->sync('2025-01', '2025-01');
        $hash = WeatherRecord::firstOrFail()->content_hash;
        foreach (['units', 'coordinates', 'time', 'fill'] as $failure) {
            $payload = $this->payload();
            if ($failure === 'units') {
                $payload['parameters']['T2M']['units'] = 'K';
            }
            if ($failure === 'coordinates') {
                $payload['geometry']['coordinates'][0] = 0;
            }
            if ($failure === 'time') {
                $payload['header']['time_standard'] = 'UTC';
            }
            if ($failure === 'fill') {
                $payload['header']['fill_value'] = 0;
            }
            try {
                app(NasaPowerWeather::class)->sync('2025-01', '2025-01');
                $this->fail('Invalid metadata was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('weather', $exception->errors());
            }
            $this->assertSame($hash, WeatherRecord::firstOrFail()->content_hash);
        }
    }

    public function test_future_current_invalid_and_excessive_ranges_do_not_call_nasa(): void
    {
        foreach ([['2026-11', '2026-11'], ['2025-02', '2025-01'], ['bad', '2025-01'], ['2020-01', '2025-01']] as [$start, $end]) {
            try {
                app(NasaPowerWeather::class)->sync($start, $end);
                $this->fail('Invalid range was accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('weather_records', 0);
            }
        }
        Http::assertNothingSent();
    }

    public function test_a_later_request_failure_rolls_back_the_whole_range(): void
    {
        Http::fake(['*' => Http::sequence()->push($this->payload('2025-12'))->pushStatus(503)->pushStatus(503)]);
        try {
            app(NasaPowerWeather::class)->sync('2025-12', '2026-01');
            $this->fail('The upstream failure was ignored.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('weather_records', 0);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_command_uses_the_same_validation_and_aggregation(): void
    {
        Http::fake(['*' => Http::response($this->payload())]);
        $this->artisan('weather:sync', ['start' => '2025-01'])->assertSuccessful();
        $this->assertDatabaseCount('weather_records', 1);
        $this->artisan('weather:sync', ['start' => 'invalid'])->assertFailed();
    }
}
