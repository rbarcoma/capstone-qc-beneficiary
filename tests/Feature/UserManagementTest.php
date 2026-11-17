<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Action;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\ModuleAccess;
use App\Services\ReportDownload;
use App\Services\ReportRenderer;
use App\Services\ReportSnapshot;
use App\Services\UserManagement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(string $role = 'viewer'): array
    {
        return ['name' => 'Account fixture', 'email' => 'new-account@example.test', 'role' => $role,
            'building_ids' => [], 'restricted_modules' => [], 'reason' => 'Test account setup'];
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);

        return $admin;
    }

    public function test_only_admin_can_read_and_manage_accounts_including_direct_json_routes(): void
    {
        $subject = User::factory()->create();
        $this->get('/users-access')->assertRedirect('/login');
        $this->getJson('/users/'.$subject->id)->assertUnauthorized();
        foreach (['energy_manager', 'facilities_personnel', 'budget_officer', 'viewer'] as $role) {
            $actor = User::factory()->role($role)->create();
            $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => time()]);
            $this->get('/users-access')->assertForbidden();
            $this->getJson('/users/'.$subject->id)->assertForbidden();
            $this->getJson('/users/999999999')->assertForbidden();
            $this->postJson('/users', $this->payload())->assertForbidden();
            $this->patchJson('/users/'.$subject->id, $this->payload())->assertForbidden();
            $this->patchJson('/users/'.$subject->id.'/status', ['is_active' => false, 'version' => 1, 'reason' => 'No privilege'])->assertForbidden();
            $this->postJson('/users/'.$subject->id.'/temporary-password', ['version' => 1, 'reason' => 'No privilege'])->assertForbidden();
        }
        $this->assertSame('viewer', $subject->fresh()->role->value);
        $this->assertTrue($subject->fresh()->is_active);
        $this->assertDatabaseMissing('users', ['email' => 'new-account@example.test']);
        $this->assertDatabaseCount('user_module_restrictions', 0);
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('create', User::class));
    }

    public function test_recent_password_confirmation_is_required_without_losing_or_writing_request_data(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->postJson('/users', $this->payload())->assertStatus(423);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->postJson(route('password.confirm.store'), ['password' => 'password'])->assertSuccessful();
        $this->postJson('/users', $this->payload())->assertCreated();
    }

    public function test_creation_returns_a_single_no_store_credential_and_never_persists_plaintext_in_audit_or_flash(): void
    {
        $this->admin();
        $building = Building::factory()->create();
        $data = $this->payload('facilities_personnel');
        $data['email'] = 'NEW-ACCOUNT@EXAMPLE.TEST';
        $data['building_ids'] = [$building->id];
        $data['restricted_modules'] = ['reports'];
        $response = $this->postJson('/users', $data)->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $password = $response->json('temporaryPassword');
        $subject = User::findOrFail($response->json('user.id'));
        $this->assertSame(24, strlen($password));
        $this->assertTrue(Hash::check($password, $subject->password));
        $this->assertTrue($subject->must_change_password);
        $this->assertTrue($subject->temporary_password_expires_at->isFuture());
        $this->assertNull($subject->email_verified_at);
        $this->assertSame([$building->id], $subject->allowedBuildingIds());
        $this->assertDatabaseHas('user_module_restrictions', ['user_id' => $subject->id, 'module_key' => 'reports']);
        $entry = AuditLog::where('event', 'user.created')->firstOrFail();
        $this->assertSame($subject->id, $entry->subject_user_id);
        $this->assertStringNotContainsString($password, json_encode($entry->toArray()));
        $this->assertStringNotContainsString($subject->password, json_encode($entry->toArray()));
        $this->assertStringNotContainsString($password, json_encode(session()->all()));
        $this->getJson('/users/'.$subject->id)->assertOk()->assertJsonMissingPath('temporaryPassword')->assertJsonMissingPath('user.password');
        $this->deleteJson('/users/'.$subject->id)->assertMethodNotAllowed();
    }

    public function test_validation_rejects_forged_metadata_unknown_modules_and_essential_restrictions_atomically(): void
    {
        $this->admin();
        $cases = [
            ['name' => ''], ['role' => 'super_admin'], ['restricted_modules' => ['made_up']],
            ['restricted_modules' => ['dashboard']], ['role' => 'admin', 'restricted_modules' => ['users_access']],
            ['password' => 'UserChosenCredential'], ['email_verified_at' => now()->toDateTimeString()],
            ['building_ids' => [999999999]],
        ];
        foreach ($cases as $changes) {
            $this->postJson('/users', array_replace($this->payload(), $changes))->assertUnprocessable();
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('user_module_restrictions', 0);
    }

    public function test_editing_transfers_explicit_facilities_scope_restricts_modules_revokes_sessions_and_preserves_password(): void
    {
        $this->admin();
        $old = Building::factory()->create();
        $next = Building::factory()->create();
        $subject = User::factory()->role('facilities_personnel')->create();
        $subject->buildings()->attach($old);
        $hash = $subject->password;
        DB::table('sessions')->insert(['id' => 'subject-session', 'user_id' => $subject->id, 'payload' => '', 'last_activity' => time()]);
        $data = array_replace($this->payload('facilities_personnel'), ['email' => $subject->email, 'name' => $subject->name,
            'building_ids' => [$next->id], 'restricted_modules' => ['reports'], 'version' => 1, 'reason' => 'Transferred building duties']);
        $this->patchJson('/users/'.$subject->id, $data)->assertOk();
        $subject->refresh();
        $this->assertSame([$next->id], $subject->allowedBuildingIds());
        $this->assertFalse($subject->hasBuildingAccess($old));
        $this->assertFalse($subject->canAccessModule('reports'));
        $this->assertFalse($subject->canAccessModule('verification_queue'));
        $this->assertSame($hash, $subject->password);
        $this->assertSame(2, $subject->version);
        $this->assertSame(2, $subject->access_version);
        $this->assertDatabaseMissing('sessions', ['id' => 'subject-session']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.updated', 'subject_user_id' => $subject->id]);
        $this->patchJson('/users/'.$subject->id, $data)->assertUnprocessable()->assertJsonValidationErrors('version');
    }

    public function test_self_access_changes_and_last_usable_admin_deletion_are_blocked_even_with_pending_backup(): void
    {
        $admin = $this->admin();
        User::factory()->admin()->create(['must_change_password' => true, 'temporary_password_expires_at' => now()->addHour()]);
        $this->patchJson('/users/'.$admin->id, array_replace($this->payload('viewer'), ['email' => $admin->email, 'version' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->patchJson('/users/'.$admin->id.'/status', ['is_active' => false, 'version' => 1, 'reason' => 'Self'])->assertForbidden();
        $this->deleteJson(route('profile.destroy'), ['password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }

    public function test_deactivation_and_reactivation_do_not_restore_old_sessions_or_change_access_assignments(): void
    {
        $admin = $this->admin();
        $subject = User::factory()->create();
        $subject->moduleRestrictions()->create(['module_key' => 'reports']);
        $hash = $subject->password;
        $this->patchJson('/users/'.$subject->id.'/status', ['is_active' => false, 'version' => 1, 'reason' => 'Transfer'])->assertOk();
        $this->assertFalse($subject->fresh()->is_active);
        $this->post('/logout');
        $this->post('/login', ['email' => $subject->email, 'password' => 'password'])->assertSessionHasErrors();
        $this->assertGuest();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time(), 'auth.access_version' => 1]);
        $this->patchJson('/users/'.$subject->id.'/status', ['is_active' => true, 'version' => 2, 'reason' => 'Return'])->assertOk();
        $this->assertSame($hash, $subject->fresh()->password);
        $this->assertDatabaseHas('user_module_restrictions', ['user_id' => $subject->id, 'module_key' => 'reports']);
        $this->actingAs($subject->fresh())->withSession(['auth.access_version' => 1])->getJson('/dashboard')->assertUnauthorized();
        $this->post('/login', ['email' => $subject->email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->get('/dashboard')->assertOk();
        $this->get('/reports')->assertForbidden();
    }

    public function test_read_filters_pagination_and_detail_history_are_private_allowlisted_and_real(): void
    {
        $this->admin();
        User::factory()->count(11)->role('energy_manager')->create();
        $subject = User::factory()->role('facilities_personnel')->create(['name' => 'Find this fixture']);
        $this->get('/users-access')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn ($page) => $page->has('users.data', 10)->where('counts.total', 13)->has('roles', 4)->has('matrix', 7)->has('moduleCatalogue', 19));
        $this->get('/users-access?search=Find%20this&role=facilities_personnel&status=active')
            ->assertInertia(fn ($page) => $page->has('users.data', 1)->where('users.data.0.id', $subject->id)->missing('users.data.0.password')->missing('users.data.0.remember_token'));
        $this->getJson('/users/'.$subject->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('history.total', 0);
        $this->get('/users-access?search=not-present')->assertInertia(fn ($page) => $page->has('users.data', 0));
    }

    public function test_audit_failure_rolls_back_account_assignment_and_restrictions(): void
    {
        $this->admin();
        AuditLog::creating(fn () => throw new RuntimeException('Synthetic audit failure'));
        try {
            $this->withoutExceptionHandling();
            $this->postJson('/users', $this->payload());
            $this->fail('An audit failure must prevent creation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_module_restrictions', 0);
        $this->assertDatabaseCount('user_building', 0);
    }

    public function test_restricted_modules_are_denied_on_auxiliary_and_mutation_routes_not_just_sidebar(): void
    {
        $actor = User::factory()->role('energy_manager')->create();
        $building = Building::factory()->create();
        $meter = Meter::factory()->create(['building_id' => $building->id]);
        $record = MonthlyRecord::create(['meter_id' => $meter->id, 'month' => '2026-10-01', 'kwh' => 100, 'source' => 'synthetic test', 'uploaded_by' => $actor->id]);
        $routes = [
            'data_management' => ['GET', '/data-management/template'], 'verification_queue' => ['POST', '/monthly-records/'.$record->id.'/verify'],
            'preprocessing' => ['POST', '/preprocessing/weather'], 'forecasting' => ['POST', '/forecasting/issue'],
            'model_evaluation' => ['GET', '/model-evaluation'], 'reports' => ['POST', '/reports/'.$building->id.'/summary'],
            'notifications' => ['POST', '/notifications/read'], 'action_tracker' => ['GET', '/action-tracker'],
            'decision_support' => ['GET', '/decision-support'], 'solar_scenarios' => ['POST', '/solar-scenarios/calculate'],
        ];
        $this->actingAs($actor);
        foreach ($routes as $module => [$method, $url]) {
            $actor->moduleRestrictions()->create(['module_key' => $module]);
            $this->json($method, $url)->assertForbidden();
        }
        $this->assertFalse(Gate::forUser($actor)->allows('decide', new Recommendation));
        $this->assertFalse(Gate::forUser($actor)->allows('update', new Action));
        $this->get('/dashboard')->assertOk();
        $this->get('/settings/profile')->assertOk();
        foreach (array_keys(ModuleAccess::catalogue()) as $module) {
            $viewer = User::factory()->create();
            if (! ModuleAccess::roleAllows(UserRole::Viewer, $module)) {
                $this->assertFalse($viewer->canAccessModule($module));
            }
        }
    }

    public function test_service_rechecks_revoked_admin_and_final_state_retries(): void
    {
        $admin = $this->admin();
        DB::table('users')->where('id', $admin->id)->update(['role' => 'viewer']);
        try {
            app(UserManagement::class)->create($admin, $this->payload());
            $this->fail('A stale actor must not create an account.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('users', 1);
        }
    }

    public function test_role_changes_retain_dormant_assignments_but_facilities_scope_is_always_explicit(): void
    {
        $this->admin();
        $building = Building::factory()->create();
        $subject = User::factory()->role('facilities_personnel')->create();
        $subject->buildings()->attach($building);
        $data = array_replace($this->payload('energy_manager'), ['email' => $subject->email, 'version' => 1, 'restricted_modules' => ['reports']]);
        $this->patchJson('/users/'.$subject->id, $data)->assertOk();
        $subject->refresh();
        $this->assertSame(UserRole::EnergyManager, $subject->role);
        $this->assertNull($subject->allowedBuildingIds());
        $this->assertSame([$building->id], $subject->buildings()->pluck('buildings.id')->all());
        $this->assertTrue($subject->canAccessModule('verification_queue'));
        $this->assertFalse($subject->canAccessModule('reports'));
        $this->assertFalse($subject->canAccessModule('users_access'));
        $this->patchJson('/users/'.$subject->id, array_replace($data, ['role' => 'facilities_personnel', 'version' => 2, 'building_ids' => []]))->assertOk();
        $this->assertSame([], $subject->fresh()->allowedBuildingIds());
    }

    public function test_two_admin_changes_cannot_use_a_deactivated_stale_actor_to_remove_the_remaining_admin(): void
    {
        $first = $this->admin();
        $second = User::factory()->admin()->create();
        $this->patchJson('/users/'.$second->id.'/status', ['is_active' => false, 'version' => 1, 'reason' => 'Remove access'])->assertOk();
        try {
            app(UserManagement::class)->changeStatus($second, $first, ['is_active' => false, 'version' => 1, 'reason' => 'Stale retry']);
            $this->fail('A deactivated stale actor must not remove another Admin.');
        } catch (AuthorizationException) {
            $this->assertTrue($first->fresh()->is_active);
            $this->assertSame(1, User::where('role', 'admin')->where('is_active', true)->count());
        }
    }

    public function test_report_download_rechecks_module_restriction_after_rendering_before_audit_or_release(): void
    {
        $actor = User::factory()->role('energy_manager')->create();
        $building = Building::factory()->create();
        $reader = app(ReportSnapshot::class);
        $fingerprint = $reader->fingerprint($reader->read($building, $actor));
        $this->mock(ReportRenderer::class)->shouldReceive('render')->once()->andReturnUsing(function () use ($actor) {
            $actor->moduleRestrictions()->create(['module_key' => 'reports']);

            return 'synthetic rendered bytes';
        });
        try {
            app(ReportDownload::class)->generate($building, $actor, 'summary', $fingerprint);
            $this->fail('Revoked report access must not release a download.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertDatabaseMissing('audit_logs', ['event' => 'report.downloaded']);
        }
    }
}
