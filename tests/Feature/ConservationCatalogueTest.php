<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Services\ForecastIssuance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ForecastData;
use Tests\TestCase;

class ConservationCatalogueTest extends TestCase
{
    use ForecastData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-17 12:00:00', 'UTC'));
    }

    /** @return array<string, mixed> */
    private function evidence(bool $multi = false): array
    {
        $f = $this->forecastFixture(4, $multi);
        foreach ($f['meters'] as $meter) {
            foreach (MonthlyRecord::where('meter_id', $meter->id)->orderBy('month')->get() as $i => $record) {
                $record->update(['billed_demand_kw' => 10 + $i * 10, 'notes' => 'PRIVATE-R5-CONTEXT', 'cost_php' => 999888]);
            }
        }
        $this->actingAs($f['user']);

        return $f;
    }

    public function test_all_seven_entries_are_fixed_unapproved_and_ineligible_for_automatic_action_even_with_evidence(): void
    {
        $f = $this->evidence(true);
        $response = $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->has('conservationCatalogue.entries', 7)->where('conservationCatalogue.version', 'r5-review-prototype-v1')
            ->where('conservationCatalogue.entries.0.requirements.0.state', 'available')
            ->where('conservationCatalogue.entries.3.requirements.0.state', 'available')
            ->where('conservationCatalogue.entries.5.requirements.0.state', 'unavailable')
            ->where('policyAvailable', false)->where('recommendations.data', [])->where('counts.evaluated', 0)->where('counts.high', 0));
        $entries = $response->inertiaProps('conservationCatalogue.entries');
        $this->assertSame(['R5-01', 'R5-02', 'R5-03', 'R5-04', 'R5-05', 'R5-06', 'R5-07'], array_column($entries, 'id'));
        foreach ($entries as $entry) {
            $this->assertSame('unapproved', $entry['approvalState']);
            $this->assertSame('not_assessed', $entry['eligibilityState']);
            $this->assertNotEmpty($entry['description']);
            $this->assertNotEmpty($entry['eligibility']);
            $this->assertNotEmpty($entry['limitations']);
            $this->assertNotEmpty($entry['proposedOwners']);
            $this->assertNotEmpty($entry['missingRequirements']);
            foreach (array_slice($entry['requirements'], 1) as $requirement) {
                $this->assertSame('not_assessed', $requirement['state']);
            }
            foreach (['priority', 'decision', 'canDecide', 'action', 'savingsPercent', 'estimatedSavings', 'recommendationId'] as $field) {
                $this->assertArrayNotHasKey($field, $entry);
            }
        }
    }

    public function test_positive_negative_zero_and_zero_baseline_values_do_not_select_or_rank_catalogue_items(): void
    {
        $this->evidence();
        $record = MonthlyRecord::orderByDesc('month')->firstOrFail();
        $ids = null;
        foreach ([180, 60, 120] as $value) {
            $record->update(['kwh' => $value]);
            $response = $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
                ->where('conservationCatalogue.entries.0.requirements.0.state', 'available')
                ->where('conservationCatalogue.entries.0.eligibilityState', 'not_assessed'));
            $current = array_column($response->inertiaProps('conservationCatalogue.entries'), 'id');
            $ids ??= $current;
            $this->assertSame($ids, $current);
        }
        MonthlyRecord::where('month', '<', $record->month->toDateString())->update(['kwh' => 0, 'billed_demand_kw' => 0]);
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('exploratoryHistory.consumption.differencePercent', null)->where('exploratoryHistory.meters.0.differencePercent', null)
            ->where('conservationCatalogue.entries.0.requirements.0.state', 'available')
            ->where('conservationCatalogue.entries.3.requirements.0.state', 'available')
            ->where('conservationCatalogue.entries.3.eligibilityState', 'not_assessed'));
    }

    public function test_partial_meter_coverage_distinguishes_energy_and_individual_demand_evidence(): void
    {
        $f = $this->evidence(true);
        MonthlyRecord::where('meter_id', $f['meters'][1]->id)->where('month', '2025-08-01')->update(['verification_status' => 'draft']);
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('exploratoryHistory.consumption.actual.value', null)->where('exploratoryHistory.meters.1.actual.value', null)
            ->where('conservationCatalogue.entries.0.requirements.0.state', 'unavailable')
            ->where('conservationCatalogue.entries.3.requirements.0.state', 'available')
            ->where('conservationCatalogue.entries.4.requirements.0.state', 'available')
            ->where('conservationCatalogue.entries.6.requirements.0.state', 'available')
            ->where('conservationCatalogue.entries.4.requirements.1.state', 'not_assessed'));
    }

    public function test_missing_reference_months_and_optional_demand_remain_missing_requirements(): void
    {
        $this->evidence();
        MonthlyRecord::where('month', '2025-06-01')->delete();
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('exploratoryHistory.consumption.referenceAverage', null)
            ->where('conservationCatalogue.entries.0.requirements.0.state', 'unavailable')
            ->where('conservationCatalogue.entries.0.missingRequirements.0.state', 'unavailable')
            ->where('conservationCatalogue.entries.3.requirements.0.state', 'unavailable'));
    }

    public function test_missing_billed_demand_does_not_suppress_energy_context_or_invent_demand(): void
    {
        $this->evidence();
        MonthlyRecord::where('month', '2025-07-01')->update(['billed_demand_kw' => null]);
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('conservationCatalogue.entries.0.requirements.0.state', 'available')
            ->where('conservationCatalogue.entries.3.requirements.0.state', 'unavailable')
            ->where('exploratoryHistory.meters.0.referenceAverage', null)
            ->where('exploratoryHistory.meters.0.references.2.state', 'demand_unavailable'));
    }

    public function test_current_issued_forecast_is_evidence_not_approval_or_a_reusable_scenario_assumption(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        $before = $run->fresh()->getRawOriginal();
        $this->actingAs($f['user'])->withSession(['scenarioResult' => ['reductionPercent' => 23, 'scenarioKwh' => 123]])
            ->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('comparison.forecastId', $run->id)->where('comparison.forecastKwh', 420)
            ->where('conservationCatalogue.entries.5.requirements.0.state', 'available')
            ->where('conservationCatalogue.entries.5.requirements.1.state', 'not_assessed')
            ->where('conservationCatalogue.entries.5.requirements.2.state', 'not_assessed')
            ->where('conservationCatalogue.entries.5.approvalState', 'unapproved')
            ->missing('conservationCatalogue.entries.5.reductionPercent')->missing('conservationCatalogue.entries.5.scenarioKwh'));
        $this->assertSame($before, $run->fresh()->getRawOriginal());
    }

    public function test_corrections_withdraw_comparison_requirements_and_reverification_restores_only_system_evidence(): void
    {
        $this->evidence();
        $record = MonthlyRecord::orderByDesc('month')->firstOrFail();
        $record->update(['verification_status' => 'draft', 'version' => 2]);
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('conservationCatalogue.entries.0.requirements.0.state', 'unavailable')
            ->where('conservationCatalogue.entries.3.requirements.0.state', 'unavailable'));
        $record->update(['verification_status' => 'verified']);
        $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->where('conservationCatalogue.entries.0.requirements.0.state', 'available')
            ->where('conservationCatalogue.entries.3.requirements.0.state', 'available')
            ->where('conservationCatalogue.entries.0.requirements.1.state', 'not_assessed')
            ->where('exploratoryHistory.consumption.actual.sources.0.version', 2));
    }

    public function test_empty_building_still_shows_all_unapproved_entries_without_fabricated_evidence(): void
    {
        $user = User::factory()->create();
        Building::factory()->create();
        $response = $this->actingAs($user)->get('/decision-support')->assertInertia(fn (Assert $page) => $page
            ->has('conservationCatalogue.entries', 7)->where('comparison.state', 'current_forecast_unavailable')
            ->where('exploratoryHistory.consumption', null)->where('exploratoryHistory.meters', []));
        foreach ($response->inertiaProps('conservationCatalogue.entries') as $entry) {
            $this->assertSame('unavailable', $entry['requirements'][0]['state']);
            $this->assertSame('not_assessed', $entry['eligibilityState']);
        }
    }

    public function test_roles_building_scope_module_gates_and_private_evidence_are_preserved(): void
    {
        $f = $this->evidence();
        $other = Building::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->get('/decision-support')->assertRedirect('/login');
        foreach (['admin', 'energy_manager', 'budget_officer', 'viewer', 'facilities_personnel'] as $role) {
            $user = User::factory()->role($role)->create();
            $user->buildings()->attach($f['building']);
            $response = $this->actingAs($user)->get('/decision-support?building='.$f['building']->code)->assertInertia(fn (Assert $page) => $page
                ->has('conservationCatalogue.entries', 7)->where('conservationCatalogue.entries.0.approvalState', 'unapproved')
                ->where('conservationCatalogue.entries.6.requirements.1.state', 'not_assessed'));
            $response->assertDontSee('PRIVATE-R5-CONTEXT')->assertDontSee($f['meters'][0]->meralco_account_ref);
            if ($role === 'facilities_personnel') {
                $this->get('/decision-support?building='.$other->code)->assertNotFound();
                $user->buildings()->detach();
                $this->get('/decision-support')->assertInertia(fn (Assert $page) => $page->where('conservationCatalogue', null));
            }
            $user->moduleRestrictions()->create(['module_key' => 'decision_support']);
            $this->get('/decision-support')->assertForbidden();
        }
    }

    public function test_catalogue_browsing_has_no_domain_writes_or_catalogue_mutation_endpoint(): void
    {
        $this->evidence();
        $tables = ['monthly_records', 'forecasts', 'recommendations', 'recommendation_decisions', 'actions', 'audit_logs', 'notification_receipts', 'jobs'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->get('/decision-support')->assertOk();
        $this->get('/decision-support')->assertOk();
        $this->post('/decision-support', ['catalogue_id' => 'R5-01', 'decision' => 'accepted'])->assertStatus(405);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table.' changed during read-only catalogue review');
        }
        $this->assertNull(config('recommendations.policy_fingerprint'));
    }
}
