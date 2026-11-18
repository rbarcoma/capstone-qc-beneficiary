<?php

namespace Tests\Feature;

use App\Services\ForecastIssuance;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\ForecastData;
use Tests\TestCase;

class DssReviewMigrationTest extends TestCase
{
    use DatabaseMigrations, ForecastData;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertSame('mysql', DB::connection()->getDriverName());
    }

    public function test_actual_additive_up_down_reapply_preserves_history_and_refuses_lossy_subject_rollback(): void
    {
        $migration = require database_path('migrations/2026_11_18_000016_add_dss_review_and_subjects.php');
        $migration->down();
        try {
            $f = $this->forecastFixture();
            $forecast = app(ForecastIssuance::class)->issue($f['building'], $f['user'])['forecast'];
            $now = now();
            $legacy = ['forecast_id' => $forecast->id, 'rule_code' => 'R5', 'policy_fingerprint' => 'fp-building',
                'source_fingerprint' => $forecast->dataset_fingerprint, 'title' => 'Legacy building decision', 'message' => 'Retained history.',
                'evidence' => json_encode(['forecast_kwh' => 420]), 'priority' => 'high', 'contains_cost_data' => 0,
                'evaluation_status' => 'evaluated', 'decision' => 'pending', 'version' => 1, 'created_at' => $now, 'updated_at' => $now];
            DB::table('recommendations')->insert($legacy);

            $tables = ['users', 'buildings', 'meters', 'monthly_records', 'forecasts', 'recommendations', 'recommendation_decisions', 'actions'];
            $before = [];
            $columns = [];
            foreach ($tables as $table) {
                $before[$table] = DB::table($table)->get()->toJson();
                $columns[$table] = Schema::getColumnListing($table);
            }

            $migration->up();
            foreach (['dss_configurations', 'dss_configuration_events'] as $table) {
                $this->assertTrue(Schema::hasTable($table));
                $this->assertDatabaseCount($table, 0);
            }
            $this->assertSame(['subject_key', 'subject_snapshot'], array_values(array_intersect(Schema::getColumnListing('recommendations'), ['subject_key', 'subject_snapshot'])));
            $this->assertDatabaseHas('recommendations', ['title' => 'Legacy building decision', 'subject_key' => 'building', 'subject_snapshot' => null]);
            $this->assertSame(
                [...(array) json_decode($before['recommendations'], true)[0], 'subject_key' => 'building', 'subject_snapshot' => null],
                (array) DB::table('recommendations')->first(),
            );

            DB::table('recommendations')->insert([...$legacy, 'policy_fingerprint' => 'fp-meter', 'title' => 'Meter-level decision', 'subject_key' => 'meter:'.$f['meters'][0]->id]);
            try {
                $migration->down();
                $this->fail('Lossy rollback was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Per-subject recommendation history must be retained; this rollback cannot collapse it.', $exception->getMessage());
            }
            $this->assertDatabaseHas('recommendations', ['title' => 'Meter-level decision', 'subject_key' => 'meter:'.$f['meters'][0]->id]);

            DB::table('recommendations')->where('title', 'Meter-level decision')->delete();
            $migration->down();
            foreach ($tables as $table) {
                $this->assertSame($before[$table], DB::table($table)->get()->toJson(), $table);
                $this->assertSame($columns[$table], Schema::getColumnListing($table), $table);
            }
            $this->assertFalse(Schema::hasTable('dss_configurations'));
            $this->assertFalse(Schema::hasTable('dss_configuration_events'));

            $migration->up();
            foreach ($tables as $table) {
                if ($table === 'recommendations') {
                    $this->assertSame(
                        [...(array) json_decode($before[$table], true)[0], 'subject_key' => 'building', 'subject_snapshot' => null],
                        (array) DB::table($table)->first(),
                    );

                    continue;
                }
                $this->assertSame($before[$table], DB::table($table)->get()->toJson(), $table);
            }
        } finally {
            if (! Schema::hasTable('dss_configurations')) {
                $migration->up();
            }
        }
    }
}
