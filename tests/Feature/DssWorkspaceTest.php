<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\AuditLog;
use App\Models\BillingEvidence;
use App\Models\DssConfiguration;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\Recommendation;
use App\Models\RecommendationDecision;
use App\Models\User;
use App\Services\DssConfigurationWorkflow;
use App\Services\ForecastIssuance;
use App\Services\ForecastScenario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BillingEvidenceData;
use Tests\Support\FixtureReviewPolicy;
use Tests\Support\ForecastData;
use Tests\TestCase;

class DssWorkspaceTest extends TestCase
{
    use BillingEvidenceData, ForecastData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18 12:00:00', 'UTC'));
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function proposal(string $kind = 'rule', string $subject = 'R2', array $overrides = []): array
    {
        return array_replace_recursive([
            'kind' => $kind, 'subject_key' => $subject, 'previous_version' => 0, 'valid_from' => '2026-11-01', 'valid_to' => '2027-01-01',
            'payload' => ['title' => 'Fictional configuration proposal', 'rationale' => 'Synthetic review reference',
                'mode' => 'information_only', 'measure' => DssConfigurationWorkflow::MEASURES[$subject][0] ?? null,
                'reference_method' => 'Explicitly exploratory comparison; no operational classification',
                'tariff_basis' => 'energy_only', 'rate_php_per_kwh' => '2.50000000',
                'source_reference' => 'SYNTHETIC-TARIFF-REFERENCE', 'source_document_hash' => hash('sha256', 'fictional tariff evidence'),
                'included_charges' => 'Fictional energy component only', 'excluded_charges' => 'Demand, fixed, taxes, balances and credits',
                'conditions' => 'Human review requires explicit supporting evidence', 'limitations' => 'No savings guarantee or action authorization',
                'responsible_office' => 'Fictional review office', 'scope' => 'Synthetic building review scope', 'operational_evidence' => 'Fictional operating evidence; remaining requirements documented'],
        ], $overrides);
    }

    private function transition(DssConfiguration $item, User $actor, string $operation, ?int $version = null): TestResponse
    {
        $item = $item->fresh();

        return $this->actingAs($actor)->post('/decision-support/reviews/'.$item->id, ['operation' => $operation,
            'state_version' => $version ?? $item->state_version, 'content_hash' => $item->content_hash, 'reference' => 'Fictional independent review reference']);
    }

    public function test_http_billing_evidence_revision_independent_verification_and_stale_input_reuse_the_verified_foundation(): void
    {
        $f = $this->billingFixture();
        $before = $f['record']->fresh()->getRawOriginal();
        $input = [...$this->billingInput(), 'utility_service_id' => $f['service']->id, 'expected_record_version' => $f['record']->version,
            'previous_evidence_version' => 1, 'authorized_source' => true];
        $this->actingAs($f['author'])->from('/billing-evidence')->post('/billing-evidence/records/'.$f['record']->id, $input)->assertSessionHasNoErrors();
        $bill = BillingEvidence::latest('id')->firstOrFail();
        $this->assertSame(2, $bill->version);
        $this->post('/billing-evidence/'.$bill->id.'/verify', ['version' => 2, 'confirmed_against_bill' => true])->assertSessionHasErrors('billing_evidence');
        $this->actingAs($f['reviewer'])->post('/billing-evidence/'.$bill->id.'/verify', ['version' => 2, 'confirmed_against_bill' => true])->assertSessionHasNoErrors();
        $this->assertSame('verified', $bill->fresh()->verification_status);
        $this->actingAs($f['author'])->post('/billing-evidence/records/'.$f['record']->id, $input)->assertSessionHasErrors('billing_evidence');
        $this->assertSame($before, $f['record']->fresh()->getRawOriginal());
        $this->get('/billing-evidence?building='.$f['building']->code.'&record='.$f['record']->id)->assertInertia(fn (Assert $page) => $page
            ->component('billing-evidence')->has('evidence.data', 2)->where('latestEvidenceVersion', 2)->where('canMap', true));
    }

