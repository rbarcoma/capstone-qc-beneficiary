<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\User;
use App\Models\UserModuleRestriction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserManagementFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertSame('mysql', DB::connection()->getDriverName());
    }

    public function test_database_defaults_preserve_every_role_and_building_scope_without_restriction_rows(): void
    {
        $building = Building::factory()->create();
        $other = Building::factory()->create();

        foreach (UserRole::cases() as $role) {
            $user = User::factory()->role($role)->create()->fresh();

            $this->assertTrue($user->is_active);
            $this->assertSame(1, $user->version);
            $this->assertSame(1, $user->access_version);
            $this->assertNull($user->deactivated_at);
            $this->assertSame($role, $user->role);
            $this->assertTrue($user->hasRole($role));
            $this->assertSame(0, $user->moduleRestrictions()->count());

            if ($role === UserRole::FacilitiesPersonnel) {
                $this->assertSame([], $user->allowedBuildingIds());
                $this->assertFalse($user->hasBuildingAccess($building));
                $user->buildings()->attach($building);
                $this->assertSame([$building->id], $user->allowedBuildingIds());
                $this->assertTrue($user->hasBuildingAccess($building));
                $this->assertFalse($user->hasBuildingAccess($other));
            } else {
                $this->assertNull($user->allowedBuildingIds());
                $this->assertTrue($user->hasBuildingAccess($building));
                $this->assertTrue($user->hasBuildingAccess($other));
            }
        }

        $this->assertDatabaseCount('user_module_restrictions', 0);
    }

    public function test_restrictions_are_user_scoped_storage_without_mutating_accounts_or_assignments(): void
    {
        $user = User::factory()->role(UserRole::FacilitiesPersonnel)->create()->fresh();
        $other = User::factory()->role(UserRole::Viewer)->create()->fresh();
        $building = Building::factory()->create();
        $user->buildings()->attach($building);
        $before = $user->getRawOriginal();
        $assignment = DB::table('user_building')->where('user_id', $user->id)->first();

        $restriction = $user->moduleRestrictions()->create(['module_key' => 'reports']);
        $other->moduleRestrictions()->create(['module_key' => 'reports']);

        $this->assertSame($user->id, $restriction->user->id);
        $this->assertSame(['reports'], $user->moduleRestrictions()->pluck('module_key')->all());
        $this->assertSame(1, $other->moduleRestrictions()->count());
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertEquals($assignment, DB::table('user_building')->where('user_id', $user->id)->first());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_duplicate_restrictions_for_the_same_user_and_module_are_rejected(): void
    {
        $user = User::factory()->create();
        $user->moduleRestrictions()->create(['module_key' => 'reports']);

        $this->expectException(QueryException::class);
        $user->moduleRestrictions()->create(['module_key' => 'reports']);
    }

    public function test_restrictions_require_an_existing_user(): void
    {
        $this->expectException(QueryException::class);
        UserModuleRestriction::create(['user_id' => 999999999, 'module_key' => 'reports']);
    }

    public function test_foundation_metadata_is_not_mass_assignable(): void
    {
        $user = User::factory()->create()->fresh();
        $user->fill(['is_active' => false, 'version' => 99, 'access_version' => 99, 'deactivated_at' => now()])->save();

        $this->assertTrue($user->fresh()->is_active);
        $this->assertSame(1, $user->fresh()->version);
        $this->assertSame(1, $user->fresh()->access_version);
        $this->assertNull($user->fresh()->deactivated_at);
    }

    public function test_metadata_can_be_stored_explicitly_without_lifecycle_side_effects(): void
    {
        $user = User::factory()->create()->fresh();
        $before = $user->getRawOriginal();
        $at = now()->startOfSecond();

        $user->forceFill(['is_active' => false, 'version' => 2, 'access_version' => 3, 'deactivated_at' => $at])->save();
        $user->refresh();

        $this->assertFalse($user->is_active);
        $this->assertSame(2, $user->version);
        $this->assertSame(3, $user->access_version);
        $this->assertTrue($at->equalTo($user->deactivated_at));
        foreach (['name', 'email', 'password', 'role', 'remember_token', 'email_verified_at'] as $field) {
            $this->assertSame($before[$field], $user->getRawOriginal($field));
        }
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('user_module_restrictions', 0);
    }

    public function test_subject_identifier_and_snapshots_survive_the_existing_self_deletion_flow(): void
    {
        $actor = User::factory()->admin()->create();
        $subject = User::factory()->create();
        $other = User::factory()->create();
        $building = Building::factory()->create();
        $subject->buildings()->attach($building);
        $subject->moduleRestrictions()->create(['module_key' => 'reports']);
        $other->moduleRestrictions()->create(['module_key' => 'reports']);
        $entry = AuditLog::create([
            'actor_id' => $actor->id, 'subject_user_id' => $subject->id,
            'event' => 'fixture.user.access_changed',
            'before' => ['subject' => ['id' => $subject->id, 'role' => 'viewer'], 'restricted_modules' => []],
            'after' => ['subject' => ['id' => $subject->id, 'role' => 'viewer'], 'restricted_modules' => ['reports']],
        ])->fresh();
        $before = $entry->getRawOriginal();

        $this->assertSame($subject->id, $entry->subjectUser->id);
        $this->actingAs($subject)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $subject->id]);
        $this->assertDatabaseMissing('user_module_restrictions', ['user_id' => $subject->id]);
        $this->assertDatabaseMissing('user_building', ['user_id' => $subject->id]);
        $this->assertDatabaseHas('user_module_restrictions', ['user_id' => $other->id, 'module_key' => 'reports']);
        $entry->refresh();
        $this->assertSame($before, $entry->getRawOriginal());
        $this->assertSame($subject->id, $entry->subject_user_id);
        $this->assertNull($entry->subjectUser);

        // Preserve the existing actor-FK behavior, independently of the subject.
        $actor->delete();
        $entry->refresh();
        $this->assertNull($entry->actor);
        $this->assertSame($subject->id, $entry->subject_user_id);
        $this->assertSame($before['before'], $entry->getRawOriginal('before'));
        $this->assertSame($before['after'], $entry->getRawOriginal('after'));
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_live_subject_changes_do_not_rewrite_historical_event_evidence(): void
    {
        $subject = User::factory()->create(['name' => 'Original name']);
        $entry = AuditLog::create([
            'subject_user_id' => $subject->id, 'event' => 'fixture.user.created',
            'after' => ['subject' => ['id' => $subject->id, 'name' => $subject->name, 'role' => $subject->role->value]],
        ])->fresh();
        $before = $entry->getRawOriginal();

        $subject->update(['name' => 'Current name', 'role' => UserRole::EnergyManager]);

        $this->assertSame('Current name', $entry->subjectUser->name);
        $this->assertSame($before, $entry->fresh()->getRawOriginal());
        $this->assertSame('Original name', $entry->fresh()->after['subject']['name']);
        $this->assertSame('viewer', $entry->fresh()->after['subject']['role']);
    }
}
