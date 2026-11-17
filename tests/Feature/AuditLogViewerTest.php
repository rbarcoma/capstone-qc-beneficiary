<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuditLogViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_view_list_and_details_and_denials_are_recorded(): void
    {
        $entry = AuditLog::create(['event' => 'weather.synced', 'after' => ['created' => 2]]);
        $this->get('/audit-logs')->assertRedirect('/login');
        $this->getJson('/audit-logs/'.$entry->id)->assertUnauthorized();
        foreach (['energy_manager', 'facilities_personnel', 'budget_officer', 'viewer'] as $role) {
            $user = User::factory()->role($role)->create();
            $this->assertFalse(Gate::forUser($user)->allows('viewAny', AuditLog::class));
            $this->assertFalse(Gate::forUser($user)->allows('view', $entry));
            $this->actingAs($user)->get('/audit-logs')->assertForbidden();
            $this->getJson('/audit-logs/'.$entry->id)->assertForbidden();
            $this->assertSame(2, AuditLog::where('actor_id', $user->id)->where('event', 'authorization.denied')->count());
            $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'event' => 'authorization.denied', 'reason' => 'GET audit-logs.show']);
        }
        $this->actingAs(User::factory()->admin()->create())->get('/audit-logs')->assertOk();
        $this->getJson('/audit-logs/'.$entry->id)->assertOk()->assertJsonPath('after.created', 2);
    }

    public function test_list_projects_real_filtered_counts_without_snapshot_or_account_attributes(): void
    {
        $admin = User::factory()->admin()->create();
        $actor = User::factory()->create(['name' => 'Named auditor fixture', 'email' => 'actor-private@example.test', 'password' => 'private-password']);
        $entry = AuditLog::create(['actor_id' => $actor->id, 'event' => 'monthly_record.corrected', 'reason' => 'Private correction context', 'before' => ['notes' => 'snapshot-only-marker'], 'after' => ['kwh' => '2.000']]);
        AuditLog::create(['event' => 'weather.synced']);
        $before = AuditLog::count();
        $this->actingAs($admin)->get('/audit-logs')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn ($page) => $page->component('audit-logs')->where('metrics.entries', 2)->where('metrics.corrections', 1)
                ->has('entries.data', 2)->where('entries.data.1.id', $entry->id)->where('entries.data.1.actor.name', $actor->name)
                ->missing('entries.data.1.before')->missing('entries.data.1.after')->missing('entries.data.1.reason')
                ->where('actors.0', ['id' => $actor->id, 'name' => $actor->name]))
            ->assertDontSee('snapshot-only-marker')->assertDontSee('Private correction context')->assertDontSee('actor-private@example.test')->assertDontSee($actor->password);
        $this->get('/audit-logs?event=monthly_record.corrected&actor='.$actor->id)->assertInertia(fn ($page) => $page->where('metrics.entries', 1)->where('metrics.corrections', 1)->has('entries.data', 1)->where('filters.actor', (string) $actor->id));
        $this->assertSame($before, AuditLog::count());
        $this->assertNull(config('recommendations.policy_fingerprint'));
    }

    public function test_details_return_exact_stored_evidence_and_never_modify_it(): void
    {
        $entry = AuditLog::create(['event' => 'recommendation.decided', 'reason' => 'Synthetic historical context', 'before' => ['decision' => 'pending', 'evidence' => ['kwh' => '420.000']],
            'after' => ['recommendation' => ['decision' => 'accepted'], 'action' => ['owner_id' => 123]]])->fresh();
        $before = $entry->getRawOriginal();
        $this->actingAs(User::factory()->admin()->create())->getJson('/audit-logs/'.$entry->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['id' => $entry->id, 'event' => $entry->event, 'actor' => null, 'createdAt' => $entry->created_at->toIso8601String(),
                'monthlyRecordId' => null, 'forecastId' => null, 'reason' => $entry->reason, 'before' => $entry->before, 'after' => $entry->after]);
        $this->assertSame($before, $entry->fresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('recommendations', 0);
        $this->assertDatabaseCount('actions', 0);
        $this->getJson('/audit-logs/999999')->assertNotFound();
    }

    public function test_dates_are_inclusive_manila_calendar_boundaries_and_filters_combine(): void
    {
        $actor = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $make = fn (string $date, ?int $actorId = null, string $event = 'monthly_record.corrected') => AuditLog::forceCreate(['actor_id' => $actorId, 'event' => $event, 'created_at' => $date]);
        $make('2026-11-13 15:59:59', $actor->id); // Nov 13 local: excluded.
        $start = $make('2026-11-13 16:00:00', $actor->id);
        $end = $make('2026-11-14 15:59:59', $actor->id);
        $make('2026-11-14 16:00:00', $actor->id); // Nov 15 local: excluded.
        $make('2026-11-14 01:00:00'); // Actor filter excludes.
        $make('2026-11-14 02:00:00', $actor->id, 'weather.synced'); // Event filter excludes.
        $this->actingAs($admin)->get('/audit-logs?event=monthly_record.corrected&actor='.$actor->id.'&from=2026-11-14&to=2026-11-14')
            ->assertInertia(fn ($page) => $page->where('metrics.entries', 2)->where('metrics.corrections', 2)->where('entries.data.0.id', $end->id)->where('entries.data.1.id', $start->id));
        $this->get('/audit-logs?from=2026-11-15')->assertInertia(fn ($page) => $page->where('metrics.entries', 1));
        $this->get('/audit-logs?to=2026-11-13')->assertInertia(fn ($page) => $page->where('metrics.entries', 1));
    }

    public function test_pagination_uses_stable_time_then_id_order_and_preserves_filters(): void
    {
        $admin = User::factory()->admin()->create();
        $ids = [];
        for ($i = 0; $i < 27; $i++) {
            $ids[] = AuditLog::forceCreate(['event' => 'weather.synced', 'created_at' => '2026-11-14 01:00:00'])->id;
        }
        // Newer insertion but older event time must not displace the newer events.
        AuditLog::forceCreate(['event' => 'weather.synced', 'created_at' => '2026-11-13 01:00:00']);
        $this->actingAs($admin)->get('/audit-logs?event=weather.synced&from=2026-11-14')->assertInertia(fn ($page) => $page->has('entries.data', 25)
            ->where('entries.data.0.id', $ids[26])->where('entries.data.24.id', $ids[2])->where('metrics.entries', 27)
            ->where('entries.next_page_url', fn ($url) => str_contains($url, 'event=weather.synced') && str_contains($url, 'from=2026-11-14') && str_contains($url, 'page=2')));
        $this->get('/audit-logs?event=weather.synced&from=2026-11-14&page=2')->assertInertia(fn ($page) => $page->has('entries.data', 2)->where('entries.data.0.id', $ids[1])->where('entries.data.1.id', $ids[0]));
    }

    public function test_system_and_removed_actors_remain_visible_without_fabricated_attribution(): void
    {
        $actor = User::factory()->create();
        $entry = AuditLog::create(['actor_id' => $actor->id, 'event' => 'monthly_record.corrected']);
        $actor->delete();
        AuditLog::create(['event' => 'weather.synced']);
        $this->actingAs(User::factory()->admin()->create())->get('/audit-logs?actor=unattributed')->assertInertia(fn ($page) => $page->where('metrics.entries', 2)->where('actors', [])->where('entries.data.0.actor', null)->where('entries.data.1.actor', null));
        $this->getJson('/audit-logs/'.$entry->id)->assertJsonPath('actor', null);
    }

    public function test_empty_no_match_and_invalid_filters_are_handled_explicitly(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/audit-logs')->assertInertia(fn ($page) => $page->where('entries.data', [])->where('metrics.entries', 0)->where('metrics.corrections', 0)->where('eventOptions', [])->where('actors', []));
        AuditLog::create(['event' => 'weather.synced']);
        $this->get('/audit-logs?event=forecast.issued')->assertInertia(fn ($page) => $page->where('entries.data', [])->where('metrics.entries', 0));
        foreach (['from' => '2026-02-30', 'to' => '1899-12-31', 'event' => ['weather.synced'], 'actor' => '0 OR 1=1', 'page' => -1] as $key => $value) {
            $this->getJson('/audit-logs?'.http_build_query([$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->getJson('/audit-logs?from=2026-11-15&to=2026-11-14')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_audit_viewer_has_no_mutation_endpoint(): void
    {
        $entry = AuditLog::create(['event' => 'weather.synced', 'after' => ['created' => 1]])->fresh();
        $before = $entry->getRawOriginal();
        $this->actingAs(User::factory()->admin()->create())->patchJson('/audit-logs/'.$entry->id, ['event' => 'rewritten'])->assertMethodNotAllowed();
        $this->deleteJson('/audit-logs/'.$entry->id)->assertMethodNotAllowed();
        $this->postJson('/audit-logs', ['event' => 'forged'])->assertMethodNotAllowed();
        $this->assertSame($before, $entry->fresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_logs', 1);
    }
}
