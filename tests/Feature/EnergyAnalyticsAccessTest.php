<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Dataset;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Services\BuildingDataset;
use App\Services\NasaPowerWeather;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class EnergyAnalyticsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 12:00:00', 'UTC'));
        Http::preventStrayRequests();
    }

    private function meter(string $code, ?float $area = 100): Meter
    {
        return Meter::factory()->create(['building_id' => Building::factory()->create(['code' => $code, 'name' => $code, 'floor_area_m2' => $area])->id, 'meralco_account_ref' => 'PRIVATE-'.$code.'-4321']);
    }

    private function record(Meter $meter, string $month, string $kwh, ?string $cost = '98765.43'): MonthlyRecord
    {
        return MonthlyRecord::create(['meter_id' => $meter->id, 'month' => $month.'-01', 'kwh' => $kwh, 'cost_php' => $cost, 'source' => 'manual', 'verification_status' => 'verified'])->refresh();
    }

    public function test_all_roles_can_view_authorized_analytics_and_comparison_but_only_managers_preprocess(): void
    {
        foreach (['admin', 'energy_manager', 'facilities_personnel', 'budget_officer', 'viewer'] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->get('/energy-analytics')->assertOk();
            $this->get('/building-comparison')->assertOk();
            $response = $this->get('/preprocessing');
            in_array($role, ['admin', 'energy_manager'], true) ? $response->assertOk() : $response->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_guest_requests_redirect_to_login(): void
    {
        foreach (['/preprocessing', '/energy-analytics', '/building-comparison'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        $this->post('/preprocessing/build', [])->assertRedirect('/login');
        $this->post('/preprocessing/weather', [])->assertRedirect('/login');
    }

    public function test_build_action_reuses_versions_and_lineage_is_reviewer_only(): void
    {
        $meter = $this->meter('HIGHRISE');
        $record = $this->record($meter, '2026-10', '100');
        $this->actingAs(User::factory()->admin()->create());
        $this->post('/preprocessing/build', ['building_id' => $meter->building_id])->assertSessionHasNoErrors();
        $this->post('/preprocessing/build', ['building_id' => $meter->building_id])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('datasets', 1);
        $dataset = Dataset::firstOrFail();
        $this->get('/preprocessing?building=HIGHRISE')->assertInertia(fn ($page) => $page
            ->where('currentDataset.id', $dataset->id)->where('currentDataset.version', 1));
        $this->getJson("/datasets/{$dataset->id}/lineage")->assertOk()->assertJsonPath('dataset.input_manifest.verified_records.0.id', $record->id)->assertJsonCount(1, 'records.data');
        foreach (['facilities_personnel', 'budget_officer', 'viewer'] as $role) {
            $this->actingAs(User::factory()->role($role)->create());
            $this->post('/preprocessing/build', ['building_id' => $meter->building_id])->assertForbidden();
            $this->post('/preprocessing/weather', ['start' => '2025-01', 'end' => '2025-01'])->assertForbidden();
            $this->getJson("/datasets/{$dataset->id}/lineage")->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_weather_http_action_is_explicit_and_passes_the_authenticated_actor(): void
    {
        $admin = User::factory()->admin()->create();
        $this->mock(NasaPowerWeather::class, function ($mock) use ($admin) {
            $mock->shouldReceive('sync')->once()->with('2025-01', '2025-01', Mockery::on(fn ($actor) => $actor->id === $admin->id))
                ->andReturn(['created' => 1, 'updated' => 0, 'unchanged' => 0, 'incomplete' => 0]);
        });
        $this->actingAs($admin)->post('/preprocessing/weather', ['start' => '2025-01', 'end' => '2025-01'])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('operationResult');
    }

    public function test_facilities_analytics_and_comparison_do_not_expose_unassigned_buildings_or_costs(): void
    {
        $own = $this->meter('HIGHRISE');
        $other = $this->meter('TREASURY');
        $this->record($own, '2026-10', '100');
        $this->record($other, '2026-10', '500');
        $manager = User::factory()->role('energy_manager')->create();
        app(BuildingDataset::class)->build($own->building, $manager);
        app(BuildingDataset::class)->build($other->building, $manager);
        $facilities = User::factory()->role('facilities_personnel')->create();
        $facilities->buildings()->attach($own->building_id);
        $response = $this->actingAs($facilities)->get('/energy-analytics');
        $response->assertInertia(fn ($page) => $page->has('buildings', 1)->has('analytics.rows', 1)
            ->where('analytics.rows.0.kwh', 100)->where('analytics.rows.0.costPhp', null)->where('analytics.canViewCost', false)
            ->missing('analytics.dataset.input_manifest'));
        $this->assertStringNotContainsString('98765.43', $response->getContent());
        $this->assertStringNotContainsString('PRIVATE-', $response->getContent());
        $this->get('/energy-analytics?building=TREASURY')->assertNotFound();
        $this->get('/building-comparison')->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.name', 'HIGHRISE')->where('rows.0.costPhp', null));
    }

    public function test_comparisons_use_a_common_month_and_do_not_rank_unknown_cost_or_intensity(): void
    {
        $first = $this->meter('HIGHRISE');
        $second = $this->meter('TREASURY', null);
        $this->record($first, '2026-09', '100');
        $this->record($first, '2026-10', '1000');
        $this->record($second, '2026-09', '200', null);
        $manager = User::factory()->role('energy_manager')->create();
        app(BuildingDataset::class)->build($first->building, $manager);
        app(BuildingDataset::class)->build($second->building, $manager);
        $this->actingAs(User::factory()->role('viewer')->create())->get('/building-comparison')
            ->assertInertia(fn ($page) => $page->where('selectedPeriod', '2026-09-01')->where('portfolioKwh', 300)
                ->where('rows.0.kwh', 100)->where('rows.0.kwhRank', 2)->where('rows.1.kwhRank', 1)
                ->where('rows.1.costPhpRank', null)->where('rows.1.kwhPerM2Rank', null));
        $this->get('/building-comparison?period=2026-10-01')->assertNotFound();
    }

    public function test_no_portfolio_sum_is_shown_when_one_building_is_stale_or_missing(): void
    {
        $first = $this->meter('HIGHRISE');
        $second = $this->meter('TREASURY');
        $this->record($first, '2026-10', '100');
        $other = $this->record($second, '2026-10', '200');
        $manager = User::factory()->role('energy_manager')->create();
        app(BuildingDataset::class)->build($first->building, $manager);
        app(BuildingDataset::class)->build($second->building, $manager);
        $other->update(['verification_status' => 'draft', 'version' => 2]);
        $this->actingAs($manager)->get('/building-comparison')->assertInertia(fn ($page) => $page
            ->where('portfolioKwh', null)->has('periods', 0)->where('rows.0.kwh', null)->where('rows.1.stale', true));
    }

    public function test_dashboard_and_registry_use_complete_building_periods_and_hide_stale_trends(): void
    {
        $meter = $this->meter('HIGHRISE');
        $second = Meter::factory()->create(['building_id' => $meter->building_id]);
        $this->record($meter, '2026-10', '100');
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($page) => $page->where('kpis.verifiedMonths', 0)->where('readiness.0.verifiedCoverage', 0)->has('trend', 0));
        $this->get('/buildings-meters')->assertInertia(fn ($page) => $page->where('buildings.0.verifiedMonths', 0));
        $other = $this->record($second, '2026-10', '200');
        app(BuildingDataset::class)->build($meter->building, $admin);
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('kpis.verifiedMonths', 1)->has('trend', 1)->where('trend.0.kwh', 300));
        $other->update(['verification_status' => 'draft', 'version' => 2]);
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('kpis.verifiedMonths', 0)->has('trend', 0));
    }
}
