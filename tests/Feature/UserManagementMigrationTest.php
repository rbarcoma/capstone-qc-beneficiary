<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserManagementMigrationTest extends TestCase
{
    // MySQL DDL commits implicitly: test the actual up/down cycle outside the
    // transaction used by RefreshDatabase, and only on the isolated testing DB.
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertSame('mysql', DB::connection()->getDriverName());
    }

    public function test_up_down_and_reapply_preserve_legacy_accounts_assignments_and_audit_payloads(): void
    {
        $migration = require database_path('migrations/2026_11_16_000013_add_user_management_foundation.php');
        $migration->down();

        try {
            foreach (UserRole::cases() as $role) {
                // Simulate legacy rows without current-model defaults for the
                // columns deliberately removed by this rollback checkpoint.
                DB::table('users')->insert(Arr::except(User::factory()->role($role)->make()->getAttributes(), [
                    'is_active', 'version', 'access_version', 'deactivated_at',
                ]));
            }
            $subject = User::where('role', UserRole::FacilitiesPersonnel)->firstOrFail();
            $building = Building::factory()->create();
            $subject->buildings()->attach($building);
            $legacy = AuditLog::create([
                'actor_id' => $subject->id, 'event' => 'fixture.legacy.event',
                'before' => ['original' => 'before'], 'after' => ['original' => 'after'], 'reason' => 'Retained fixture evidence',
            ])->fresh();
            $users = DB::table('users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $assignment = DB::table('user_building')->first();
            $audit = $legacy->getRawOriginal();

            $migration->up();

            $added = ['is_active', 'version', 'access_version', 'deactivated_at'];
            $this->assertSame($users, DB::table('users')->orderBy('id')->get()->map(fn ($row) => Arr::except((array) $row, $added))->all());
            foreach (User::all() as $user) {
                $this->assertTrue($user->is_active);
                $this->assertSame(1, $user->version);
                $this->assertSame(1, $user->access_version);
                $this->assertNull($user->deactivated_at);
                $this->assertSame(0, $user->moduleRestrictions()->count());
            }
            $this->assertEquals($assignment, DB::table('user_building')->first());
            $this->assertSame($audit, Arr::except($legacy->fresh()->getRawOriginal(), ['subject_user_id']));
            $this->assertNull($legacy->fresh()->subject_user_id);

            $subject->moduleRestrictions()->create(['module_key' => 'reports']);
            $subjectEntry = AuditLog::create([
                'subject_user_id' => $subject->id, 'event' => 'fixture.subject.event',
                'after' => ['subject' => ['id' => $subject->id, 'role' => 'facilities_personnel']],
            ])->fresh();
            $subjectAudit = Arr::except($subjectEntry->getRawOriginal(), ['subject_user_id']);

            $migration->down();

            $this->assertFalse(Schema::hasTable('user_module_restrictions'));
            foreach ($added as $column) {
                $this->assertFalse(Schema::hasColumn('users', $column));
            }
            $this->assertFalse(Schema::hasColumn('audit_logs', 'subject_user_id'));
            $this->assertSame($users, DB::table('users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
            $this->assertEquals($assignment, DB::table('user_building')->first());
            $this->assertSame($audit, $legacy->fresh()->getRawOriginal());
            $this->assertSame($subjectAudit, $subjectEntry->fresh()->getRawOriginal());

            $migration->up();
            $this->assertDatabaseCount('users', 5);
            $this->assertDatabaseCount('audit_logs', 2);
            $this->assertDatabaseCount('user_module_restrictions', 0);
            $this->assertTrue($subject->fresh()->is_active);
        } finally {
            // Leave migration bookkeeping/schema aligned for normal teardown.
            if (! Schema::hasColumn('users', 'is_active')) {
                $migration->up();
            }
        }
    }
}
