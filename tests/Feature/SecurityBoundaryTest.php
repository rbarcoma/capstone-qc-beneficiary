<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Building;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SecurityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_mutations_reject_missing_and_forged_csrf_tokens_before_domain_writes(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        // Laravel normally bypasses CSRF in PHPUnit. Exercise the real middleware
        // without changing the isolated testing database or persisted config.
        $this->app['env'] = 'local';
        $token = str_repeat('a', 40);
        $this->withSession(['_token' => $token]);

        foreach ([
            ['POST', '/buildings'], ['POST', '/meters'],
            ['PATCH', '/buildings/1'], ['DELETE', '/buildings/1'],
            ['PATCH', '/meters/1'], ['DELETE', '/meters/1'],
            ['POST', '/data-management/import'], ['POST', '/monthly-records'],
            ['POST', '/monthly-records/1/verify'], ['PATCH', '/monthly-records/1/correct'],
            ['POST', '/preprocessing/build'], ['POST', '/preprocessing/weather'],
            ['POST', '/forecasting/issue'], ['POST', '/forecasting/reconcile'],
            ['POST', '/solar-scenarios/calculate'], ['POST', '/recommendations/1/decide'],
            ['PATCH', '/actions/1'], ['POST', '/notifications/read'],
            ['POST', '/reports/1/summary'],
            ['POST', '/users'], ['PATCH', '/users/1'], ['PATCH', '/users/1/status'],
            ['POST', '/users/1/temporary-password'], ['PUT', '/account/password-setup'],
        ] as [$method, $uri]) {
            $this->json($method, $uri)->assertStatus(419);
            $this->json($method, $uri, ['_token' => 'forged'])->assertStatus(419);
        }

        foreach (['buildings', 'meters', 'monthly_records', 'datasets', 'forecasts', 'recommendations', 'actions', 'notification_receipts', 'user_module_restrictions', 'audit_logs', 'jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        // Positive control: a genuine token reaches the intended authorization
        // and validation path, so the failures above are not an unreachable app.
        $this->from('/buildings-meters')->post('/buildings', ['_token' => $token, 'name' => 'Synthetic CSRF check', 'code' => 'CSRF_CHECK'])
            ->assertRedirect('/buildings-meters');
        $this->assertDatabaseHas('buildings', ['code' => 'CSRF_CHECK']);
        $this->assertNull(config('recommendations.policy_fingerprint'));
    }

    public function test_registration_cannot_assign_role_building_scope_or_verified_status(): void
    {
        Notification::fake();
        $building = Building::factory()->create();
        $this->post('/register', [
            'name' => 'Synthetic privilege check', 'email' => 'p9-registration@example.test',
            'password' => 'Password-for-testing-123!', 'password_confirmation' => 'Password-for-testing-123!',
            'role' => 'admin', 'building_ids' => [$building->id], 'email_verified_at' => now()->toISOString(),
        ])->assertRedirect('/dashboard');

        $user = User::where('email', 'p9-registration@example.test')->firstOrFail();
        $this->assertSame(UserRole::Viewer, $user->role);
        $this->assertNull($user->email_verified_at);
        $this->assertSame(0, $user->buildings()->count());
        $this->actingAs($user)->post('/buildings', ['name' => 'Denied', 'code' => 'DENIED'])->assertForbidden();
        $this->assertDatabaseMissing('buildings', ['code' => 'DENIED']);
        $this->assertNull(config('recommendations.policy_fingerprint'));
    }
}