    public function test_billing_evidence_and_configuration_show_the_latest_verified_period_and_prefer_verified_records(): void
    {
        $f = $this->billingFixture();
        MonthlyRecord::create(['meter_id' => $f['meter']->id, 'month' => '2025-06-01', 'kwh' => '120.000', 'cost_php' => '900.00',
            'source' => 'manual', 'verification_status' => 'draft', 'uploaded_by' => $f['author']->id, 'last_edited_by' => $f['author']->id]);

        $this->actingAs($f['author'])->get('/billing-evidence?building='.$f['building']->code)->assertInertia(fn (Assert $page) => $page
            ->component('billing-evidence')->where('latestVerifiedPeriod', '2025-05-01')
            ->where('selectedRecord.id', $f['record']->id)->where('selectedRecord.verification_status', 'verified'));

        $this->actingAs($f['author'])->get('/decision-support/configuration?building='.$f['building']->code.'&kind=rule&subject=R2')
            ->assertInertia(fn (Assert $page) => $page->component('dss-configuration')->where('latestVerifiedPeriod', '2025-05-01'));
    }

    public function test_only_the_author_can_submit_a_configuration_draft(): void
    {
        $f = $this->billingFixture();
        $this->actingAs($f['author'])->post('/decision-support/configuration/'.$f['building']->id, $this->proposal())->assertSessionHasNoErrors();
        $item = DssConfiguration::sole();

        $this->transition($item, $f['reviewer'], 'submit')->assertSessionHasErrors('configuration');
        $this->assertSame('draft', $item->fresh()->status);
        $this->assertDatabaseCount('dss_configuration_events', 1);

        $this->transition($item, $f['author'], 'submit')->assertSessionHasNoErrors();
        $this->assertSame('submitted', $item->fresh()->status);
    }

    public function test_service_association_is_explicit_admin_only_audited_and_preserves_the_legacy_reference(): void
    {
        $f = $this->billingFixture();
        $meter = Meter::factory()->create(['building_id' => $f['building']->id]);
        $before = $meter->fresh()->getRawOriginal();
        $input = ['meter_id' => $meter->id, 'utility_account_id' => $f['service']->utility_account_id,
            'service_reference' => 'EXPLICIT-FAKE-NEW-SERVICE', 'mapping_reference' => 'Fictional verified association', 'authorized_source' => true];
        $this->actingAs($f['reviewer'])->post('/billing-evidence/services', $input)->assertForbidden();
        $this->actingAs($f['author'])->post('/billing-evidence/services', $input)->assertSessionHasNoErrors();
        $this->assertSame($before, $meter->fresh()->getRawOriginal());
        $this->assertDatabaseHas('utility_services', ['meter_id' => $meter->id, 'utility_account_id' => $f['service']->utility_account_id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'utility_service.associated']);
    }

    public function test_rule_configuration_uses_independent_admin_or_energy_manager_approval_without_activation(): void
    {
        $f = $this->billingFixture();
        $this->actingAs($f['author'])->post('/decision-support/configuration/'.$f['building']->id, $this->proposal())->assertSessionHasNoErrors();
        $item = DssConfiguration::sole();
        $this->assertArrayNotHasKey('rate_php_per_kwh', $item->payload);
        $this->transition($item, $f['author'], 'submit')->assertSessionHasNoErrors();
        $this->transition($item, $f['author'], 'approve')->assertSessionHasErrors('configuration');
        $this->transition($item, $f['reviewer'], 'approve')->assertSessionHasNoErrors();
        $this->assertSame('approved', $item->fresh()->status);
        $this->assertSame($item->content_hash, $item->fresh()->approved_hash);
        $this->assertDatabaseCount('dss_configuration_events', 3);
        $this->assertDatabaseCount('recommendations', 0);
        $this->assertDatabaseCount('actions', 0);
        $this->assertNull(config('recommendations.policy_fingerprint'));
        $this->get('/decision-support/configuration?building='.$f['building']->code)->assertInertia(fn (Assert $page) => $page
            ->component('dss-configuration')->where('runtimeActive', false)->where('items.data.0.status', 'approved'));
        $this->transition($item, $f['author'], 'revoke')->assertSessionHasErrors('configuration');
        $this->transition($item, $f['reviewer'], 'revoke')->assertSessionHasNoErrors();
    }

    public function test_admin_can_independently_approve_a_different_energy_managers_configuration(): void
    {
        $f = $this->billingFixture();
        $this->actingAs($f['reviewer'])->post('/decision-support/configuration/'.$f['building']->id, $this->proposal())->assertSessionHasNoErrors();
        $item = DssConfiguration::sole();
        $this->transition($item, $f['reviewer'], 'submit')->assertSessionHasNoErrors();
        $this->transition($item, $f['author'], 'approve')->assertSessionHasNoErrors();
        $this->assertSame($f['author']->id, $item->fresh()->reviewer_id);
    }

