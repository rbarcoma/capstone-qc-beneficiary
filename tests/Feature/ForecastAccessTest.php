<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Forecast;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Services\ForecastIssuance;
use App\Services\PythonForecast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ForecastData;
use Tests\TestCase;

class ForecastAccessTest extends TestCase
{
    use ForecastData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 12:00:00', 'UTC'));
    }

    public function test_all_roles_can_view_their_workspace_but_only_managers_can_mutate(): void
    {
        $this->mock(PythonForecast::class)->shouldNotReceive('evaluate');
        foreach (['admin', 'energy_manager', 'viewer', 'budget_officer', 'facilities_personnel'] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->get('/forecasting')->assertOk();
            $this->get('/model-evaluation')->assertOk();
            if (! in_array($role, ['admin', 'energy_manager'], true)) {
                $this->post('/forecasting/issue', [])->assertForbidden();
                $this->post('/forecasting/reconcile', [])->assertForbidden();
            }
        }
        $this->assertDatabaseCount('forecasts', 0);
        $this->assertSame(6, AuditLog::where('event', 'authorization.denied')->count());
    }

    public function test_guests_cannot_view_or_issue_forecasts(): void
    {
        $this->get('/forecasting')->assertRedirect('/login');
        $this->get('/model-evaluation')->assertRedirect('/login');
        $this->post('/forecasting/issue', [])->assertRedirect('/login');
    }

    public function test_issue_endpoint_stores_a_run_and_projects_only_safe_current_evidence(): void
    {
        $f = $this->forecastFixture();
        $this->actingAs($f['user'])->from('/forecasting')->post('/forecasting/issue', ['building_id' => $f['building']->id, 'expected_fingerprint' => $f['dataset']->fingerprint])
            ->assertRedirect('/forecasting')->assertSessionHasNoErrors();
        $run = Forecast::firstOrFail();
        $response = $this->get('/forecasting');
        $response->assertInertia(fn ($page) => $page->component('forecasting')->where('data.current.id', $run->id)
            ->where('data.current.predictionKwh', 420)->where('data.current.targetMonth', '2026-09-01')
            ->where('data.canRun', true)->missing('data.current.worker_result')->missing('data.current.input_manifest'));
        $this->assertStringNotContainsString($f['meters'][0]->meralco_account_ref, $response->getContent());
        $this->get('/model-evaluation')->assertInertia(fn ($page) => $page->component('model-evaluation')
            ->where('data.evaluation.metrics.linear_regression.test_months', 3)->has('data.evaluation.evidence', 3));
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('kpis.nextForecast', '2026-09-01')->where('readiness.0.forecastReady', true));
    }

    public function test_request_cannot_override_horizon_method_or_reviewed_dataset(): void
    {
        $f = $this->forecastFixture();
        $this->mock(PythonForecast::class)->shouldNotReceive('evaluate');
        $this->actingAs($f['user']);
        $base = ['building_id' => $f['building']->id, 'expected_fingerprint' => $f['dataset']->fingerprint];
        foreach (['target_month' => '2030-01-01', 'horizon' => 12, 'model' => 'ridge', 'dataset_id' => $f['dataset']->id] as $key => $value) {
            $this->post('/forecasting/issue', [...$base, $key => $value])->assertSessionHasErrors($key);
        }
        $this->post('/forecasting/issue', [...$base, 'expected_fingerprint' => str_repeat('0', 64)])->assertSessionHasErrors('forecast');
        $this->assertDatabaseCount('forecasts', 0);
    }

    public function test_facilities_cannot_read_another_building_or_select_its_run(): void
    {
        $own = $this->forecastFixture();
        $other = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($other['building'], $other['user'])['forecast'];
        $actor = User::factory()->role('facilities_personnel')->create();
        $actor->buildings()->attach($own['building']->id);
        $this->actingAs($actor)->get('/forecasting')->assertInertia(fn ($page) => $page->has('buildings', 1)->has('data.history', 0)->where('data.canRun', false));
        $this->get('/forecasting?building='.$other['building']->code)->assertNotFound();
        $this->get('/model-evaluation?building='.$own['building']->code.'&run='.$run->id)->assertNotFound();
    }

    public function test_corrected_source_withholds_prediction_metrics_and_actual_numbers(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        MonthlyRecord::firstOrFail()->update(['verification_status' => 'draft', 'version' => 2]);
        $this->actingAs($f['user'])->get('/model-evaluation?run='.$run->id)->assertInertia(fn ($page) => $page
            ->where('data.current', null)->where('data.evaluation.sourceCurrent', false)->where('data.evaluation.predictionKwh', null)
            ->where('data.evaluation.metrics', null)->has('data.evaluation.evidence', 0)->where('data.evaluation.actual.kwh', null));
        $this->assertSame('420.000', $run->fresh()->predicted_kwh);
    }

    public function test_explicit_reconciliation_is_authorized_and_idempotent(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        MonthlyRecord::create(['meter_id' => $f['meters'][0]->id, 'month' => '2026-09-01', 'kwh' => 450, 'source' => 'manual', 'verification_status' => 'verified']);
        $this->actingAs($f['user'])->post('/forecasting/reconcile', ['building_id' => $f['building']->id])->assertSessionHasNoErrors();
        $this->post('/forecasting/reconcile', ['building_id' => $f['building']->id])->assertSessionHasNoErrors();
        $this->assertSame(2, AuditLog::where('event', 'forecast.actual_reconciled')->count());
        $this->get('/model-evaluation?run='.$run->id)->assertInertia(fn ($page) => $page->where('data.evaluation.actual.valid', true)->where('data.evaluation.actual.absoluteError', 30));
    }

    public function test_worker_failure_is_a_form_error_and_never_a_fabricated_forecast(): void
    {
        $f = $this->forecastFixture();
        config(['forecasting.python' => '/usr/bin/false']);
        $this->actingAs($f['user'])->post('/forecasting/issue', ['building_id' => $f['building']->id, 'expected_fingerprint' => $f['dataset']->fingerprint])->assertSessionHasErrors('forecast');
        $this->assertDatabaseCount('forecasts', 0);
        $this->assertDatabaseCount('forecast_results', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'forecast.failed']);
    }

    public function test_active_or_selected_runs_older_than_twenty_items_remain_accessible(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        $older = null;
        foreach (range(1, 24) as $number) {
            $copy = $run->replicate();
            $copy->issuance_key = hash('sha256', 'fixture-'.$number);
            $copy->engine_signature = hash('sha256', 'old-runtime-'.$number);
            $copy->save();
            $older ??= $copy->id;
        }
        $this->actingAs($f['user'])->get('/model-evaluation?run='.$older)->assertInertia(fn ($page) => $page
            ->where('data.current.id', $run->id)->where('data.evaluation.id', $older));
    }
}
