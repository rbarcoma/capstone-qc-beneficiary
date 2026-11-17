<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\ActionUpdate;
use App\Models\AuditLog;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Services\ActionLifecycle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Support\ActionData;
use Tests\TestCase;

class ActionTrackerTest extends TestCase
{
    use ActionData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 12:00:00', 'UTC'));
    }

    public function test_manager_records_follow_up_and_completion_without_changing_sources_or_assignment(): void
    {
        $f = $this->actionFixture();
        $action = $f['action'];
        $recommendationBefore = $f['recommendation']->getRawOriginal();
        $forecastBefore = $f['forecast']->getRawOriginal();
        $assignment = $action->only(['owner_id', 'due_date', 'title', 'recommendation_id']);
        $this->assertNull(config('recommendations.policy_fingerprint'));
        $this->actingAs($f['user'])->patch('/actions/'.$action->id, ['version' => 1, 'status' => 'open', 'outcome' => 'Inspection scheduled; no savings measured.'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('actions', ['id' => $action->id, 'version' => 2, 'status' => 'open', 'completed_at' => null]);
        $this->patch('/actions/'.$action->id, ['version' => 2, 'status' => 'completed', 'outcome' => 'Inspection completed; observations recorded.'])->assertSessionHasNoErrors();
        $current = $action->fresh();
        $this->assertSame('completed', $current->status);
        $this->assertSame(3, $current->version);
        $this->assertTrue($current->completed_at->equalTo(now()));
        $this->assertEquals($assignment, $current->only(array_keys($assignment)));
        $this->assertSame($recommendationBefore, $f['recommendation']->fresh()->getRawOriginal());
        $this->assertSame($forecastBefore, $f['forecast']->fresh()->getRawOriginal());
        $history = ActionUpdate::orderBy('id')->get();
        $this->assertSame('open', $history[0]->before['status']);
        $this->assertSame('Inspection scheduled; no savings measured.', $history[1]->before['outcome']);
        $this->assertSame('completed', $history[1]->after['status']);
        $this->assertSame([1, 2], $history->pluck('action_version')->all());
        $this->assertSame(2, AuditLog::where('event', 'action.updated')->where('forecast_id', $f['forecast']->id)->count());
        $this->get('/action-tracker')->assertInertia(fn ($page) => $page->where('metrics.tracked', 1)->where('metrics.completed', 1)->where('metrics.open', 0)->where('actions.data.0.canUpdate', false));
    }

    public function test_only_energy_managers_can_update_even_when_another_role_owns_the_action(): void
    {
        $owner = User::factory()->role('viewer')->create();
        $f = $this->actionFixture($owner);
        foreach (['admin', 'viewer', 'budget_officer', 'facilities_personnel'] as $role) {
            $actor = $role === 'viewer' ? $owner : User::factory()->role($role)->create();
            $actor->buildings()->attach($f['building']);
            $this->actingAs($actor)->get('/action-tracker')->assertInertia(fn ($page) => $page->has('actions.data', 1)->where('actions.data.0.canUpdate', false));
            $this->patch('/actions/'.$f['action']->id, ['version' => 1, 'status' => 'completed', 'outcome' => 'Not authorized.'])->assertForbidden();
            $this->assertDatabaseHas('audit_logs', ['actor_id' => $actor->id, 'event' => 'authorization.denied', 'reason' => 'PATCH actions.update']);
        }
        $this->assertDatabaseCount('action_updates', 0);
        $this->assertSame('open', $f['action']->fresh()->status);
    }

    public function test_persisted_role_revocation_is_rechecked_under_lock(): void
    {
        $f = $this->actionFixture();
        User::whereKey($f['user']->id)->update(['role' => 'viewer']);
        try {
            app(ActionLifecycle::class)->update($f['action'], $f['user'], ['version' => 1, 'status' => 'completed', 'outcome' => 'Revoked actor.']);
            $this->fail('A revoked manager updated an action.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('action_updates', 0);
            $this->assertSame(1, $f['action']->fresh()->version);
        }
    }

    public function test_stale_versions_retries_and_completed_reopening_are_rejected(): void
    {
        $f = $this->actionFixture();
        $url = '/actions/'.$f['action']->id;
        $this->actingAs($f['user'])->patch($url, ['version' => 1, 'status' => 'open', 'outcome' => 'First review.'])->assertSessionHasNoErrors();
        $this->patch($url, ['version' => 1, 'status' => 'completed', 'outcome' => 'Stale simultaneous review.'])->assertSessionHasErrors('action');
        $this->patch($url, ['version' => 2, 'status' => 'completed', 'outcome' => 'Final outcome.'])->assertSessionHasNoErrors();
        $before = $f['action']->fresh()->getRawOriginal();
        $this->patch($url, ['version' => 2, 'status' => 'completed', 'outcome' => 'Retry.'])->assertSessionHasErrors('action');
        $this->patch($url, ['version' => 3, 'status' => 'open', 'outcome' => 'Reopen.'])->assertSessionHasErrors('action');
        $this->patch($url, ['version' => 3, 'status' => 'completed', 'outcome' => 'Overwrite completion.'])->assertSessionHasErrors('action');
        $this->assertDatabaseCount('action_updates', 2);
        $this->assertSame($before, $f['action']->fresh()->getRawOriginal());
        $this->assertDatabaseCount('actions', 1);
    }

    public function test_validation_protects_assignment_sources_and_server_completion_time(): void
    {
        $f = $this->actionFixture();
        $base = ['version' => 1, 'status' => 'completed', 'outcome' => 'Reported observations.'];
        $this->actingAs($f['user']);
        foreach (['outcome' => '   ', 'status' => 'in_progress', 'version' => 0, 'owner_id' => $f['user']->id, 'due_date' => '2027-01-01',
            'title' => 'Changed', 'recommendation_id' => $f['recommendation']->id, 'forecast_id' => $f['forecast']->id, 'completed_at' => '2020-01-01'] as $field => $value) {
            $this->patch('/actions/'.$f['action']->id, [...$base, $field => $value])->assertSessionHasErrors($field);
        }
        $this->patch('/actions/'.$f['action']->id, [...$base, 'outcome' => str_repeat('a', 4001)])->assertSessionHasErrors('outcome');
        $this->postJson('/actions', $base)->assertNotFound();
        $this->deleteJson('/actions/'.$f['action']->id)->assertMethodNotAllowed();
        $this->assertDatabaseCount('action_updates', 0);
    }

    public function test_retained_actions_can_be_followed_after_source_correction_without_enabling_policy(): void
    {
        $f = $this->actionFixture();
        MonthlyRecord::firstOrFail()->update(['verification_status' => 'draft', 'version' => 2]);
        $this->actingAs($f['user'])->get('/action-tracker')->assertInertia(fn ($page) => $page->has('actions.data', 1)->where('actions.data.0.canUpdate', true));
        $this->patch('/actions/'.$f['action']->id, ['version' => 1, 'status' => 'completed', 'outcome' => 'Follow-through of previously accepted work; not new advice.'])->assertSessionHasNoErrors();
        $this->assertNull(config('recommendations.policy_fingerprint'));
        $this->assertDatabaseCount('forecasts', 1);
        $this->assertDatabaseCount('recommendations', 1);
        $this->assertDatabaseCount('actions', 1);
        $this->assertSame('accepted', $f['recommendation']->fresh()->decision);
    }

    public function test_missing_retained_acceptance_prevents_updates(): void
    {
        $f = $this->actionFixture();
        // Model a damaged fixture ledger; production has no deletion endpoint.
        $f['recommendation']->decisions()->delete();
        $this->actingAs($f['user'])->get('/action-tracker')->assertInertia(fn ($page) => $page->where('actions.data.0.canUpdate', false));
        $this->patch('/actions/'.$f['action']->id, ['version' => 1, 'status' => 'completed', 'outcome' => 'No accepted ledger.'])->assertSessionHasErrors('action');
        $this->assertDatabaseCount('action_updates', 0);
        $f['recommendation']->update(['decision' => 'pending']);
        $this->get('/action-tracker')->assertInertia(fn ($page) => $page->where('actions.data', []));
        $this->getJson('/actions/'.$f['action']->id.'/history')->assertForbidden();
    }

    public function test_audit_failure_rolls_back_action_and_history(): void
    {
        $f = $this->actionFixture();
        $before = $f['action']->getRawOriginal();
        Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $log) {
            if ($log->event === 'action.updated') {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            app(ActionLifecycle::class)->update($f['action'], $f['user'], ['version' => 1, 'status' => 'completed', 'outcome' => 'Cannot persist without audit.']);
            $this->fail('An unaudited action change persisted.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
            $this->assertSame($before, $f['action']->fresh()->getRawOriginal());
            $this->assertDatabaseCount('action_updates', 0);
        } finally {
            Event::forget('eloquent.creating: '.AuditLog::class);
        }
    }

    public function test_facilities_scope_cost_privacy_and_history_authorization_are_enforced(): void
    {
        $f = $this->actionFixture();
        $other = $this->actionFixture();
        $facilities = User::factory()->role('facilities_personnel')->create();
        $facilities->buildings()->attach($f['building']);
        $this->actingAs($facilities)->get('/action-tracker')->assertInertia(fn ($page) => $page->has('actions.data', 1)->where('metrics.tracked', 1)->has('buildings', 1));
        $this->getJson('/actions/'.$other['action']->id.'/history')->assertForbidden();
        $this->getJson('/action-tracker?building='.$other['building']->code)->assertUnprocessable()->assertJsonValidationErrors('building');
        $this->getJson('/actions/'.$f['action']->id.'/history')->assertOk()->assertJsonPath('data', []);
        foreach (['contains_cost_data' => true, 'rule_code' => 'R1'] as $key => $value) {
            $f['recommendation']->update(['contains_cost_data' => false, $key => $value]);
            $this->get('/action-tracker')->assertInertia(fn ($page) => $page->where('actions.data', [])->where('metrics.tracked', 0));
            $this->getJson('/actions/'.$f['action']->id.'/history')->assertForbidden();
        }
    }

    public function test_history_paginates_retained_outcomes_and_preserves_removed_actor_attribution(): void
    {
        $f = $this->actionFixture();
        $reviewer = User::factory()->role('energy_manager')->create();
        for ($i = 1; $i <= 12; $i++) {
            app(ActionLifecycle::class)->update($f['action'], $reviewer, ['version' => $i, 'status' => 'open', 'outcome' => 'Review '.$i]);
        }
        $reviewer->delete();
        $this->actingAs($f['user'])->getJson('/actions/'.$f['action']->id.'/history')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(10, 'data')->assertJsonPath('data.0.version', 12)->assertJsonPath('data.0.before.outcome', 'Review 11')
            ->assertJsonPath('data.0.after.outcome', 'Review 12')->assertJsonPath('data.0.actorId', null)->assertJsonPath('data.0.actorName', 'Former user');
        $this->getJson('/actions/'.$f['action']->id.'/history?page=2')->assertJsonCount(2, 'data')->assertJsonPath('data.1.version', 1);
    }

    public function test_empty_states_guests_and_invalid_filters(): void
    {
        $this->get('/action-tracker')->assertRedirect('/login');
        $this->getJson('/actions/999/history')->assertUnauthorized();
        $this->actingAs(User::factory()->role('facilities_personnel')->create())->get('/action-tracker')->assertInertia(fn ($page) => $page->component('action-tracker')->where('buildings', [])->where('actions.data', [])->where('metrics.tracked', 0));
        foreach (['status' => 'cancelled', 'building' => ['HIGHRISE'], 'page' => 0] as $key => $value) {
            $this->getJson('/action-tracker?'.http_build_query([$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
        }
    }

    public function test_list_paginates_and_preserves_status_and_building_filters(): void
    {
        $f = $this->actionFixture();
        for ($i = 0; $i < 20; $i++) {
            $recommendation = $f['recommendation']->replicate();
            $recommendation->policy_fingerprint = hash('sha256', 'pagination fixture '.$i);
            $recommendation->save();
            Action::create(['recommendation_id' => $recommendation->id, 'owner_id' => $f['user']->id, 'title' => 'Synthetic pagination action '.$i, 'due_date' => '2026-12-01', 'status' => 'open']);
        }
        $query = '?status=open&building='.$f['building']->code;
        $this->actingAs($f['user'])->get('/action-tracker'.$query)->assertInertia(fn ($page) => $page->has('actions.data', 20)->where('metrics.tracked', 21)
            ->where('actions.next_page_url', fn ($url) => str_contains($url, 'status=open') && str_contains($url, 'building='.$f['building']->code) && str_contains($url, 'page=2')));
        $this->get('/action-tracker'.$query.'&page=2')->assertInertia(fn ($page) => $page->has('actions.data', 1)->where('actions.data.0.id', $f['action']->id));
        $this->get('/action-tracker?status=completed')->assertInertia(fn ($page) => $page->where('actions.data', [])->where('metrics.tracked', 0));
    }
}
