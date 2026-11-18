<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Forecast;
use App\Models\MonthlyRecord;
use App\Models\Recommendation;
use App\Models\RecommendationDecision;
use App\Models\User;
use App\Models\WeatherRecord;
use App\Services\BuildingDataset;
use App\Services\DecisionSupportInformation;
use App\Services\ForecastIssuance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ForecastData;
use Tests\TestCase;

class DecisionSupportInformationTest extends TestCase
{
    use ForecastData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-17 12:00:00', 'UTC'));
    }

    public function test_r2_compares_current_forecast_with_complete_same_building_reference_without_writes(): void
    {
        $f = $this->forecastFixture(multi: true);
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        $before = $run->fresh()->getRawOriginal();
        $auditCount = AuditLog::count();
        $this->actingAs($f['user']);
        for ($i = 0; $i < 2; $i++) {
            $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
                ->where('comparison.state', 'available')->where('comparison.forecastId', $run->id)
                ->where('comparison.referenceMonth', '2026-08-01')->where('comparison.targetMonth', '2026-09-01')
                ->where('comparison.forecastKwh', 840)->where('comparison.referenceKwh', 800)
                ->where('comparison.differenceKwh', 40)->where('comparison.differencePercent', 5)
                ->has('comparison.referenceRecords', 2)->where('comparison.referenceRecords.0.version', 1)
                ->where('comparison.datasetFingerprint', $f['dataset']->fingerprint)
                ->where('readiness.state', 'current_forecast')->where('readiness.eligible', true)
                ->where('readiness.coverage.expectedMeterPeriods', 32)->where('readiness.coverage.verifiedMeterPeriods', 32)
                ->where('readiness.coverage.missingMeterPeriods', 0)->where('readiness.coverage.incompleteMonths', [])
                ->where('policyAvailable', false)->where('counts.evaluated', 0)->where('counts.high', 0)->where('recommendations.data', []));
        }
        $this->assertSame($before, $run->fresh()->getRawOriginal());
        $this->assertSame($auditCount, AuditLog::count());
        $this->assertSame(1, Forecast::count());
        $this->assertSame(0, Recommendation::count());
        $this->assertSame(0, RecommendationDecision::count());
        $this->assertSame(0, Action::count());
        $this->assertNull(config('recommendations.policy_fingerprint'));
    }

    public function test_r2_preserves_negative_change_without_classification(): void
    {
        $f = $this->forecastFixture();
        foreach (MonthlyRecord::orderBy('month')->get() as $i => $record) {
            $record->update(['kwh' => 500 - 20 * $i]);
        }
        app(BuildingDataset::class)->build($f['building'], $f['user']);
        app(ForecastIssuance::class)->issue($f['building'], $f['user']);
        $this->actingAs($f['user'])->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('comparison.referenceKwh', 200)->where('comparison.forecastKwh', 180)
            ->where('comparison.differenceKwh', -20)->where('comparison.differencePercent', -10)
            ->missing('comparison.priority')->missing('comparison.classification')->missing('comparison.canDecide'));
    }

    public function test_r2_zero_reference_keeps_absolute_difference_but_percentage_undefined(): void
    {
        $f = $this->forecastFixture();
        MonthlyRecord::orderByDesc('month')->firstOrFail()->update(['kwh' => 0]);
        app(BuildingDataset::class)->build($f['building'], $f['user']);
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        $this->actingAs($f['user'])->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('comparison.state', 'available')->where('comparison.referenceKwh', 0)
            ->where('comparison.differenceKwh', (float) $run->fresh()->predicted_kwh)->where('comparison.differencePercent', null));
    }

    public function test_r2_equal_forecast_and_positive_reference_report_zero_change(): void
    {
        $f = $this->forecastFixture();
        MonthlyRecord::query()->update(['kwh' => 200]);
        app(BuildingDataset::class)->build($f['building'], $f['user']);
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        $this->assertNull($run->worker_result['metrics']['linear_regression']['r2']);
        $this->assertNull($run->worker_result['metrics']['previous_3_month_average']['r2']);
        $this->actingAs($f['user'])->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('comparison.state', 'available')->where('comparison.differenceKwh', 0)->where('comparison.differencePercent', 0));
    }

    public function test_r7_empty_building_explains_missing_inputs_without_inventing_coverage_or_forecast(): void
    {
        $user = User::factory()->create();
        Building::factory()->create();
        $this->actingAs($user)->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('comparison.state', 'current_forecast_unavailable')->missing('comparison.forecastKwh')
            ->where('readiness.state', 'not_eligible')->where('readiness.requiredObservations', 16)
            ->where('readiness.observations', 0)->where('readiness.coverage.requiredMeterCount', 0)
            ->where('readiness.coverage.fromMonth', null)->where('readiness.coverage.expectedMeterPeriods', null)
            ->where('readiness.notices.0.code', 'no_registered_meters')->where('readiness.notices.1.code', 'no_billing_history')
            ->where('readiness.eligibilityReason', 'missing_current_dataset')->missing('readiness.priority')->missing('readiness.canDecide'));
        $this->assertDatabaseCount('forecasts', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_r7_reports_short_history_and_eligible_but_not_issued_as_distinct_states(): void
    {
        $f = $this->forecastFixture(3);
        $this->actingAs($f['user'])->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('readiness.observations', 3)->where('readiness.eligibilityReason', 'insufficient_history')
            ->where('readiness.coverage.fromMonth', '2025-05-01')->where('readiness.coverage.throughMonth', '2025-07-01')
            ->where('readiness.coverage.expectedMeterPeriods', 3));
        $ready = $this->forecastFixture();
        $this->get('/decision-support?building='.$ready['building']->code)->assertInertia(fn (Assert $page) => $page
            ->where('readiness.state', 'eligible_not_issued')->where('readiness.eligible', true)
            ->where('readiness.notices.0.code', 'eligible_not_issued')->where('comparison.state', 'current_forecast_unavailable'));
        $this->assertDatabaseCount('forecasts', 0);
    }

    public function test_partial_newer_month_blocks_r2_and_identifies_missing_and_unverified_meter_bills(): void
    {
        $f = $this->forecastFixture(multi: true);
        app(ForecastIssuance::class)->issue($f['building'], $f['user']);
        MonthlyRecord::create(['meter_id' => $f['meters'][0]->id, 'month' => '2026-09-01', 'kwh' => 9999,
            'source' => 'manual', 'verification_status' => 'draft']);
        app(BuildingDataset::class)->build($f['building'], $f['user']);
        $this->actingAs($f['user'])->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('comparison.state', 'current_forecast_unavailable')->missing('comparison.referenceKwh')->where('currentForecastId', null)
            ->where('readiness.eligibilityReason', 'latest_period_not_completely_verified')
            ->where('readiness.latestCompleteMonth', '2026-08-01')->where('readiness.coverage.expectedMeterPeriods', 34)
            ->where('readiness.coverage.verifiedMeterPeriods', 32)->where('readiness.coverage.missingMeterPeriods', 1)
            ->where('readiness.coverage.unverifiedMeterPeriods', 1)->where('readiness.coverage.incompleteMonths.0.month', '2026-09-01')
            ->where('readiness.coverage.incompleteMonths.0.missingMeterIds', [$f['meters'][1]->id])
            ->where('readiness.coverage.incompleteMonths.0.unverifiedMeterIds', [$f['meters'][0]->id]));
    }

    public function test_r7_identifies_interior_calendar_gap_without_skipping_or_filling_it(): void
    {
        $f = $this->forecastFixture(17);
        MonthlyRecord::where('month', '2025-10-01')->delete();
        app(BuildingDataset::class)->build($f['building'], $f['user']);
        $this->actingAs($f['user'])->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('readiness.eligibilityReason', 'non_contiguous_verified_history')
            ->where('readiness.observations', 16)->where('readiness.coverage.expectedMeterPeriods', 17)
            ->where('readiness.coverage.verifiedMeterPeriods', 16)->where('readiness.coverage.missingMeterPeriods', 1)
            ->where('readiness.coverage.incompleteMonths.0.month', '2025-10-01')
            ->where('readiness.coverage.incompleteMonths.0.missingMeterIds', [$f['meters'][0]->id]));
    }

    public function test_r7_reports_missing_weather_and_does_not_treat_billing_completeness_as_forecast_readiness(): void
    {
        $f = $this->forecastFixture();
        WeatherRecord::where('month', '2026-06-01')->update(['is_complete' => false, 'content_hash' => str_repeat('a', 64)]);
        app(BuildingDataset::class)->build($f['building'], $f['user']);
        $this->actingAs($f['user'])->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('readiness.eligible', false)->where('readiness.eligibilityReason', 'missing_or_unverified_weather')
            ->where('readiness.coverage.missingMeterPeriods', 0)->where('readiness.incompleteWeatherMonths', ['2026-06-01'])
            ->where('comparison.state', 'current_forecast_unavailable'));
    }

    public function test_read_time_notices_resolve_after_correction_reprocessing_and_reissuance(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        $record = MonthlyRecord::orderByDesc('month')->firstOrFail();
        $record->update(['verification_status' => 'draft', 'version' => 2]);
        $this->actingAs($f['user'])->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('readiness.eligibilityReason', 'stale_dataset')->where('comparison.state', 'current_forecast_unavailable')
            ->missing('comparison.forecastId')->where('readiness.coverage.unverifiedMeterPeriods', 1));
        $record->update(['verification_status' => 'verified', 'kwh' => 405]);
        app(BuildingDataset::class)->build($f['building'], $f['user']);
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('readiness.state', 'eligible_not_issued')->where('comparison.state', 'current_forecast_unavailable'));
        $replacement = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        $this->assertNotSame($run->id, $replacement->id);
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('readiness.state', 'current_forecast')->where('comparison.forecastId', $replacement->id)
            ->where('comparison.referenceKwh', 405)->where('comparison.referenceRecords.0.version', 2));
        $this->assertDatabaseCount('recommendations', 0);
    }

    public function test_old_runtime_forecast_is_not_reused_for_r2(): void
    {
        $f = $this->forecastFixture();
        app(ForecastIssuance::class)->issue($f['building'], $f['user']);
        config(['forecasting.python' => '/different/runtime']);
        $this->actingAs($f['user'])->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('readiness.state', 'eligible_not_issued')->where('comparison.state', 'current_forecast_unavailable')->missing('comparison.forecastKwh'));
    }

    public function test_information_is_role_scoped_allowlisted_and_module_restrictions_still_apply(): void
    {
        $f = $this->forecastFixture();
        app(ForecastIssuance::class)->issue($f['building'], $f['user']);
        $other = Building::factory()->create();
        $this->get('/decision-support')->assertRedirect('/login');
        foreach (['admin', 'energy_manager', 'budget_officer', 'viewer', 'facilities_personnel'] as $role) {
            $user = User::factory()->role($role)->create();
            $user->buildings()->attach($f['building']);
            $this->actingAs($user)->get('/decision-support?building='.$f['building']->code)->assertInertia(fn (Assert $page) => $page
                ->where('comparison.state', 'available')->missing('comparison.referenceRecords.0.cost_php')
                ->missing('comparison.referenceRecords.0.notes')->missing('comparison.referenceRecords.0.uploaded_by')
                ->missing('readiness.coverage.meralco_account_ref')->where('counts.high', 0));
            if ($role === 'facilities_personnel') {
                $this->get('/decision-support?building='.$other->code)->assertNotFound();
                $user->buildings()->detach();
                $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
                    ->where('buildings', [])->where('comparison', null)->where('readiness', null));
            }
            $user->moduleRestrictions()->create(['module_key' => 'decision_support']);
            $this->get('/decision-support')->assertForbidden();
        }
    }

    public function test_information_service_rechecks_module_restriction(): void
    {
        $f = $this->forecastFixture();
        $user = User::factory()->role('facilities_personnel')->create();
        $user->buildings()->attach($f['building']);
        $user->moduleRestrictions()->create(['module_key' => 'decision_support']);
        $this->expectException(HttpException::class);
        app(DecisionSupportInformation::class)->read($f['building'], $user);
    }

    public function test_information_service_rechecks_role_change_and_building_scope(): void
    {
        $f = $this->forecastFixture();
        User::whereKey($f['user']->id)->update(['role' => 'facilities_personnel']);
        $this->expectException(AuthorizationException::class);
        app(DecisionSupportInformation::class)->read($f['building'], $f['user']);
    }

    public function test_inactive_registered_meters_remain_required_and_invalid_weather_units_block_readiness(): void
    {
        $f = $this->forecastFixture(multi: true);
        $f['meters'][1]->update(['status' => 'inactive']);
        $weather = WeatherRecord::where('month', '2026-06-01')->firstOrFail();
        $weather->update(['units' => [...$weather->units, 'ALLSKY_SFC_SW_DWN' => 'W/m²'], 'content_hash' => str_repeat('b', 64)]);
        app(BuildingDataset::class)->build($f['building'], $f['user']);
        $this->actingAs($f['user'])->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('readiness.coverage.requiredMeterCount', 2)->where('readiness.coverage.expectedMeterPeriods', 32)
            ->where('readiness.eligible', false)->where('readiness.eligibilityReason', 'missing_or_unverified_weather')
            ->where('comparison.state', 'current_forecast_unavailable'));
    }
}
