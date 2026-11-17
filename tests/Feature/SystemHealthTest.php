<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_may_open_or_run_checks_and_denials_are_audited(): void
    {
        Process::fake();
        $this->get('/system-health')->assertRedirect('/login');
        $this->getJson('/system-health/checks')->assertUnauthorized();
        foreach (['energy_manager', 'facilities_personnel', 'budget_officer', 'viewer'] as $role) {
            $actor = User::factory()->role($role)->create();
            $this->assertFalse(Gate::forUser($actor)->allows('view-system-health'));
            $this->actingAs($actor)->get('/system-health')->assertForbidden();
            $this->getJson('/system-health/checks')->assertForbidden();
            $this->assertSame(2, AuditLog::where('actor_id', $actor->id)->where('event', 'authorization.denied')->count());
        }
        Process::assertNothingRan();
    }

    public function test_opening_the_private_page_does_not_run_or_claim_checks(): void
    {
        Process::fake();
        $this->actingAs(User::factory()->admin()->create())->get('/system-health')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn ($page) => $page->component('system-health')->missing('snapshot'));
        Process::assertNothingRan();
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertNull(config('recommendations.policy_fingerprint'));
    }

    public function test_diagnostics_are_measured_bounded_and_do_not_mutate_domain_or_queue_data(): void
    {
        Process::fake(['*' => Process::result(output: json_encode(['contract_version' => 1, 'python' => '3.12.3', 'pins_match' => true, 'estimator_ok' => true], JSON_THROW_ON_ERROR))]);
        $this->actingAs(User::factory()->admin()->create())->getJson('/system-health/checks?command=arbitrary&python=/untrusted')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('checks.0.state', 'Passed')->assertJsonPath('checks.2.state', 'Passed')
            ->assertJsonPath('observations.0.state', 'Unverified')->assertJsonPath('observations.1.state', 'Unconfigured')->assertJsonPath('observations.2.state', 'Unverified')
            ->assertJsonPath('observations.4.state', 'Disabled')->assertJsonStructure(['startedAt', 'checkedAt']);
        Process::assertRan(fn (PendingProcess $process) => $process->command === [config('forecasting.python'), '-I', '-B', base_path('python/health.py')]
            && $process->timeout === 5 && $process->path === base_path() && $process->environment['OPENBLAS_NUM_THREADS'] === '1' && $process->input === null);
        foreach (['monthly_records', 'datasets', 'forecasts', 'recommendations', 'actions', 'audit_logs', 'jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_database_and_runtime_failures_are_independent_and_redacted(): void
    {
        $actor = User::factory()->admin()->create();
        DB::partialMock()->shouldReceive('selectOne')->once()->andThrow(new RuntimeException('SECRET database host/password'));
        Process::fake(['*' => Process::result(output: '/private/secret runtime output', errorOutput: 'SECRET stderr', exitCode: 1)]);
        $response = $this->actingAs($actor)->getJson('/system-health/checks')->assertOk()->assertJsonPath('checks.0.state', 'Failed')->assertJsonPath('checks.2.state', 'Failed');
        foreach (['SECRET', '/private/secret', 'password', base_path(), config('forecasting.python')] as $secret) {
            $response->assertDontSee($secret);
        }
    }

    public function test_bad_runtime_contract_pin_mismatch_and_timeout_never_pass(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach ([['contract_version' => 2], ['contract_version' => 1, 'python' => '3.12.3', 'pins_match' => false, 'estimator_ok' => true],
            ['contract_version' => 1, 'python' => '<private version>', 'pins_match' => true, 'estimator_ok' => true]] as $result) {
            Process::fake(['*' => Process::result(output: json_encode($result, JSON_THROW_ON_ERROR))]);
            $this->getJson('/system-health/checks')->assertOk()->assertJsonPath('checks.2.state', 'Failed')->assertDontSee('<private version>');
        }
        Process::fake(function () {
            throw new RuntimeException('SECRET timeout command');
        });
        $this->getJson('/system-health/checks')->assertOk()->assertJsonPath('checks.2.state', 'Failed')->assertDontSee('SECRET');
    }

    public function test_storage_permission_failure_and_policy_value_do_not_claim_readiness(): void
    {
        Process::fake();
        $actor = User::factory()->admin()->create();
        $original = storage_path();
        config(['recommendations.policy_fingerprint' => hash('sha256', 'not approval')]);
        $this->app->useStoragePath(sys_get_temp_dir().'/qc-missing-storage-'.bin2hex(random_bytes(8)));
        try {
            $snapshot = app(SystemHealth::class)->inspect($actor);
            $this->assertSame('Failed', $snapshot['checks'][1]['state']);
            $this->assertSame('Review required', $snapshot['observations'][4]['state']);
        } finally {
            $this->app->useStoragePath($original);
        }
    }

    public function test_revoked_admin_is_rechecked_before_runtime_and_endpoint_is_throttled(): void
    {
        Process::fake();
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);
        User::whereKey($actor->id)->update(['role' => 'viewer']);
        $this->get('/system-health')->assertForbidden();
        $this->getJson('/system-health/checks')->assertForbidden();
        Process::assertNothingRan();
        $this->actingAs(User::factory()->admin()->create());
        for ($i = 0; $i < 6; $i++) {
            $this->getJson('/system-health/checks')->assertOk();
        }
        $this->getJson('/system-health/checks')->assertTooManyRequests();
        $this->postJson('/system-health/checks')->assertMethodNotAllowed();
    }

    public function test_real_sail_runtime_diagnostic_imports_pins_and_runs_estimator_without_issuing_forecast(): void
    {
        $result = app(SystemHealth::class)->inspect(User::factory()->admin()->create());
        $this->assertSame('Passed', $result['checks'][2]['state'], $result['checks'][2]['detail']);
        $this->assertDatabaseCount('forecasts', 0);
        $this->assertDatabaseCount('monthly_records', 0);
        $this->assertNull(config('recommendations.policy_fingerprint'));
    }
}
