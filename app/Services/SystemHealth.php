<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Throwable;

class SystemHealth
{
    /** @return array<string, mixed> */
    public function inspect(User $user): array
    {
        Gate::forUser(User::findOrFail($user->id))->authorize('view-system-health');
        $started = now()->toIso8601String();
        $checks = [];
        try {
            $result = DB::selectOne('SELECT 1 AS healthy');
            $passed = $result !== null && (int) $result->healthy === 1;
            $checks[] = $this->result('database', 'Database connection', $passed,
                $passed ? 'A read-only SELECT 1 completed. This does not verify backups or application write permissions.' : 'Database read check failed. Review server diagnostics.');
        } catch (Throwable) {
            $checks[] = $this->result('database', 'Database connection', false, 'Database read check failed. Review server diagnostics.');
        }
        $paths = [storage_path('app/private'), storage_path('framework/cache'), storage_path('framework/sessions'), storage_path('framework/views'), storage_path('logs')];
        $storage = collect($paths)->every(fn ($path) => is_dir($path) && is_readable($path) && is_writable($path));
        $checks[] = $this->result('storage', 'Local storage permissions', $storage, $storage
            ? 'Required local directories are readable and writable according to filesystem permissions. No test file was written; capacity, remote disks and backups are not checked.'
            : 'One or more required local directories are missing or lack permissions. No test file was written.');
        try {
            $process = Process::path(base_path())->env(['OMP_NUM_THREADS' => '1', 'OPENBLAS_NUM_THREADS' => '1'])
                ->timeout(5)->run([(string) config('forecasting.python'), '-I', '-B', base_path('python/health.py')]);
            $runtime = json_decode($process->output(), true, flags: JSON_THROW_ON_ERROR);
            $passed = $process->successful() && is_array($runtime) && ($runtime['contract_version'] ?? null) === 1
                && ($runtime['pins_match'] ?? null) === true && ($runtime['estimator_ok'] ?? null) === true
                && is_string($runtime['python'] ?? null) && preg_match('/^\d+\.\d+\.\d+$/', $runtime['python']) === 1;
            $checks[] = $this->result('python', 'Isolated Python runtime', $passed, $passed
                ? 'Python '.$runtime['python'].' imported the pinned forecast dependencies and completed an in-memory Linear Regression smoke check. No building data or operational forecast was used.'
                : 'Runtime execution, dependency pins or estimator check failed. Review the isolated runtime on the server.');
        } catch (Throwable) {
            // Never return command arguments, stderr, credentials or filesystem paths.
            $checks[] = $this->result('python', 'Isolated Python runtime', false, 'Runtime check failed or exceeded its 5-second limit. Review the isolated runtime on the server.');
        }

        return ['startedAt' => $started, 'checkedAt' => now()->toIso8601String(), 'checks' => $checks,
            'observations' => [
                ['name' => 'Queue workers / scheduler', 'state' => 'Unverified', 'detail' => 'No monitored worker or scheduler heartbeat is recorded. A configured queue driver or empty queue does not prove a worker is running. Monthly domain scheduling remains unimplemented.'],
                ['name' => 'Notification email', 'state' => 'Unconfigured', 'detail' => 'Domain notifications currently use the live in-app inbox. No SMTP delivery probe or queued email was sent.'],
                ['name' => 'Encrypted backups / restore', 'state' => 'Unverified', 'detail' => 'No backup or restore-drill evidence is recorded by this application. A healthy database is not backup evidence.'],
                ['name' => 'External monitoring / firewall', 'state' => 'Unverified', 'detail' => 'This authenticated application check cannot establish external monitoring, firewall rules or production TLS configuration.'],
                ['name' => 'C5 rule generation', 'state' => config('recommendations.policy_fingerprint') === null ? 'Disabled' : 'Review required', 'detail' => 'An approved rule evaluator is not implemented. A policy configuration value is not approval and does not establish operational readiness.'],
            ]];
    }

    /** @return array{id: string, name: string, state: string, detail: string} */
    private function result(string $id, string $name, bool $passed, string $detail): array
    {
        return ['id' => $id, 'name' => $name, 'state' => $passed ? 'Passed' : 'Failed', 'detail' => $detail];
    }
}
