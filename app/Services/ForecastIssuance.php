<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Dataset;
use App\Models\Forecast;
use App\Models\ForecastResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ForecastIssuance
{
    public function __construct(private BuildingDataset $pipeline, private ForecastEligibility $eligibility, private PythonForecast $worker) {}

    public function engineSignature(): string
    {
        return DataFingerprint::hash(['worker' => $this->workerHash(), 'requirements' => hash_file('sha256', base_path('python/requirements.txt')), 'python' => config('forecasting.python')]);
    }

    public function workerHash(): string
    {
        $hash = hash_file('sha256', base_path('python/forecast.py'));
        if ($hash === false) {
            throw new RuntimeException('Forecast worker is unavailable.');
        }

        return $hash;
    }

    public function key(Dataset $dataset, string $engine): string
    {
        return DataFingerprint::hash(['building' => $dataset->building_id, 'dataset' => $dataset->fingerprint, 'engine' => $engine]);
    }

    /** @return array{forecast: Forecast, created: bool} */
    public function issue(Building $building, User $user, ?string $expectedFingerprint = null): array
    {
        Gate::forUser($user)->authorize('create', Forecast::class);
        abort_unless($user->hasBuildingAccess($building), 403);
        $inspection = $this->pipeline->inspect($building->fresh());
        $dataset = Dataset::where('building_id', $building->id)->where('fingerprint', $inspection['fingerprint'])->first();
        if ($expectedFingerprint !== null && $expectedFingerprint !== $inspection['fingerprint']) {
            throw ValidationException::withMessages(['forecast' => 'The reviewed dataset changed. Refresh and review current inputs before issuing.']);
        }
        $candidate = $this->eligibility->check($building, $dataset, $inspection);
        if (! $candidate['eligible'] || ! $dataset) {
            $this->ineligible($candidate['reason']);
        }
        $engine = $this->engineSignature();
        $workerHash = $this->workerHash();
        $key = $this->key($dataset, $engine);
        $cached = DB::transaction(function () use ($building, $dataset, $engine, $key, $user) {
            $this->finalCheck($building, $dataset, $engine, $user);

            return Forecast::where('issuance_key', $key)->first();
        }, 3);
        if ($cached) {
            return ['forecast' => $cached, 'created' => false];
        }

        // Expensive computation is outside locks. All source/engine conditions
        // are checked AGAIN under consistent locks before any issuance writes.
        $result = $this->worker->evaluate($dataset, $user);
        if (($result['eligible'] ?? false) !== true) {
            $this->ineligible($result['reason'] ?? 'worker_ineligible');
        }
        app(ForecastResultValidator::class)->validate($result, $dataset, $candidate['target_month'], $workerHash);

        return DB::transaction(function () use ($building, $dataset, $user, $engine, $key, $result) {
            $this->finalCheck($building, $dataset, $engine, $user);
            $existing = Forecast::where('issuance_key', $key)->first();
            if ($existing) {
                return ['forecast' => $existing, 'created' => false];
            }
            $forecast = Forecast::create([
                'building_id' => $building->id, 'dataset_id' => $dataset->id, 'issuance_key' => $key,
                'dataset_fingerprint' => $dataset->fingerprint, 'engine_signature' => $engine, 'model_version' => $result['model_version'],
                'model_name' => $result['model'], 'training_end_month' => $dataset->records()->max('month'), 'target_month' => $result['target_month'],
                'predicted_kwh' => $result['prediction_kwh'], 'baseline_kwh' => $result['baseline_kwh'],
                'lower_kwh' => $result['error_band']['lower_kwh'], 'upper_kwh' => $result['error_band']['upper_kwh'],
                'worker_result' => $result, 'issued_by' => $user->id, 'issued_at' => now(),
            ]);
            foreach ($result['evidence'] as $row) {
                ForecastResult::create(['forecast_id' => $forecast->id, 'month' => $row['month'], 'actual_kwh' => $row['actual_kwh'],
                    'predicted_kwh' => $row['linear_kwh'], 'baseline_kwh' => $row['baseline_kwh'], 'train_end_month' => $row['train_end_month'], 'training_rows' => $row['training_rows']]);
            }
            AuditLog::create(['actor_id' => $user->id, 'forecast_id' => $forecast->id, 'event' => 'forecast.issued', 'after' => $forecast->only(['id', 'dataset_id', 'dataset_fingerprint', 'target_month', 'model_version', 'predicted_kwh'])]);
            app(ForecastActuals::class)->reconcile($building, $user);

            return ['forecast' => $forecast, 'created' => true];
        }, 3);
    }

    private function finalCheck(Building $building, Dataset $dataset, string $engine, User $user): void
    {
        $inspection = $this->pipeline->inspectLocked($building);
        $actor = User::lockForUpdate()->findOrFail($user->id);
        Gate::forUser($actor)->authorize('create', Forecast::class);
        abort_unless($actor->hasBuildingAccess($building), 403);
        $candidate = $this->eligibility->check($building, $dataset, $inspection);
        if (! $candidate['eligible'] || $this->engineSignature() !== $engine) {
            throw ValidationException::withMessages(['forecast' => 'Forecast inputs or runtime changed. Reprocess and review current data; no stale run was issued.']);
        }
    }

    private function ineligible(?string $reason): never
    {
        throw ValidationException::withMessages(['forecast' => 'No eligible forecast: '.str_replace('_', ' ', $reason ?? 'unknown eligibility').'.']);
    }
}
