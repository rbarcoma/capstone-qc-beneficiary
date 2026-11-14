<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ForecastError;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Services\BuildingDataset;
use App\Services\ForecastActuals;
use App\Services\ForecastIssuance;
use App\Services\ForecastRead;
use App\Services\PythonForecast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\ForecastData;
use Tests\TestCase;

class ForecastIssuanceTest extends TestCase
{
    use ForecastData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 12:00:00', 'UTC'));
    }

    public function test_real_worker_result_is_issued_once_with_versions_and_same_period_evidence(): void
    {
        $f = $this->forecastFixture();
        $result = app(ForecastIssuance::class)->issue($f['building'], $f['user']);
        $run = $result['forecast'];
        $this->assertTrue($result['created']);
        $this->assertSame('2026-09-01', $run->target_month->toDateString());
        $this->assertSame('420.000', $run->predicted_kwh);
        $this->assertSame($f['dataset']->fingerprint, $run->dataset_fingerprint);
        $this->assertCount(3, $run->results);
        $this->assertDatabaseHas('audit_logs', ['forecast_id' => $run->id, 'event' => 'forecast.issued']);
        $this->assertDatabaseHas('forecast_errors', ['forecast_id' => $run->id, 'valid' => false, 'reason' => 'awaiting_complete_verified_actual']);
        $this->mock(PythonForecast::class)->shouldNotReceive('evaluate');
        $cached = app(ForecastIssuance::class)->issue($f['building'], $f['user']);
        $this->assertFalse($cached['created']);
        $this->assertSame($run->id, $cached['forecast']->id);
        $this->assertDatabaseCount('forecasts', 1);
        $this->assertDatabaseCount('forecast_results', 3);
        $this->assertSame(1, AuditLog::where('event', 'forecast.issued')->count());
    }

    public function test_source_change_during_worker_execution_prevents_publication(): void
    {
        $f = $this->forecastFixture();
        $real = new PythonForecast;
        $this->mock(PythonForecast::class)->shouldReceive('evaluate')->once()->andReturnUsing(function ($dataset, $user) use ($real) {
            $result = $real->evaluate($dataset, $user);
            MonthlyRecord::firstOrFail()->update(['verification_status' => 'draft', 'version' => 2]);

            return $result;
        });
        try {
            app(ForecastIssuance::class)->issue($f['building'], $f['user']);
            $this->fail('A superseded source was published.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('forecasts', 0);
            $this->assertDatabaseCount('forecast_results', 0);
            $this->assertDatabaseCount('forecast_errors', 0);
            $this->assertSame(0, AuditLog::where('event', 'forecast.issued')->count());
        }
    }

    public function test_runtime_change_during_execution_prevents_publication(): void
    {
        $f = $this->forecastFixture();
        $real = new PythonForecast;
        $this->mock(PythonForecast::class)->shouldReceive('evaluate')->once()->andReturnUsing(function ($dataset, $user) use ($real) {
            $result = $real->evaluate($dataset, $user);
            config(['forecasting.python' => '/usr/bin/false']);

            return $result;
        });
        $this->expectException(ValidationException::class);
        app(ForecastIssuance::class)->issue($f['building'], $f['user']);
    }

    public function test_revoked_run_permission_during_execution_prevents_publication(): void
    {
        $f = $this->forecastFixture();
        $real = new PythonForecast;
        $this->mock(PythonForecast::class)->shouldReceive('evaluate')->once()->andReturnUsing(function ($dataset, $user) use ($real) {
            $result = $real->evaluate($dataset, $user);
            User::whereKey($user->id)->update(['role' => 'viewer']);

            return $result;
        });
        try {
            app(ForecastIssuance::class)->issue($f['building'], $f['user']);
            $this->fail('A revoked actor published a forecast.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('forecasts', 0);
        }
    }

    public function test_forged_target_metrics_evidence_or_probability_are_rejected(): void
    {
        $f = $this->forecastFixture();
        $valid = (new PythonForecast)->evaluate($f['dataset'], $f['user']);
        foreach (['target', 'metrics', 'actual', 'probability', 'nan'] as $case) {
            $result = $valid;
            if ($case === 'target') {
                $result['target_month'] = '2030-01-01';
            }
            if ($case === 'metrics') {
                $result['metrics']['linear_regression']['rmse'] = 9999;
            }
            if ($case === 'actual') {
                $result['evidence'][0]['actual_kwh'] += 1;
            }
            if ($case === 'probability') {
                $result['error_band']['calibrated_confidence_level'] = 0.95;
            }
            if ($case === 'nan') {
                $result['prediction_kwh'] = NAN;
            }
            $this->mock(PythonForecast::class)->shouldReceive('evaluate')->once()->andReturn($result);
            try {
                app(ForecastIssuance::class)->issue($f['building'], $f['user']);
                $this->fail('A forged response was published.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('forecasts', 0);
                $this->assertDatabaseCount('forecast_results', 0);
            }
        }
    }

    public function test_insufficient_history_and_non_manager_roles_never_invoke_worker(): void
    {
        $f = $this->forecastFixture(8);
        $this->mock(PythonForecast::class)->shouldNotReceive('evaluate');
        foreach (['viewer', 'budget_officer', 'facilities_personnel'] as $role) {
            $actor = User::factory()->role($role)->create();
            $actor->buildings()->attach($f['building']->id);
            try {
                app(ForecastIssuance::class)->issue($f['building'], $actor);
                $this->fail('A viewer issued a run.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('forecasts', 0);
            }
        }
        try {
            app(ForecastIssuance::class)->issue($f['building'], $f['user']);
            $this->fail('Insufficient history was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('forecasts', 0);
        }
    }

    public function test_newer_unverified_period_is_not_forecast_over(): void
    {
        $f = $this->forecastFixture();
        MonthlyRecord::create(['meter_id' => $f['meters'][0]->id, 'month' => '2026-09-01', 'kwh' => 450, 'source' => 'manual']);
        app(BuildingDataset::class)->build($f['building'], $f['user']);
        $this->mock(PythonForecast::class)->shouldNotReceive('evaluate');
        $this->expectException(ValidationException::class);
        app(ForecastIssuance::class)->issue($f['building'], $f['user']);
    }

    public function test_verified_actual_is_measured_then_retracted_and_remeasured_without_losing_history(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        $uploader = User::factory()->role('facilities_personnel')->create();
        $actual = MonthlyRecord::create(['meter_id' => $f['meters'][0]->id, 'month' => '2026-09-01', 'kwh' => 450,
            'source' => 'manual', 'uploaded_by' => $uploader->id, 'last_edited_by' => $uploader->id])->refresh();
        $this->actingAs($f['user'])->post("/monthly-records/{$actual->id}/verify", ['version' => 1, 'confirmed_against_bill' => true])->assertSessionHasNoErrors();
        $error = ForecastError::where('forecast_id', $run->id)->firstOrFail();
        $this->assertTrue($error->valid);
        $this->assertEquals(30, $error->absolute_error);
        $this->assertEqualsWithDelta(30 / 450 * 100, $error->percentage_error, 0.00001);
        $read = app(ForecastRead::class)->read($f['building'], $f['user']);
        $this->assertNull($read['current']);
        $this->assertTrue($read['evaluation']['sourceCurrent']);
        $this->assertTrue($read['evaluation']['actual']['valid']);
        $this->patch("/monthly-records/{$actual->id}/correct", ['version' => 2, 'kwh' => '460', 'reason' => 'Correct target bill'])->assertSessionHasNoErrors();
        $this->assertFalse($error->fresh()->valid);
        $this->assertSame('450.000', $error->fresh()->actual_kwh);
        $this->assertNull(app(ForecastRead::class)->read($f['building'], $f['user'])['evaluation']['actual']['kwh']);
        $this->actingAs(User::factory()->admin()->create())->post("/monthly-records/{$actual->id}/verify", ['version' => 3, 'confirmed_against_bill' => true])->assertSessionHasNoErrors();
        $this->assertTrue($error->fresh()->valid);
        $this->assertSame('460.000', $error->fresh()->actual_kwh);
        $this->assertEquals(40, $error->fresh()->absolute_error);
        $this->assertSame(4, AuditLog::where('event', 'forecast.actual_reconciled')->count());
        $this->assertSame('420.000', $run->fresh()->predicted_kwh);
    }

    public function test_training_correction_suppresses_run_and_retracts_actual_error(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        $record = MonthlyRecord::orderBy('month')->firstOrFail();
        $this->actingAs($f['user'])->patch("/monthly-records/{$record->id}/correct", ['version' => 1, 'kwh' => '110', 'reason' => 'Correct old bill'])->assertSessionHasNoErrors();
        $read = app(ForecastRead::class)->read($f['building'], $f['user']);
        $this->assertNull($read['current']);
        $this->assertSame('superseded_source', $read['history'][0]['state']);
        $this->assertNull($read['history'][0]['predictionKwh']);
        $this->assertNull($read['history'][0]['metrics']);
        $this->assertDatabaseHas('forecast_errors', ['forecast_id' => $run->id, 'valid' => false, 'reason' => 'training_source_changed']);
        $this->assertDatabaseCount('forecasts', 1);
        $this->assertDatabaseCount('forecast_results', 3);
    }

    public function test_partial_multi_meter_actuals_are_not_counted_and_zero_actual_has_no_percentage(): void
    {
        $f = $this->forecastFixture(multi: true);
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        MonthlyRecord::create(['meter_id' => $f['meters'][0]->id, 'month' => '2026-09-01', 'kwh' => 0, 'source' => 'manual', 'verification_status' => 'verified']);
        app(ForecastActuals::class)->reconcile($f['building'], $f['user']);
        $this->assertFalse($run->actualError->fresh()->valid);
        MonthlyRecord::create(['meter_id' => $f['meters'][1]->id, 'month' => '2026-09-01', 'kwh' => 0, 'source' => 'manual', 'verification_status' => 'verified']);
        app(ForecastActuals::class)->reconcile($f['building'], $f['user']);
        $error = $run->actualError->fresh();
        $this->assertTrue($error->valid);
        $this->assertSame('0.000', $error->actual_kwh);
        $this->assertNull($error->percentage_error);
        $this->assertEquals(840, $error->absolute_error);
    }
}