    public function test_tariff_source_confirmation_is_budget_only_binds_the_hash_and_cannot_approve_configurations(): void
    {
        $f = $this->billingFixture();
        $budget = User::factory()->role('budget_officer')->create();
        $this->actingAs($f['author'])->post('/decision-support/configuration/'.$f['building']->id, $this->proposal('tariff', 'R1'))->assertSessionHasNoErrors();
        $item = DssConfiguration::sole();
        $this->transition($item, $f['author'], 'submit')->assertSessionHasNoErrors();
        $this->transition($item, $f['reviewer'], 'approve')->assertSessionHasErrors('configuration');
        $this->transition($item, $f['reviewer'], 'confirm_source')->assertForbidden();
        $this->transition($item, $budget, 'confirm_source')->assertSessionHasNoErrors();
        $this->transition($item, $budget, 'approve')->assertForbidden();
        $this->actingAs($budget)->post('/decision-support/configuration/'.$f['building']->id, $this->proposal())->assertForbidden();
        $this->get('/decision-support/configuration?kind=rule')->assertForbidden();
        $this->get('/decision-support/configuration?building='.$f['building']->code.'&kind=tariff')->assertInertia(fn (Assert $page) => $page->where('canDraft', false));
        $this->transition($item, $f['reviewer'], 'approve')->assertSessionHasNoErrors();
        $this->assertSame($budget->id, $item->fresh()->source_confirmation['actorId']);
        $this->assertNull(config('recommendations.policy_fingerprint'));
    }

    public function test_missing_policy_values_are_not_defaulted_or_submitted_and_wrong_measure_is_rejected(): void
    {
        $f = $this->billingFixture();
        $data = $this->proposal('rule', 'R2', ['payload' => ['mode' => 'conditional_proposal', 'threshold' => null, 'priority' => null]]);
        $this->actingAs($f['author'])->post('/decision-support/configuration/'.$f['building']->id, $data)->assertSessionHasNoErrors();
        $item = DssConfiguration::sole();
        $this->transition($item, $f['author'], 'submit')->assertSessionHasErrors('configuration');
        $this->assertSame('draft', $item->fresh()->status);
        $data['payload']['measure'] = 'meter_demand_difference_percent';
        $data['previous_version'] = 1;
        $this->actingAs($f['author'])->post('/decision-support/configuration/'.$f['building']->id, $data)->assertSessionHasErrors('configuration');
    }

    public function test_stale_state_hash_and_newer_version_are_rejected_without_overwriting_history(): void
    {
        $f = $this->billingFixture();
        $this->actingAs($f['author'])->post('/decision-support/configuration/'.$f['building']->id, $this->proposal())->assertSessionHasNoErrors();
        $item = DssConfiguration::sole();
        $this->transition($item, $f['author'], 'submit')->assertSessionHasNoErrors();
        $this->transition($item, $f['reviewer'], 'approve', 1)->assertSessionHasErrors('configuration');
        $this->actingAs($f['author'])->post('/decision-support/configuration/'.$f['building']->id, $this->proposal())->assertSessionHasErrors('configuration');
        $this->post('/decision-support/configuration/'.$f['building']->id, $this->proposal(overrides: ['previous_version' => 1]))->assertSessionHasNoErrors();
        $this->transition($item, $f['reviewer'], 'approve')->assertSessionHasErrors('configuration');
        $this->assertDatabaseCount('dss_configurations', 2);
        $this->assertSame('submitted', $item->fresh()->status);
    }

    public function test_configuration_scope_changes_and_tampering_block_approval(): void
    {
        $f = $this->billingFixture();
        $this->actingAs($f['author'])->post('/decision-support/configuration/'.$f['building']->id, $this->proposal())->assertSessionHasNoErrors();
        $item = DssConfiguration::sole();
        $this->transition($item, $f['author'], 'submit')->assertSessionHasNoErrors();
        DB::table('dss_configurations')->where('id', $item->id)->update(['payload' => json_encode(['title' => 'tampered'])]);
        $this->transition($item, $f['reviewer'], 'approve')->assertSessionHasErrors('configuration');
        $this->assertSame('submitted', $item->fresh()->status);
    }

