<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DecisionSupportHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-17 12:00:00', 'UTC'));
    }

    /** @return array{user: User, building: Building, meters: list<Meter>} */
    private function history(bool $multi = true): array
    {
        $user = User::factory()->role('energy_manager')->create();
        $building = Building::factory()->create();
        $meters = [Meter::factory()->create(['building_id' => $building->id])];
        if ($multi) {
            $meters[] = Meter::factory()->create(['building_id' => $building->id]);
        }
        foreach ($meters as $meter) {
            foreach ([5, 6, 7, 8] as $i => $month) {
                MonthlyRecord::create(['meter_id' => $meter->id, 'month' => sprintf('2026-%02d-01', $month), 'kwh' => 90 + 30 * $i,
                    'billed_demand_kw' => 10 + 10 * $i, 'source' => 'manual', 'verification_status' => 'verified',
                    'notes' => 'PRIVATE-C5-CONTEXT', 'cost_php' => 999888, 'version' => 1]);
            }
        }
        $this->actingAs($user);

        return compact('user', 'building', 'meters');
    }

    public function test_same_building_energy_and_separate_same_meter_demand_use_exact_prior_three_months_without_forecast(): void
    {
        $f = $this->history();
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('exploratoryHistory.assessedMonth', '2026-08-01')->where('exploratoryHistory.referenceMonths', ['2026-05-01', '2026-06-01', '2026-07-01'])
            ->where('exploratoryHistory.consumption.actual.value', 360)->where('exploratoryHistory.consumption.referenceAverage', 240)
            ->where('exploratoryHistory.consumption.difference', 120)->where('exploratoryHistory.consumption.absoluteDifference', 120)
            ->where('exploratoryHistory.consumption.differencePercent', 50)->has('exploratoryHistory.consumption.references', 3)
            ->where('exploratoryHistory.consumption.references.0.value', 180)->has('exploratoryHistory.consumption.actual.sources', 2)
            ->where('exploratoryHistory.consumption.actual.sources.0.meterId', $f['meters'][0]->id)
            ->where('exploratoryHistory.consumption.actual.sources.0.version', 1)->has('exploratoryHistory.meters', 2)
            ->where('exploratoryHistory.meters.0.actual.value', 40)->where('exploratoryHistory.meters.0.referenceAverage', 20)
            ->where('exploratoryHistory.meters.0.difference', 20)->where('exploratoryHistory.meters.0.differencePercent', 100)
            ->where('currentForecastId', null)->where('comparison.state', 'current_forecast_unavailable')->where('policyAvailable', false));
        $this->assertDatabaseCount('datasets', 0);
        $this->assertDatabaseCount('forecasts', 0);
        $this->assertDatabaseCount('weather_records', 0);
    }

    public function test_negative_and_equal_changes_keep_sign_and_absolute_magnitude_without_labels(): void
    {
        $this->history(false);
        $record = MonthlyRecord::where('month', '2026-08-01')->firstOrFail();
        foreach ([[60, 10, -60, 60, -50, -10, -50], [120, 20, 0, 0, 0, 0, 0]] as [$kwh, $kw, $difference, $absolute, $percent, $demandDifference, $demandPercent]) {
            $record->update(['kwh' => $kwh, 'billed_demand_kw' => $kw]);
            $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
                ->where('exploratoryHistory.consumption.difference', $difference)->where('exploratoryHistory.consumption.absoluteDifference', $absolute)
                ->where('exploratoryHistory.consumption.differencePercent', $percent)
                ->where('exploratoryHistory.meters.0.difference', $demandDifference)->where('exploratoryHistory.meters.0.differencePercent', $demandPercent)
                ->missing('exploratoryHistory.consumption.classification')->missing('exploratoryHistory.meters.0.priority'));
        }
    }

    public function test_zero_baselines_are_real_averages_but_percentage_is_undefined(): void
    {
        $this->history(false);
        MonthlyRecord::where('month', '<', '2026-08-01')->update(['kwh' => 0, 'billed_demand_kw' => 0]);
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('exploratoryHistory.consumption.referenceAverage', 0)->where('exploratoryHistory.consumption.difference', 180)
            ->where('exploratoryHistory.consumption.differencePercent', null)->where('exploratoryHistory.consumption.percentageReason', 'zero_baseline')
            ->where('exploratoryHistory.meters.0.referenceAverage', 0)->where('exploratoryHistory.meters.0.difference', 40)
            ->where('exploratoryHistory.meters.0.differencePercent', null)->where('exploratoryHistory.meters.0.percentageReason', 'zero_baseline'));
    }

    public function test_missing_calendar_month_does_not_use_older_records_or_average_only_available_months(): void
    {
        $f = $this->history(false);
        MonthlyRecord::where('month', '2026-06-01')->delete();
        MonthlyRecord::create(['meter_id' => $f['meters'][0]->id, 'month' => '2026-04-01', 'kwh' => 8888,
            'billed_demand_kw' => 888, 'source' => 'manual', 'verification_status' => 'verified']);
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('exploratoryHistory.referenceMonths', ['2026-05-01', '2026-06-01', '2026-07-01'])
            ->where('exploratoryHistory.consumption.actual.value', 180)->where('exploratoryHistory.consumption.references.1.state', 'missing')
            ->where('exploratoryHistory.consumption.references.1.value', null)->where('exploratoryHistory.consumption.referenceAverage', null)
            ->where('exploratoryHistory.consumption.difference', null)->where('exploratoryHistory.meters.0.references.1.state', 'missing')
            ->where('exploratoryHistory.meters.0.referenceAverage', null)->where('exploratoryHistory.meters.0.differencePercent', null));
    }

    public function test_incomplete_latest_building_period_withholds_total_but_keeps_valid_individual_meter_comparison(): void
    {
        $f = $this->history();
        MonthlyRecord::where('meter_id', $f['meters'][1]->id)->where('month', '2026-08-01')->delete();
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('exploratoryHistory.assessedMonth', '2026-08-01')->where('exploratoryHistory.consumption.actual.state', 'incomplete')
            ->where('exploratoryHistory.consumption.actual.value', null)->where('exploratoryHistory.consumption.referenceAverage', 240)
            ->where('exploratoryHistory.consumption.difference', null)->where('exploratoryHistory.consumption.actual.missingMeterIds', [$f['meters'][1]->id])
            ->where('exploratoryHistory.meters.0.actual.value', 40)->where('exploratoryHistory.meters.0.referenceAverage', 20)
            ->where('exploratoryHistory.meters.1.actual.state', 'missing')->where('exploratoryHistory.meters.1.actual.value', null)
            ->where('exploratoryHistory.meters.1.referenceAverage', 20)->where('exploratoryHistory.meters.1.difference', null));
    }

    public function test_unverified_reference_and_current_bills_never_expose_their_values(): void
    {
        $f = $this->history();
        MonthlyRecord::where('meter_id', $f['meters'][1]->id)->whereIn('month', ['2026-06-01', '2026-08-01'])
            ->update(['verification_status' => 'draft', 'kwh' => 777777, 'billed_demand_kw' => 555555]);
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('exploratoryHistory.consumption.actual.value', null)->where('exploratoryHistory.consumption.references.1.value', null)
            ->where('exploratoryHistory.consumption.referenceAverage', null)
            ->where('exploratoryHistory.consumption.references.1.unverifiedMeterIds', [$f['meters'][1]->id])
            ->where('exploratoryHistory.meters.1.actual.state', 'unverified')->where('exploratoryHistory.meters.1.actual.value', null)
            ->where('exploratoryHistory.meters.1.references.1.state', 'unverified')->where('exploratoryHistory.meters.1.references.1.sources', [])
            ->where('exploratoryHistory.meters.1.referenceAverage', null)->where('exploratoryHistory.meters.0.difference', 20))
            ->assertDontSee('777777')->assertDontSee('555555');
    }

    public function test_missing_demand_does_not_become_zero_or_block_complete_energy_comparison(): void
    {
        $this->history(false);
        MonthlyRecord::where('month', '2026-06-01')->update(['billed_demand_kw' => null]);
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('exploratoryHistory.consumption.referenceAverage', 120)->where('exploratoryHistory.consumption.difference', 60)
            ->where('exploratoryHistory.meters.0.references.1.state', 'demand_unavailable')->where('exploratoryHistory.meters.0.references.1.value', null)
            ->has('exploratoryHistory.meters.0.references.1.sources', 1)->where('exploratoryHistory.meters.0.actual.value', 40)
            ->where('exploratoryHistory.meters.0.referenceAverage', null));
        MonthlyRecord::where('month', '2026-06-01')->update(['billed_demand_kw' => 20]);
        MonthlyRecord::where('month', '2026-08-01')->update(['billed_demand_kw' => null]);
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('exploratoryHistory.meters.0.actual.state', 'demand_unavailable')->where('exploratoryHistory.meters.0.actual.value', null)
            ->where('exploratoryHistory.meters.0.referenceAverage', 20)->where('exploratoryHistory.meters.0.difference', null));
    }

    public function test_empty_and_short_history_have_explicit_unavailable_references(): void
    {
        $user = User::factory()->create();
        $building = Building::factory()->create();
        $this->actingAs($user)->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('exploratoryHistory.assessedMonth', null)->where('exploratoryHistory.consumption', null)->where('exploratoryHistory.meters', []));
        $f = $this->history(false);
        MonthlyRecord::where('month', '<', '2026-07-01')->delete();
        $this->get('/decision-support?building='.$f['building']->code)->assertInertia(fn (Assert $page) => $page
            ->where('exploratoryHistory.consumption.referenceAverage', null)->where('exploratoryHistory.consumption.references.0.state', 'missing')
            ->where('exploratoryHistory.consumption.references.2.value', 150)->where('exploratoryHistory.meters.0.referenceAverage', null));
    }

    public function test_corrections_recompute_fresh_values_without_preprocessing_or_overwriting_history(): void
    {
        $this->history(false);
        $record = MonthlyRecord::where('month', '2026-08-01')->firstOrFail();
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page->where('exploratoryHistory.consumption.actual.value', 180));
        $record->update(['verification_status' => 'draft', 'version' => 2, 'kwh' => 210]);
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('exploratoryHistory.consumption.actual.value', null)->where('exploratoryHistory.meters.0.actual.value', null));
        $record->update(['verification_status' => 'verified']);
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('exploratoryHistory.consumption.actual.value', 210)->where('exploratoryHistory.consumption.difference', 90)
            ->where('exploratoryHistory.consumption.actual.sources.0.version', 2));
        $this->assertDatabaseCount('datasets', 0);
    }

    public function test_fractional_average_uses_unrounded_values_for_percentage_and_inactive_meters_remain_required(): void
    {
        $f = $this->history(false);
        $f['meters'][0]->update(['status' => 'inactive']);
        foreach (['2026-05-01' => '0.001', '2026-06-01' => '0.002', '2026-07-01' => '0.002', '2026-08-01' => '0.001'] as $month => $value) {
            MonthlyRecord::where('month', $month)->update(['kwh' => $value, 'billed_demand_kw' => $value]);
        }
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('exploratoryHistory.consumption.referenceAverage', 0.001667)->where('exploratoryHistory.consumption.differencePercent', -40)
            ->where('exploratoryHistory.meters.0.meterId', $f['meters'][0]->id)->where('exploratoryHistory.meters.0.referenceAverage', 0.001667)
            ->where('exploratoryHistory.meters.0.differencePercent', -40));
    }

    public function test_all_roles_get_only_allowlisted_authorized_evidence_and_no_new_domain_writes(): void
    {
        $f = $this->history(false);
        $before = DB::table('monthly_records')->orderBy('id')->get()->toJson();
        $other = Building::factory()->create();
        foreach (['admin', 'energy_manager', 'budget_officer', 'viewer', 'facilities_personnel'] as $role) {
            $user = User::factory()->role($role)->create();
            $user->buildings()->attach($f['building']);
            $this->actingAs($user)->get('/decision-support?building='.$f['building']->code)->assertInertia(fn (Assert $page) => $page
                ->where('exploratoryHistory.consumption.actual.value', 180)->missing('exploratoryHistory.consumption.priority')
                ->missing('exploratoryHistory.consumption.actual.sources.0.notes')->missing('exploratoryHistory.consumption.actual.sources.0.cost_php')
                ->missing('exploratoryHistory.meters.0.meralco_account_ref')->missing('exploratoryHistory.meters.0.canDecide')
                ->where('policyAvailable', false)->where('counts.evaluated', 0)->where('recommendations.data', []))->assertDontSee('PRIVATE-C5-CONTEXT');
            if ($role === 'facilities_personnel') {
                $this->get('/decision-support?building='.$other->code)->assertNotFound();
                $user->buildings()->detach();
                $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page->where('exploratoryHistory', null));
            }
        }
        $this->assertSame($before, DB::table('monthly_records')->orderBy('id')->get()->toJson());
        foreach (['recommendations', 'recommendation_decisions', 'actions', 'forecasts', 'datasets', 'audit_logs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertNull(config('recommendations.policy_fingerprint'));
        $f['user']->moduleRestrictions()->create(['module_key' => 'decision_support']);
        $this->actingAs($f['user'])->get('/decision-support')->assertForbidden();
    }
}
