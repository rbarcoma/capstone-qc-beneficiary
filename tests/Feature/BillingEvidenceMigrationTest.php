<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BillingEvidenceMigrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertSame('mysql', DB::connection()->getDriverName());
    }

    public function test_actual_additive_up_down_reapply_preserves_legacy_rows_columns_and_references_without_backfill(): void
    {
        $migration = require database_path('migrations/2026_11_18_000015_create_billing_evidence_tables.php');
        $migration->down();
        try {
            $user = User::factory()->role('admin')->create();
            $building = Building::factory()->create();
            $meter = Meter::factory()->create(['building_id' => $building->id]);
            $record = MonthlyRecord::create(['meter_id' => $meter->id, 'month' => '2025-05-01', 'kwh' => '123.456', 'cost_php' => '789.01',
                'verification_status' => 'verified', 'verified_by' => $user->id, 'verified_at' => now(), 'source' => 'manual']);
            AuditLog::create(['actor_id' => $user->id, 'monthly_record_id' => $record->id, 'event' => 'fixture.retained', 'after' => ['version' => 1]]);
            $user->buildings()->attach($building);
            $tables = ['users', 'buildings', 'meters', 'monthly_records', 'monthly_record_revisions', 'audit_logs', 'user_building'];
            $before = [];
            $columns = [];
            foreach ($tables as $table) {
                $before[$table] = DB::table($table)->get()->toJson();
                $columns[$table] = Schema::getColumnListing($table);
            }
            $migration->up();
            foreach (['utility_accounts', 'utility_services', 'billing_evidence', 'billing_evidence_components'] as $table) {
                $this->assertTrue(Schema::hasTable($table));
                $this->assertDatabaseCount($table, 0);
            }
            $migration->down();
            foreach ($tables as $table) {
                $this->assertSame($before[$table], DB::table($table)->get()->toJson(), $table);
                $this->assertSame($columns[$table], Schema::getColumnListing($table), $table);
            }
            $migration->up();
            foreach ($tables as $table) {
                $this->assertSame($before[$table], DB::table($table)->get()->toJson(), $table);
            }
            $this->assertSame($meter->meralco_account_ref, $meter->fresh()->meralco_account_ref);
        } finally {
            if (! Schema::hasTable('billing_evidence')) {
                $migration->up();
            }
        }
    }
}
