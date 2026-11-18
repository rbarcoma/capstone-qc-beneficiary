<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Services\ForecastIssuance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ForecastData;
use Tests\TestCase;

class SolarScenarioTest extends TestCase
{
    use ForecastData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 12:00:00', 'UTC'));
    }

    public function test_assumptions_calculate_from_current_run_without_changing_official_data(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        // Compare persisted rows on both sides. The newly created model still
        // holds Python's full-precision floats and pre-MySQL JSON formatting;
        // DECIMAL storage/JSON canonicalization happen at insertion, not here.
        $before = $run->fresh()->getRawOriginal();
        $this->actingAs($f['user'])->from('/solar-scenarios')->post('/solar-scenarios/calculate', [
            'building_id' => $f['building']->id, 'expected_forecast_id' => $run->id, 'reduction_percent' => 10, 'capacity_kwp' => 2.5, 'performance_ratio' => 0.8,
        ])->assertSessionHasNoErrors()->assertSessionHas('scenarioResult.scenarioKwh', 378)
            ->assertSessionHas('scenarioResult.solar.generationKwh', 240)
            ->assertSessionHas('scenarioResult', fn ($result) => array_key_exists('costDifferencePhp', $result) && $result['costDifferencePhp'] === null);
        $this->get('/solar-scenarios')->assertInertia(fn ($page) => $page->where('scenarioResult.forecastId', $run->id)->where('scenarioResult.climateProxyMonth', '2026-08-01')->where('scenarioResult.solar.targetCalendarDays', 30));
        $this->assertSame($before, $run->fresh()->getRawOriginal());
        $this->assertDatabaseCount('forecasts', 1);
        $this->assertDatabaseCount('monthly_records', 16);
        $this->assertDatabaseCount('datasets', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'scenario.calculated', 'forecast_id' => $run->id]);
    }

    public function test_every_view_role_can_calculate_for_accessible_forecast_without_run_permission(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        foreach (['admin', 'energy_manager', 'viewer', 'budget_officer', 'facilities_personnel'] as $role) {
            $user = User::factory()->role($role)->create();
            $user->buildings()->attach($f['building']->id);
            $this->actingAs($user)->get('/solar-scenarios')->assertOk();
            $this->post('/solar-scenarios/calculate', ['building_id' => $f['building']->id, 'expected_forecast_id' => $run->id, 'reduction_percent' => 5])->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('forecasts', 1);
    }

    public function test_documented_installation_estimate_uses_decimal_capacity_and_one_rate_and_is_audited(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        $base = ['building_id' => $f['building']->id, 'expected_forecast_id' => $run->id, 'reduction_percent' => 0, 'capacity_kwp' => '2.5', 'performance_ratio' => 0.8];
        $assumptions = ['rate' => '100.002', 'reference' => 'Fictional test quotation', 'date' => '2026-11-01', 'inclusions' => 'Panels and labour', 'exclusions' => 'Roof reinforcement'];
        foreach (['admin', 'energy_manager', 'budget_officer', 'viewer'] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->post('/solar-scenarios/calculate', [...$base, 'installation' => $assumptions])
                ->assertSessionHasNoErrors()->assertSessionHas('scenarioResult.installation.totalPhp', '250.01')
                ->assertSessionHas('scenarioResult.solar.generationKwh', 240);
            $this->get('/solar-scenarios')->assertInertia(fn ($page) => $page->where('canViewInstallationCost', true)
                ->where('scenarioResult.installation.reference', $assumptions['reference'])->where('scenarioResult.costDifferencePhp', null));
        }
        $audit = AuditLog::where('event', 'scenario.calculated')->latest('id')->firstOrFail();
        $this->assertEquals([...$assumptions, 'capacityKwp' => '2.5', 'totalPhp' => '250.01'], $audit->after['assumptions']['installation']);
        $this->post('/solar-scenarios/calculate', $base)->assertSessionHasNoErrors()->assertSessionMissing('scenarioResult.installation')->assertSessionHas('scenarioResult.solar.generationKwh', 240);
    }

    public function test_installation_details_must_be_complete_and_are_denied_to_facilities_on_write_and_read(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        $base = ['building_id' => $f['building']->id, 'expected_forecast_id' => $run->id, 'reduction_percent' => 0, 'capacity_kwp' => '2.5', 'performance_ratio' => 0.8];
        $this->actingAs($f['user'])->post('/solar-scenarios/calculate', [...$base, 'installation' => ['rate' => '-1']])->assertSessionHasErrors([
            'installation.rate', 'installation.reference', 'installation.date', 'installation.inclusions', 'installation.exclusions',
        ]);
        $this->post('/solar-scenarios/calculate', [...$base, 'capacity_kwp' => null, 'performance_ratio' => null, 'installation' => ['date' => '2026-02-30']])->assertSessionHasErrors(['capacity_kwp', 'installation.rate', 'installation.date']);
        $facility = User::factory()->role('facilities_personnel')->create();
        $facility->buildings()->attach($f['building']);
        $this->actingAs($facility)->post('/solar-scenarios/calculate', [...$base, 'installation' => ['rate' => '1']])->assertForbidden();
        $this->withSession(['scenarioResult' => ['forecastId' => $run->id, 'installation' => ['reference' => 'Private test source']]])
            ->get('/solar-scenarios')->assertInertia(fn ($page) => $page->where('canViewInstallationCost', false)->missing('scenarioResult.installation'));
        $this->assertDatabaseMissing('audit_logs', ['event' => 'scenario.calculated']);
    }

    public function test_unauthorized_building_and_guests_cannot_calculate(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        $this->get('/solar-scenarios')->assertRedirect('/login');
        $this->post('/solar-scenarios/calculate', [])->assertRedirect('/login');
        $this->actingAs(User::factory()->role('facilities_personnel')->create())->post('/solar-scenarios/calculate', ['building_id' => $f['building']->id, 'expected_forecast_id' => $run->id, 'reduction_percent' => 5])->assertForbidden();
        $this->get('/solar-scenarios?building='.$f['building']->code)->assertNotFound();
    }

    public function test_stale_run_and_stale_flash_result_are_not_used(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        MonthlyRecord::firstOrFail()->update(['verification_status' => 'draft', 'version' => 2]);
        $this->actingAs($f['user'])->withSession(['scenarioResult' => ['forecastId' => $run->id, 'scenarioKwh' => 999]])
            ->get('/solar-scenarios')->assertInertia(fn ($page) => $page->where('currentForecast', null)->where('scenarioResult', null));
        $this->post('/solar-scenarios/calculate', ['building_id' => $f['building']->id, 'expected_forecast_id' => $run->id, 'reduction_percent' => 5])->assertSessionHasErrors('scenario');
    }

    public function test_tariff_target_weather_override_and_invalid_assumptions_are_rejected(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        $base = ['building_id' => $f['building']->id, 'expected_forecast_id' => $run->id, 'reduction_percent' => 10];
        $this->actingAs($f['user']);
        foreach (['tariff_php_per_kwh' => 9.5, 'target_month' => '2030-01-01', 'solar_irradiance' => 9, 'reduction_percent' => 101, 'capacity_kwp' => 2] as $field => $value) {
            $this->post('/solar-scenarios/calculate', [...$base, $field => $value])->assertSessionHasErrors($field === 'capacity_kwp' ? 'performance_ratio' : $field);
        }
        $this->post('/solar-scenarios/calculate', [...$base, 'expected_forecast_id' => $run->id + 100])->assertSessionHasErrors('scenario');
        $this->assertDatabaseCount('forecasts', 1);
    }
}