    public function test_r5_catalogue_and_case_review_are_versioned_independent_and_retract_on_source_changes(): void
    {
        $f = $this->billingFixture();
        $this->actingAs($f['author'])->post('/decision-support/configuration/'.$f['building']->id, $this->proposal('catalogue', 'R5-01'))->assertSessionHasNoErrors();
        $catalogue = DssConfiguration::sole();
        $this->transition($catalogue, $f['author'], 'submit')->assertSessionHasNoErrors();
        $this->transition($catalogue, $f['reviewer'], 'approve')->assertSessionHasNoErrors();
        $input = $this->proposal('review', 'R5-01', ['payload' => ['catalogue_configuration_id' => $catalogue->id]]);
        $this->actingAs($f['author'])->post('/decision-support/configuration/'.$f['building']->id, $input)->assertSessionHasNoErrors();
        $case = DssConfiguration::where('kind', 'review')->sole();
        $this->transition($case, $f['author'], 'submit')->assertSessionHasNoErrors();
        $f['record']->update(['verification_status' => 'draft', 'version' => 2]);
        $this->transition($case, $f['reviewer'], 'approve')->assertSessionHasErrors('configuration');
        $this->actingAs($f['reviewer'])->get('/decision-support/configuration?building='.$f['building']->code.'&kind=review&subject=R5-01')
            ->assertInertia(fn (Assert $page) => $page->where('items.data.0.sourceCurrent', false));
        $this->assertDatabaseCount('recommendations', 0);
        $this->assertDatabaseCount('actions', 0);
    }

