<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Dataset;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Models\WeatherRecord;
use App\Services\BuildingDataset;
use App\Services\DataFingerprint;
use App\Services\NasaPowerWeather;
use App\Services\PythonForecast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class PythonForecastContractTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Meter $meter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 12:00:00', 'UTC'));
        $this->manager = User::factory()->role('energy_manager')->create();
        $this->meter = Meter::factory()->create(['building_id' => Building::factory()->create()->id]);
    }

    private function dataset(): Dataset
    {
        $month = Carbon::parse('2025-05-01');
        for ($i = 0; $i < 16; $i++) {
            MonthlyRecord::create(['meter_id' => $this->meter->id, 'month' => $month->toDateString(), 'kwh' => 100 + 20 * $i, 'source' => 'manual', 'verification_status' => 'verified']);
            $values = ['T2M' => 25.0, 'RH2M' => 75.0, 'PRECTOTCORR' => 2.0, 'ALLSKY_SFC_SW_DWN' => 4.0];
            WeatherRecord::create([
                'location_key' => app(NasaPowerWeather::class)->locationKey(), 'source' => 'NASA_POWER', 'month' => $month->toDateString(),
                'latitude' => config('weather.latitude'), 'longitude' => config('weather.longitude'), 'weather_values' => $values,
                'units' => NasaPowerWeather::UNITS, 'quality' => ['fixture' => true], 'is_complete' => true, 'raw_values' => [],
                'source_metadata' => ['fixture' => true], 'content_hash' => DataFingerprint::hash($values), 'fetched_at' => now(),
            ]);
            $month->addMonth();
        }

        return app(BuildingDataset::class)->build($this->meter->building, $this->manager)['dataset'];
    }

    public function test_real_laravel_subprocess_contract_uses_current_dataset_and_returns_computed_evidence(): void
    {
        $dataset = $this->dataset();
        $result = app(PythonForecast::class)->evaluate($dataset, $this->manager);
        $this->assertTrue($result['eligible']);
        $this->assertSame('2026-09-01', $result['target_month']);
        $this->assertEqualsWithDelta(420.0, $result['prediction_kwh'], 0.000001);
        $this->assertSame($dataset->fingerprint, $result['dataset_fingerprint']);
        $this->assertSame(3, $result['metrics']['linear_regression']['test_months']);
        $this->assertCount(3, $result['evidence']);
        $this->assertSame('1.6.1', $result['runtime']['scikit_learn']);
        $this->assertDatabaseCount('datasets', 1);
        $this->assertDatabaseCount('monthly_records', 16);
    }

    public function test_latest_unverified_period_is_rejected_before_invoking_python(): void
    {
        $this->dataset();
        MonthlyRecord::create(['meter_id' => $this->meter->id, 'month' => '2026-09-01', 'kwh' => 999, 'source' => 'manual']);
        $current = app(BuildingDataset::class)->build($this->meter->building, $this->manager)['dataset'];
        config(['forecasting.python' => '/usr/bin/false']);
        $result = app(PythonForecast::class)->evaluate($current, $this->manager);
        $this->assertFalse($result['eligible']);
        $this->assertSame('latest_period_not_completely_verified', $result['reason']);
        $this->assertArrayNotHasKey('prediction_kwh', $result);
    }

    public function test_stale_inputs_require_reprocessing_before_worker_execution(): void
    {
        $dataset = $this->dataset();
        MonthlyRecord::firstOrFail()->update(['verification_status' => 'draft', 'version' => 2]);
        config(['forecasting.python' => '/usr/bin/false']);
        $this->expectException(ValidationException::class);
        app(PythonForecast::class)->evaluate($dataset, $this->manager);
    }

    public function test_viewer_cannot_run_the_worker_even_with_building_visibility(): void
    {
        $dataset = $this->dataset();
        $this->expectException(AuthorizationException::class);
        app(PythonForecast::class)->evaluate($dataset, User::factory()->role('viewer')->create());
    }

    public function test_worker_failure_does_not_issue_a_result_or_modify_source_data(): void
    {
        $dataset = $this->dataset();
        config(['forecasting.python' => '/usr/bin/false']);
        try {
            app(PythonForecast::class)->evaluate($dataset, $this->manager);
            $this->fail('A failed worker must not return a prediction.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('datasets', 1);
            $this->assertDatabaseCount('monthly_records', 16);
        }
    }
}
