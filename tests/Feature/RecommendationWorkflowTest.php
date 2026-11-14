<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Building;
use App\Models\MonthlyRecord;
use App\Models\Recommendation;
use App\Models\RecommendationDecision;
use App\Models\User;
use App\Services\ForecastIssuance;
use App\Services\RecommendationWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Support\ForecastData;
use Tests\TestCase;

class RecommendationWorkflowTest extends TestCase
{
    use ForecastData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 12:00:00', 'UTC'));
    }

    /** @return array{recommendation: Recommendation, user: User, building: Building} */
    private function recommendationFixture(bool $policy = true): array
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        // An explicitly synthetic evaluator contract, enabled ONLY in this test.
        $fingerprint = hash('sha256', 'synthetic workflow contract, not an approved rule');
        if ($policy) {
            config(['recommendations.policy_fingerprint' => $fingerprint]);
        }
        $recommendation = Recommendation::create(['forecast_id' => $run->id, 'rule_code' => 'R5', 'policy_fingerprint' => $fingerprint,
            'source_fingerprint' => $run->dataset_fingerprint, 'title' => 'Synthetic review fixture', 'message' => 'Review synthetic evidence.',
            'evidence' => ['forecast_kwh' => 420, 'fixture' => true], 'priority' => 'high', 'evaluation_status' => 'evaluated']);

        return ['recommendation' => $recommendation->fresh(), 'user' => $f['user'], 'building' => $f['building']];
    }

    /** @return array<string, mixed> */
    private function acceptance(User $owner, int $version = 1): array
    {
        return ['decision' => 'accepted', 'version' => $version, 'note' => 'Reviewed the source evidence.', 'owner_id' => $owner->id, 'due_date' => '2026-11-20'];
    }

    public function test_acceptance_preserves_sources_and_creates_exactly_one_action_and_audit(): void
    {
        $f = $this->recommendationFixture();
        $item = $f['recommendation'];
        $before = $item->only(['forecast_id', 'rule_code', 'evidence', 'priority', 'policy_fingerprint', 'source_fingerprint']);
        $forecastBefore = $item->forecast->getRawOriginal();
        $this->actingAs($f['user'])->post('/recommendations/'.$item->id.'/decide', $this->acceptance($f['user']))->assertSessionHasNoErrors();
        $this->assertSame($before, $item->fresh()->only(array_keys($before)));
        $this->assertSame($forecastBefore, $item->forecast->fresh()->getRawOriginal());
        $this->assertDatabaseHas('actions', ['recommendation_id' => $item->id, 'owner_id' => $f['user']->id, 'due_date' => '2026-11-20', 'status' => 'open']);
        $this->assertDatabaseHas('recommendations', ['id' => $item->id, 'decision' => 'accepted', 'version' => 2, 'decided_by' => $f['user']->id]);
        $decision = RecommendationDecision::sole();
        $this->assertSame('pending', $decision->from_state);
        $this->assertSame('accepted', $decision->to_state);
        $this->assertSame($item->evidence, $decision->source_snapshot['evidence']);
        $this->assertSame($item->policy_fingerprint, $decision->source_snapshot['policy_fingerprint']);
        $audit = AuditLog::where('event', 'recommendation.decided')->sole();
        $this->assertSame('pending', $audit->before['decision']);
        $this->assertSame('accepted', $audit->after['recommendation']['decision']);
        $this->assertSame($f['user']->id, $audit->actor_id);
        $this->post('/recommendations/'.$item->id.'/decide', $this->acceptance($f['user']))->assertSessionHasErrors('recommendation');
        $this->post('/recommendations/'.$item->id.'/decide', [...$this->acceptance($f['user'], 2), 'decision' => 'rejected'])->assertSessionHasErrors('recommendation');
        $this->assertDatabaseCount('actions', 1);
        $this->assertDatabaseCount('recommendation_decisions', 1);
    }

    public function test_defer_then_reject_retains_ordered_history_and_reject_is_final(): void
    {
        $f = $this->recommendationFixture();
        $url = '/recommendations/'.$f['recommendation']->id.'/decide';
        $this->actingAs($f['user'])->post($url, ['decision' => 'deferred', 'version' => 1, 'note' => 'Need operational context.'])->assertSessionHasNoErrors();
        $this->post($url, ['decision' => 'rejected', 'version' => 1, 'note' => 'Stale browser.'])->assertSessionHasErrors('recommendation');
        $this->post($url, ['decision' => 'rejected', 'version' => 2, 'note' => 'Reviewed, not applicable.'])->assertSessionHasNoErrors();
        $this->post($url, $this->acceptance($f['user'], 3))->assertSessionHasErrors('recommendation');
        $this->assertSame(['pending', 'deferred'], RecommendationDecision::orderBy('id')->pluck('from_state')->all());
        $this->assertSame(['deferred', 'rejected'], RecommendationDecision::orderBy('id')->pluck('to_state')->all());
        $this->assertDatabaseCount('actions', 0);
        $this->assertDatabaseHas('recommendations', ['id' => $f['recommendation']->id, 'decision' => 'rejected', 'version' => 3]);
    }

    public function test_deferred_recommendation_can_be_accepted_with_explicit_assignment(): void
    {
        $f = $this->recommendationFixture();
        $url = '/recommendations/'.$f['recommendation']->id.'/decide';
        $this->actingAs($f['user'])->post($url, ['decision' => 'deferred', 'version' => 1, 'note' => 'Await owner.'])->assertSessionHasNoErrors();
        $this->post($url, $this->acceptance($f['user'], 2))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('actions', 1);
        $this->assertDatabaseCount('recommendation_decisions', 2);
    }

    public function test_only_energy_manager_can_decide_and_denials_are_audited(): void
    {
        $f = $this->recommendationFixture();
        $url = '/recommendations/'.$f['recommendation']->id.'/decide';
        $this->post($url, $this->acceptance($f['user']))->assertRedirect('/login');
        foreach (['admin', 'budget_officer', 'viewer', 'facilities_personnel'] as $role) {
            $user = User::factory()->role($role)->create();
            $user->buildings()->attach($f['building']);
            $this->actingAs($user)->post($url, $this->acceptance($f['user']))->assertForbidden();
            $this->assertDatabaseHas('audit_logs', ['event' => 'authorization.denied', 'actor_id' => $user->id]);
        }
        // Recheck persisted actor role even when a caller holds an old model.
        User::whereKey($f['user']->id)->update(['role' => 'viewer']);
        try {
            app(RecommendationWorkflow::class)->decide($f['recommendation'], $f['user'], $this->acceptance($f['user']));
            $this->fail('A revoked manager decided.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('actions', 0);
            $this->assertDatabaseCount('recommendation_decisions', 0);
        }
    }

    public function test_default_missing_changed_and_unevaluated_policy_all_block_decisions(): void
    {
        $f = $this->recommendationFixture(false);
        $item = $f['recommendation'];
        $url = '/recommendations/'.$item->id.'/decide';
        $this->actingAs($f['user'])->post($url, $this->acceptance($f['user']))->assertSessionHasErrors('recommendation');
        config(['recommendations.policy_fingerprint' => hash('sha256', 'changed contract')]);
        $this->post($url, $this->acceptance($f['user']))->assertSessionHasErrors('recommendation');
        config(['recommendations.policy_fingerprint' => $item->policy_fingerprint]);
        foreach (['requires_policy', 'insufficient_data'] as $state) {
            $item->update(['evaluation_status' => $state]);
            $this->post($url, $this->acceptance($f['user']))->assertSessionHasErrors('recommendation');
        }
        $item->update(['evaluation_status' => 'evaluated', 'rule_code' => 'R99']);
        $this->post($url, $this->acceptance($f['user']))->assertSessionHasErrors('recommendation');
        $this->assertDatabaseCount('actions', 0);
        $this->assertDatabaseCount('recommendation_decisions', 0);
    }

    public function test_source_correction_and_mismatched_source_fingerprint_block_decisions(): void
    {
        $f = $this->recommendationFixture();
        $item = $f['recommendation'];
        $url = '/recommendations/'.$item->id.'/decide';
        $source = $item->source_fingerprint;
        $item->update(['source_fingerprint' => str_repeat('a', 64)]);
        $this->actingAs($f['user'])->post($url, $this->acceptance($f['user']))->assertSessionHasErrors('recommendation');
        $item->update(['source_fingerprint' => $source]);
        MonthlyRecord::firstOrFail()->update(['verification_status' => 'draft', 'version' => 2]);
        $this->post($url, $this->acceptance($f['user']))->assertSessionHasErrors('recommendation');
        $this->get('/decision-support')->assertInertia(fn ($page) => $page->where('currentForecastId', null)->where('recommendations.data.0.evidence', null)->where('recommendations.data.0.canDecide', false)->where('counts.evaluated', 0));
        $this->assertDatabaseCount('recommendation_decisions', 0);
        $this->assertDatabaseCount('actions', 0);
    }

    public function test_required_reason_owner_date_and_server_owned_evidence_are_validated(): void
    {
        $f = $this->recommendationFixture();
        $url = '/recommendations/'.$f['recommendation']->id.'/decide';
        $base = $this->acceptance($f['user']);
        $this->actingAs($f['user']);
        foreach (['note' => '', 'owner_id' => null, 'due_date' => '2026-11-13', 'decision' => 'complete', 'version' => 0,
            'evidence' => ['fake' => true], 'priority' => 'low', 'evaluation_status' => 'evaluated', 'forecast_id' => 1, 'rule_code' => 'R1'] as $key => $value) {
            $this->post($url, [...$base, $key => $value])->assertSessionHasErrors($key);
        }
        $outsider = User::factory()->role('facilities_personnel')->create();
        $this->post($url, [...$base, 'owner_id' => $outsider->id])->assertSessionHasErrors('owner_id');
        $outsider->buildings()->attach($f['building']);
        $f['recommendation']->update(['contains_cost_data' => true]);
        $this->post($url, [...$base, 'owner_id' => $outsider->id])->assertSessionHasErrors('owner_id');
        $this->assertDatabaseCount('actions', 0);
        $this->assertDatabaseCount('recommendation_decisions', 0);
    }

    public function test_audit_failure_rolls_back_decision_history_and_action(): void
    {
        $f = $this->recommendationFixture();
        Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $log) {
            if ($log->event === 'recommendation.decided') {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            app(RecommendationWorkflow::class)->decide($f['recommendation'], $f['user'], $this->acceptance($f['user']));
            $this->fail('An unaudited decision persisted.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
            $this->assertDatabaseCount('actions', 0);
            $this->assertDatabaseCount('recommendation_decisions', 0);
            $this->assertDatabaseHas('recommendations', ['id' => $f['recommendation']->id, 'decision' => 'pending', 'version' => 1]);
        } finally {
            Event::forget('eloquent.creating: '.AuditLog::class);
        }
    }

    public function test_view_scope_cost_privacy_and_real_empty_policy_state(): void
    {
        $this->get('/decision-support')->assertRedirect('/login');
        $viewer = User::factory()->create();
        $this->actingAs($viewer)->get('/decision-support')->assertInertia(fn ($page) => $page->component('decision-support')->where('policyAvailable', false)->where('counts.evaluated', 0)->where('recommendations.data', [])->where('owners', []));
        $f = $this->recommendationFixture();
        foreach (['admin', 'budget_officer', 'viewer'] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->get('/decision-support')->assertInertia(fn ($page) => $page->has('recommendations.data', 1)->where('recommendations.data.0.canDecide', false)->where('owners', []));
        }
        $facilities = User::factory()->role('facilities_personnel')->create();
        $this->actingAs($facilities)->get('/decision-support')->assertInertia(fn ($page) => $page->where('buildings', [])->where('recommendations.data', []));
        $this->get('/decision-support?building='.$f['building']->code)->assertNotFound();
        $facilities->buildings()->attach($f['building']);
        $this->get('/decision-support')->assertInertia(fn ($page) => $page->has('recommendations.data', 1)->where('recommendations.data.0.canDecide', false));
        foreach (['contains_cost_data' => true, 'rule_code' => 'R1'] as $key => $value) {
            $f['recommendation']->update(['contains_cost_data' => false, $key => $value]);
            $this->get('/decision-support')->assertInertia(fn ($page) => $page->where('recommendations.data', [])->where('counts.evaluated', 0));
        }
    }
}