    public function test_overlapping_approved_tariffs_are_rejected_under_the_building_lock(): void
    {
        $f = $this->billingFixture();
        $budget = User::factory()->role('budget_officer')->create();
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($f['author'])->post('/decision-support/configuration/'.$f['building']->id, $this->proposal('tariff', 'R1', ['previous_version' => $i]))->assertSessionHasNoErrors();
            $item = DssConfiguration::latest('id')->firstOrFail();
            $this->transition($item, $f['author'], 'submit')->assertSessionHasNoErrors();
            $this->transition($item, $budget, 'confirm_source')->assertSessionHasNoErrors();
            $result = $this->transition($item, $f['reviewer'], 'approve');
            $i === 0 ? $result->assertSessionHasNoErrors() : $result->assertSessionHasErrors('configuration');
        }
    }

    public function test_viewer_and_facilities_cannot_access_private_evidence_or_configuration_mutations_and_module_denials_hold(): void
    {
        $f = $this->billingFixture();
        foreach (['viewer', 'facilities_personnel', 'budget_officer'] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)->get('/billing-evidence')->assertForbidden();
            $this->post('/billing-evidence/records/'.$f['record']->id, [])->assertForbidden();
            $this->post('/decision-support/configuration/'.$f['building']->id, $this->proposal())->assertForbidden();
            if ($role !== 'budget_officer') {
                $this->get('/decision-support/configuration')->assertForbidden();
            }
        }
        $f['author']->moduleRestrictions()->create(['module_key' => 'decision_support']);
        $this->actingAs($f['author'])->get('/decision-support/configuration')->assertForbidden();
        $this->post('/decision-support/configuration/'.$f['building']->id, $this->proposal())->assertForbidden();
        $f['reviewer']->moduleRestrictions()->create(['module_key' => 'verification_queue']);
        $this->actingAs($f['reviewer'])->post('/billing-evidence/'.$f['bill']->id.'/verify', [])->assertForbidden();
    }

    public function test_audit_failure_rolls_back_configuration_state_and_immutable_event(): void
    {
        $f = $this->billingFixture();
        $item = app(DssConfigurationWorkflow::class)->draft($f['building'], $f['author'], $this->proposal());
        AuditLog::creating(function ($audit) {
            if ($audit->event === 'dss.configuration.submit') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            app(DssConfigurationWorkflow::class)->transition($item, $f['author'], ['operation' => 'submit', 'state_version' => 1,
                'content_hash' => $item->content_hash, 'reference' => 'Synthetic rollback check']);
            $this->fail('Expected rollback');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
            $this->assertSame('draft', $item->fresh()->status);
            $this->assertSame(1, $item->fresh()->state_version);
            $this->assertDatabaseCount('dss_configuration_events', 1);
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_separate_r4_meter_decisions_preserve_building_history_and_exactly_one_action_per_subject(): void
    {
        $f = $this->forecastFixture(16, true);
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        $policy = hash('sha256', 'isolated per-subject workflow fixture, not policy');
        FixtureReviewPolicy::install();
        config(['recommendations.policy_fingerprint' => $policy]);
        foreach (['building', 'meter:'.$f['meters'][0]->id, 'meter:'.$f['meters'][1]->id] as $index => $key) {
            $item = Recommendation::create(['forecast_id' => $run->id, 'rule_code' => 'R4', 'policy_fingerprint' => $policy,
                'source_fingerprint' => $run->dataset_fingerprint, 'subject_key' => $key,
                'subject_snapshot' => $index === 0 ? null : ['meterId' => $f['meters'][$index - 1]->id],
                'title' => 'Synthetic subject review', 'evidence' => ['fixture' => true], 'evaluation_status' => 'evaluated'])->fresh();
            $accept = ['decision' => 'accepted', 'version' => 1, 'note' => 'Synthetic reviewed evidence', 'owner_id' => $f['user']->id, 'due_date' => '2026-12-01'];
            $this->actingAs($f['user'])->post('/recommendations/'.$item->id.'/decide', $accept)->assertSessionHasNoErrors();
            $this->post('/recommendations/'.$item->id.'/decide', $accept)->assertSessionHasErrors('recommendation');
            $this->assertSame($key, RecommendationDecision::where('recommendation_id', $item->id)->sole()->source_snapshot['subject_key']);
        }
        $this->assertDatabaseCount('recommendations', 3);
        $this->assertDatabaseCount('actions', 3);
        config(['recommendations.policy_fingerprint' => null]);
    }

    public function test_r5_per_entry_snapshots_require_exact_reviewed_catalogue_and_remain_blocked_with_null_policy(): void
    {
        $f = $this->forecastFixture();
        $author = User::factory()->admin()->create();
        $workflow = app(DssConfigurationWorkflow::class);
        $catalogues = [];
        foreach (['R5-01', 'R5-02'] as $entry) {
            $cat = $workflow->draft($f['building'], $author, $this->proposal('catalogue', $entry));
            $this->transition($cat, $author, 'submit')->assertSessionHasNoErrors();
            $this->transition($cat, $f['user'], 'approve')->assertSessionHasNoErrors();
            $catalogues[] = $cat->fresh();
        }
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        $policy = hash('sha256', 'synthetic catalogue subject fixture');
        FixtureReviewPolicy::install();
        foreach ($catalogues as $cat) {
            $item = Recommendation::create(['forecast_id' => $run->id, 'rule_code' => 'R5', 'policy_fingerprint' => $policy,
                'source_fingerprint' => $run->dataset_fingerprint, 'subject_key' => 'catalogue:'.$cat->subject_key,
                'subject_snapshot' => ['catalogueEntry' => $cat->subject_key, 'catalogueConfigurationId' => $cat->id, 'catalogueVersion' => $cat->version, 'catalogueHash' => $cat->approved_hash],
                'title' => 'Synthetic catalogue review', 'evidence' => ['fixture' => true], 'evaluation_status' => 'evaluated'])->fresh();
            $accept = ['decision' => 'accepted', 'version' => 1, 'note' => 'Synthetic evidence', 'owner_id' => $f['user']->id, 'due_date' => '2026-12-01'];
            $this->actingAs($f['user'])->post('/recommendations/'.$item->id.'/decide', $accept)->assertSessionHasErrors('recommendation');
            config(['recommendations.policy_fingerprint' => $policy]);
            $this->post('/recommendations/'.$item->id.'/decide', $accept)->assertSessionHasNoErrors();
            config(['recommendations.policy_fingerprint' => null]);
        }
        $this->assertDatabaseCount('actions', 2);
        $this->assertSame(2, Action::distinct()->count('recommendation_id'));
    }

    public function test_existing_r6_screening_rechecks_revoked_module_access_even_with_a_previously_loaded_actor(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        $f['user']->moduleRestrictions()->create(['module_key' => 'solar_scenarios']);
        try {
            app(ForecastScenario::class)->calculate($f['building'], $f['user'], ['expected_forecast_id' => $run->id, 'reduction_percent' => 10]);
            $this->fail('Revoked module permitted screening');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
