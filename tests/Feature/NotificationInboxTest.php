<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Services\ForecastIssuance;
use App\Services\NotificationInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Support\ForecastData;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use ForecastData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
    }

    public function test_only_proposal_recipients_receive_current_operational_notices(): void
    {
        $f = $this->forecastFixture();
        $run = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
        $pending = $this->forecastFixture(1);
        $draft = $this->draft($pending['meters'][0]->id);
        foreach (['energy_manager', 'budget_officer', 'admin', 'facilities_personnel', 'viewer'] as $role) {
            $actor = User::factory()->role($role)->create();
            $this->actingAs($actor)->get('/notifications')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertInertia(fn ($page) => $page->component('notifications')
                    ->where('metrics.verification', $role === 'energy_manager' ? 1 : 0)
                    ->where('metrics.forecasts', in_array($role, ['energy_manager', 'budget_officer'], true) ? 1 : 0));
        }
        $items = app(NotificationInbox::class)->read($f['user']);
        $this->assertSame($run->id, $items->firstWhere('kind', 'forecast')['sourceId']);
        $this->assertSame($draft->id, $items->firstWhere('kind', 'verification')['sourceId']);
        $text = json_encode($items, JSON_THROW_ON_ERROR);
        foreach ([$f['meters'][0]->meralco_account_ref, 'PRIVATE NOTE', 'kwh', 'cost_php', 'worker_result', 'uploaded_by'] as $private) {
            $this->assertStringNotContainsString($private, $text);
        }
        $this->assertSame(0, DB::table('notification_receipts')->count());
        $this->assertNull(config('recommendations.policy_fingerprint'));
    }

    public function test_personal_acknowledgments_are_idempotent_and_do_not_verify_or_mutate_sources(): void
    {
        $f = $this->forecastFixture(1);
        $draft = $this->draft($f['meters'][0]->id);
        $before = $draft->getRawOriginal();
        $actor = $f['user'];
        $key = app(NotificationInbox::class)->read($actor)->first()['key'];
        $this->actingAs($actor)->post('/notifications/read', ['keys' => [$key]])->assertSessionHasNoErrors();
        $receipt = DB::table('notification_receipts')->first();
        $this->travel(1)->hour();
        $this->post('/notifications/read', ['keys' => [$key]])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('notification_receipts')->count());
        $this->assertSame($receipt->read_at, DB::table('notification_receipts')->first()->read_at);
        $this->assertSame(1, AuditLog::where('event', 'notification.read')->count());
        $this->assertSame($before, $draft->fresh()->getRawOriginal());
        $this->get('/notifications')->assertInertia(fn ($page) => $page->where('metrics.unread', 0));
        $other = User::factory()->role('energy_manager')->create();
        $this->actingAs($other)->get('/notifications')->assertInertia(fn ($page) => $page->where('metrics.unread', 1));
    }

    public function test_corrected_version_is_new_unread_and_verified_or_removed_sources_disappear(): void
    {
        $f = $this->forecastFixture(1);
        $draft = $this->draft($f['meters'][0]->id);
        $inbox = app(NotificationInbox::class);
        $key = $inbox->read($f['user'])->first()['key'];
        $inbox->markRead($f['user'], [$key]);
        $draft->update(['version' => 2, 'kwh' => 300]);
        $new = $inbox->read($f['user'])->first();
        $this->assertNotSame($key, $new['key']);
        $this->assertNull($new['readAt']);
        $this->actingAs($f['user'])->postJson('/notifications/read', ['keys' => [$key]])->assertUnprocessable()->assertJsonValidationErrors('notifications');
        $draft->update(['verification_status' => 'verified']);
        $this->assertCount(0, $inbox->read($f['user']));
        $draft->update(['verification_status' => 'draft']);
        $f['building']->delete();
        $this->assertCount(0, $inbox->read($f['user']));
        $this->assertSame(1, DB::table('notification_receipts')->count());
    }

    public function test_forecast_notice_is_retracted_after_source_correction_or_runtime_change(): void
    {
        $f = $this->forecastFixture();
        app(ForecastIssuance::class)->issue($f['building'], $f['user']);
        $inbox = app(NotificationInbox::class);
        $key = $inbox->read($f['user'])->first()['key'];
        config(['forecasting.python' => '/changed/runtime']);
        $this->assertCount(0, $inbox->read($f['user']));
        config(['forecasting.python' => base_path('.venv/bin/python')]);
        MonthlyRecord::firstOrFail()->update(['verification_status' => 'draft', 'version' => 2]);
        $items = $inbox->read($f['user']);
        $this->assertSame(['verification'], $items->pluck('kind')->all());
        $this->actingAs($f['user'])->postJson('/notifications/read', ['keys' => [$key]])->assertUnprocessable();
        $this->assertSame(0, DB::table('notification_receipts')->count());
    }

    public function test_roles_and_forged_or_mixed_notice_keys_cannot_create_receipts(): void
    {
        $f = $this->forecastFixture(1);
        $this->draft($f['meters'][0]->id);
        $key = app(NotificationInbox::class)->read($f['user'])->first()['key'];
        foreach (['admin', 'facilities_personnel', 'viewer', 'budget_officer'] as $role) {
            $actor = User::factory()->role($role)->create();
            $response = $this->actingAs($actor)->postJson('/notifications/read', ['keys' => [$key]]);
            $role === 'budget_officer' ? $response->assertUnprocessable() : $response->assertForbidden();
        }
        $this->actingAs($f['user'])->postJson('/notifications/read', ['keys' => [$key, str_repeat('0', 64)]])->assertUnprocessable();
        $this->postJson('/notifications/read', ['keys' => [$key, $key]])->assertUnprocessable();
        $this->postJson('/notifications/read', ['keys' => []])->assertUnprocessable();
        $this->postJson('/notifications/read', ['keys' => ['not a key']])->assertUnprocessable();
        $this->postJson('/notifications/read', ['keys' => array_fill(0, 21, $key)])->assertUnprocessable();
        $this->assertSame(0, DB::table('notification_receipts')->count());
        $this->assertSame(0, AuditLog::where('event', 'notification.read')->count());
    }

    public function test_fresh_role_is_enforced_when_actor_instance_is_stale(): void
    {
        $f = $this->forecastFixture(1);
        $this->draft($f['meters'][0]->id);
        $inbox = app(NotificationInbox::class);
        $key = $inbox->read($f['user'])->first()['key'];
        User::whereKey($f['user']->id)->update(['role' => 'viewer']);
        $this->assertCount(0, $inbox->read($f['user']));
        $this->actingAs($f['user'])->postJson('/notifications/read', ['keys' => [$key]])->assertForbidden();
        $this->assertSame(0, DB::table('notification_receipts')->count());
    }

    public function test_read_filters_pagination_and_page_acknowledgments_keep_unreviewed_notices_unread(): void
    {
        $f = $this->forecastFixture(1);
        $this->draft($f['meters'][0]->id);
        for ($i = 1; $i <= 24; $i++) {
            $this->draft($f['meters'][0]->id, Carbon::parse('2020-01-01')->addMonths($i)->toDateString());
        }
        $this->actingAs($f['user'])->get('/notifications')->assertInertia(fn ($page) => $page->has('notices.data', 20)->where('notices.last_page', 2)->where('metrics.unread', 25));
        $keys = app(NotificationInbox::class)->read($f['user'])->take(20)->pluck('key')->all();
        $this->post('/notifications/read', compact('keys'))->assertSessionHasNoErrors();
        $this->get('/notifications?state=unread')->assertInertia(fn ($page) => $page->has('notices.data', 5)->where('metrics.unread', 5));
        $this->get('/notifications?state=read')->assertInertia(fn ($page) => $page->has('notices.data', 20));
        $this->getJson('/notifications?state=invalid')->assertUnprocessable();
        $this->getJson('/notifications?page=0')->assertUnprocessable();
    }

    public function test_audit_failure_rolls_back_receipts_and_guests_cannot_access(): void
    {
        $this->get('/notifications')->assertRedirect('/login');
        $this->post('/notifications/read', [])->assertRedirect('/login');
        $f = $this->forecastFixture(1);
        $this->draft($f['meters'][0]->id);
        $inbox = app(NotificationInbox::class);
        $key = $inbox->read($f['user'])->first()['key'];
        Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $entry) {
            if ($entry->event === 'notification.read') {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            $inbox->markRead($f['user'], [$key]);
            $this->fail('Receipt succeeded without audit.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditLog::class);
        }
        $this->assertSame(0, DB::table('notification_receipts')->count());
    }

    private function draft(int $meterId, string $month = '2024-01-01'): MonthlyRecord
    {
        return MonthlyRecord::create(['meter_id' => $meterId, 'month' => $month, 'kwh' => 100, 'cost_php' => 12345.67, 'notes' => 'PRIVATE NOTE', 'source' => 'manual', 'verification_status' => 'draft', 'version' => 1])->fresh();
    }
}
