<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Dataset;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Models\WeatherRecord;
use App\Services\BuildingAnalytics;
use App\Services\BuildingDataset;
use App\Services\DataFingerprint;
use App\Services\NasaPowerWeather;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BuildingDatasetTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Meter $meter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 12:00:00', 'UTC'));
        $this->manager = User::factory()->role('energy_manager')->create();
        $this->meter = Meter::factory()->create(['building_id' => Building::factory()->create(['floor_area_m2' => 100])->id]);
    }

    private function record(string $month, string $kwh, array $extra = [], ?Meter $meter = null): MonthlyRecord
    {
        return MonthlyRecord::create([
            'meter_id' => ($meter ?? $this->meter)->id, 'month' => $month.'-01', 'kwh' => $kwh,
            'cost_php' => '100', 'billed_demand_kw' => '10', 'source' => 'manual',
            'verification_status' => 'verified', 'verified_by' => $this->manager->id, 'verified_at' => now(),
            ...$extra,
        ])->refresh();
    }

    private function weather(string $month, float $temperature = 25): WeatherRecord
    {
        $values = ['T2M' => $temperature, 'RH2M' => 75, 'PRECTOTCORR' => 2, 'ALLSKY_SFC_SW_DWN' => 4];

        return WeatherRecord::create([
            'location_key' => app(NasaPowerWeather::class)->locationKey(), 'source' => 'NASA_POWER', 'month' => $month.'-01',
            'latitude' => config('weather.latitude'), 'longitude' => config('weather.longitude'), 'weather_values' => $values,
            'units' => NasaPowerWeather::UNITS, 'quality' => ['test_fixture' => true], 'is_complete' => true, 'raw_values' => [],
            'source_metadata' => ['fixture' => true, 'api_version' => 'test-contract', 'time_standard' => 'LST'], 'fetched_at' => now(),
            'content_hash' => DataFingerprint::hash($values),
        ]);
    }

    private function build(?string $through = null): Dataset
    {
        return app(BuildingDataset::class)->build($this->meter->building, $this->manager, $through)['dataset'];
    }

    public function test_draft_values_are_excluded_and_identical_verified_inputs_reuse_a_version(): void
    {
        $verified = $this->record('2026-09', '100');
        $draft = $this->record('2026-10', '99999.999', ['verification_status' => 'draft']);
        $first = $this->build();
        $this->assertSame(1, $first->rows_count);
        $this->assertSame('100.000', $first->records()->firstOrFail()->kwh);
        $this->assertSame([$verified->id], array_column($first->input_manifest['verified_records'], 'id'));
        $draft->update(['kwh' => '1', 'version' => 2]);
        $this->assertSame($first->id, $this->build()->id);
        $this->assertDatabaseCount('datasets', 1);
        $this->assertDatabaseCount('processed_records', 1);
    }

    public function test_no_dataset_is_created_without_complete_verified_months(): void
    {
        $this->record('2026-10', '100', ['verification_status' => 'draft']);
        try {
            $this->build();
            $this->fail('Draft-only data was processed.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('datasets', 0);
            $this->assertDatabaseCount('processed_records', 0);
        }
    }

    public function test_multi_meter_totals_require_all_registered_meters_and_never_invent_coincident_peak(): void
    {
        $second = Meter::factory()->create(['building_id' => $this->meter->building_id, 'status' => 'inactive']);
        $this->record('2026-09', '100');
        $this->record('2026-09', '200', ['verification_status' => 'draft'], $second);
        $this->record('2026-10', '100.125');
        $this->record('2026-10', '200.25', ['cost_php' => null], $second);
        $dataset = $this->build();
        $row = $dataset->records()->firstOrFail();
        $this->assertSame(1, $dataset->rows_count);
        $this->assertSame('2026-10-01', $row->month->toDateString());
        $this->assertSame('300.375', $row->kwh);
        $this->assertNull($row->cost_php);
        $this->assertNull($row->metrics['billed_demand_kw']);
        $this->assertNull($row->metrics['load_factor']);
        $this->assertFalse($row->metrics['cost_complete']);
        $this->assertEquals(3.00375, $row->metrics['kwh_per_m2']);
        $this->assertCount(2, $row->source_snapshot);
    }

    public function test_calendar_lags_do_not_bridge_a_missing_month(): void
    {
        $this->record('2026-01', '10');
        $this->record('2026-02', '20');
        $this->record('2026-04', '40');
        $dataset = $this->build();
        $april = $dataset->records()->where('month', '2026-04-01')->firstOrFail();
        $this->assertNull($april->features['lag_1_kwh']);
        $this->assertEquals(20, $april->features['lag_2_kwh']);
        $this->assertNull($april->features['previous_3_month_average_kwh']);
        $this->assertTrue(collect($dataset->issues)->contains(fn ($issue) => $issue['month'] === '2026-03-01' && $issue['kind'] === 'billing'));
    }

    public function test_features_use_only_past_values_and_cutoff_excludes_future_records(): void
    {
        foreach (['01' => 10, '02' => 20, '03' => 30, '04' => 40] as $month => $kwh) {
            $this->record('2026-'.$month, (string) $kwh);
            $this->weather('2026-'.$month, 20 + (int) $month);
        }
        $first = $this->build('2026-04-01');
        $features = $first->records()->where('month', '2026-04-01')->firstOrFail()->features;
        $this->assertEquals(30, $features['lag_1_kwh']);
        $this->assertEquals(20, $features['lag_2_kwh']);
        $this->assertEquals(20, $features['previous_3_month_average_kwh']);
        $this->assertEquals(10, $features['prior_trend_kwh']);
        $this->assertEquals(23, $features['weather_lag_1']['T2M']);
        $this->record('2026-05', '99999');
        $this->weather('2026-05', 55);
        $this->assertSame($first->id, $this->build('2026-04-01')->id);
        $this->assertSame($features, $first->records()->where('month', '2026-04-01')->firstOrFail()->features);
    }

    public function test_source_correction_invalidates_current_analytics_without_destroying_old_versions(): void
    {
        $this->record('2026-09', '100');
        $october = $this->record('2026-10', '200');
        $first = $this->build();
        $this->assertCount(2, app(BuildingAnalytics::class)->read($this->meter->building, $this->manager)['rows']);
        $this->actingAs($this->manager)->patch("/monthly-records/{$october->id}/correct", ['version' => 1, 'kwh' => '210', 'reason' => 'Recorded bill review'])->assertSessionHasNoErrors();
        $stale = app(BuildingAnalytics::class)->read($this->meter->building, $this->manager);
        $this->assertTrue($stale['stale']);
        $this->assertSame([], $stale['rows']);
        $second = $this->build();
        $this->assertSame(2, $second->version);
        $this->assertSame(1, $second->rows_count);
        $this->assertSame('200.000', $first->records()->where('month', '2026-10-01')->firstOrFail()->kwh);
        $this->assertDatabaseCount('datasets', 2);
    }

    public function test_weather_refresh_creates_new_lineage_and_retains_original_observed_and_lagged_snapshots(): void
    {
        $this->record('2026-10', '100');
        $previous = $this->weather('2026-09', 20);
        $observed = $this->weather('2026-10', 25);
        $first = $this->build();
        $observed->update(['weather_values' => [...$observed->weather_values, 'T2M' => 26], 'content_hash' => 'changed-observed-source']);
        $previous->update(['weather_values' => [...$previous->weather_values, 'T2M' => 21], 'content_hash' => 'changed-lagged-source']);
        $this->assertTrue(app(BuildingAnalytics::class)->read($this->meter->building, $this->manager)['stale']);
        $second = $this->build();
        $old = $first->records()->firstOrFail();
        $new = $second->records()->firstOrFail();
        $this->assertEquals(25, $old->weather_snapshot['observed']['weather_values']['T2M']);
        $this->assertEquals(20, $old->weather_snapshot['lag_1']['weather_values']['T2M']);
        $this->assertEquals(26, $new->weather_snapshot['observed']['weather_values']['T2M']);
        $this->assertEquals(21, $new->features['weather_lag_1']['T2M']);
    }

    public function test_missing_weather_is_null_and_does_not_remove_valid_billing_analytics(): void
    {
        $this->record('2026-10', '100');
        $dataset = $this->build();
        $row = $dataset->records()->firstOrFail();
        $this->assertSame(0, $dataset->weather_complete_count);
        $this->assertFalse($row->weather_complete);
        $this->assertNull($row->weather_snapshot['observed']);
        $this->assertNull($row->features['weather_lag_1']);
        $this->assertCount(1, app(BuildingAnalytics::class)->read($this->meter->building, $this->manager)['rows']);
    }

    public function test_floor_area_changes_require_a_new_dataset_and_preserve_old_intensity(): void
    {
        $this->record('2026-10', '100');
        $first = $this->build();
        $this->meter->building->update(['floor_area_m2' => 200]);
        $second = $this->build();
        $this->assertEquals(1, $first->records()->firstOrFail()->metrics['kwh_per_m2']);
        $this->assertEquals(0.5, $second->records()->firstOrFail()->metrics['kwh_per_m2']);
    }

    public function test_adding_a_meter_invalidates_previous_single_meter_totals(): void
    {
        $this->record('2026-10', '100');
        $this->build();
        Meter::factory()->create(['building_id' => $this->meter->building_id]);
        $data = app(BuildingAnalytics::class)->read($this->meter->building, $this->manager);
        $this->assertTrue($data['stale']);
        $this->assertSame([], $data['rows']);
    }

    public function test_mom_yoy_cost_and_load_factor_are_computed_only_from_comparable_periods(): void
    {
        $this->record('2025-10', '100');
        $this->record('2026-09', '150', ['cost_php' => '50']);
        $this->record('2026-10', '200', ['cost_php' => '100', 'billed_demand_kw' => '2']);
        $this->build();
        $summary = app(BuildingAnalytics::class)->read($this->meter->building, $this->manager)['summary'];
        $this->assertEquals(33.333, $summary['momPercent']);
        $this->assertEquals(100, $summary['yoyPercent']);
        $this->assertEquals(100, $summary['costMomPercent']);
        $this->assertEqualsWithDelta(200 / (2 * 31 * 24), $summary['loadFactor'], 0.000001);
    }

    public function test_zero_previous_values_and_missing_floor_area_produce_no_infinite_metrics(): void
    {
        $this->meter->building->update(['floor_area_m2' => null]);
        $this->record('2026-09', '0', ['cost_php' => '0']);
        $this->record('2026-10', '100', ['billed_demand_kw' => '0']);
        $this->build();
        $summary = app(BuildingAnalytics::class)->read($this->meter->building, $this->manager)['summary'];
        $this->assertNull($summary['momPercent']);
        $this->assertNull($summary['costMomPercent']);
        $this->assertNull($summary['kwhPerM2']);
        $this->assertNull($summary['loadFactor']);
    }

    public function test_facilities_can_only_read_assigned_analytics_and_cost_is_not_exposed(): void
    {
        $this->record('2026-10', '100', ['cost_php' => '98765.43']);
        $this->build();
        $user = User::factory()->role('facilities_personnel')->create();
        $user->buildings()->attach($this->meter->building_id);
        $data = app(BuildingAnalytics::class)->read($this->meter->building, $user);
        $this->assertFalse($data['canViewCost']);
        $this->assertNull($data['summary']['costPhp']);
        $this->assertNull($data['rows'][0]['costPhp']);
        $this->assertArrayNotHasKey('input_manifest', $data['dataset']);
    }

    public function test_only_admin_and_energy_manager_can_build_datasets(): void
    {
        $this->record('2026-10', '100');
        $this->expectException(AuthorizationException::class);
        app(BuildingDataset::class)->build($this->meter->building, User::factory()->role('facilities_personnel')->create());
    }
}
