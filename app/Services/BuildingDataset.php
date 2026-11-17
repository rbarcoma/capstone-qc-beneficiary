<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Dataset;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\ProcessedRecord;
use App\Models\User;
use App\Models\WeatherRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

class BuildingDataset
{
    public const VERSION = 'verified-building-calendar-v1';

    public function cutoff(): string
    {
        return now('Asia/Manila')->startOfMonth()->subMonth()->toDateString();
    }

    /** @return array<string, mixed> */
    public function inspect(Building $building, ?string $through = null): array
    {
        $through = $this->validateCutoff($through);

        return DB::transaction(fn () => $this->collect($building, $through, false));
    }

    /** @return array<string, mixed> */
    public function inspectLocked(Building $building, ?string $through = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Locked inspection requires an enclosing transaction.');
        }
        $current = Building::lockForUpdate()->findOrFail($building->id);

        return $this->collect($current, $this->validateCutoff($through), true);
    }

    /** @return array{dataset: Dataset, created: bool} */
    public function build(Building $building, User $user, ?string $through = null): array
    {
        Gate::forUser($user)->authorize('create', Dataset::class);
        abort_unless($user->hasBuildingAccess($building), 403);
        $through = $this->validateCutoff($through);

        return DB::transaction(function () use ($building, $user, $through) {
            $current = Building::lockForUpdate()->findOrFail($building->id);
            $data = $this->collect($current, $through, true);
            $actor = User::lockForUpdate()->findOrFail($user->id);
            Gate::forUser($actor)->authorize('create', Dataset::class);
            abort_unless($actor->hasBuildingAccess($current), 403);
            if ($data['rows'] === []) {
                throw ValidationException::withMessages(['dataset' => 'No complete verified building months are available. Verify every required meter for a month first.']);
            }
            $existing = Dataset::where('building_id', $building->id)->where('fingerprint', $data['fingerprint'])->first();
            if ($existing) {
                return ['dataset' => $existing, 'created' => false];
            }
            $dataset = Dataset::create([
                'building_id' => $building->id, 'version' => (int) Dataset::where('building_id', $building->id)->max('version') + 1,
                'pipeline_version' => self::VERSION, 'cutoff_month' => $through, 'fingerprint' => $data['fingerprint'],
                'input_manifest' => $data['manifest'], 'issues' => $data['issues'], 'rows_count' => count($data['rows']),
                'weather_complete_count' => count(array_filter($data['rows'], fn ($row) => $row['weather_complete'])), 'created_by' => $user->id,
            ]);
            foreach ($data['rows'] as $row) {
                ProcessedRecord::create(['dataset_id' => $dataset->id, ...$row]);
            }
            AuditLog::create(['actor_id' => $user->id, 'event' => 'dataset.built', 'after' => ['dataset_id' => $dataset->id, 'building_id' => $building->id, 'version' => $dataset->version, 'fingerprint' => $dataset->fingerprint]]);

            return ['dataset' => $dataset, 'created' => true];
        }, 3);
    }

    private function validateCutoff(?string $through): string
    {
        $through ??= $this->cutoff();
        Validator::make(['through' => $through], ['through' => ['required', 'date_format:Y-m-d', 'regex:/^\d{4}-\d{2}-01$/', 'after_or_equal:1900-01-01', 'before:'.now('Asia/Manila')->startOfMonth()->toDateString()]])->validate();

        return $through;
    }

    /** @return array<string, mixed> */
    private function collect(Building $building, string $through, bool $lock): array
    {
        // No effective-date metadata exists: require ALL registered non-removed
        // meters, including inactive ones, rather than invent historical windows.
        $meters = Meter::where('building_id', $building->id)->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $records = MonthlyRecord::whereIn('meter_id', $meters->modelKeys())->where('month', '<=', $through)
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $weatherMonths = $records->pluck('month')->flatMap(fn ($month) => [Carbon::parse($month)->toDateString(), Carbon::parse($month)->subMonth()->toDateString()])->unique();
        $weather = WeatherRecord::where('location_key', app(NasaPowerWeather::class)->locationKey())
            ->whereIn('month', $weatherMonths)
            ->orderBy('month')->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy(fn (WeatherRecord $record) => $record->month->toDateString());

        $groups = $records->groupBy(fn (MonthlyRecord $record) => $record->month->toDateString())->sortKeys();
        $rollups = [];
        $issues = [];
        $coverage = [];
        $verifiedSnapshots = [];
        foreach ($groups as $month => $group) {
            $verified = $group->where('verification_status', 'verified');
            $coverage[$month] = $group->map(fn (MonthlyRecord $record) => ['id' => $record->id, 'meter_id' => $record->meter_id, 'status' => $record->verification_status])->all();
            foreach ($verified as $record) {
                $verifiedSnapshots[] = ['id' => $record->id, ...$record->snapshot()];
            }
            if ($meters->isEmpty() || $verified->count() !== $meters->count()) {
                $issues[] = ['month' => $month, 'kind' => 'billing', 'message' => 'Requires verified bills for every registered meter ('.$verified->count().'/'.$meters->count().'). No partial building total used.'];

                continue;
            }
            $valid = $verified->every(fn (MonthlyRecord $record) => is_numeric($record->kwh) && (float) $record->kwh >= 0
                && ($record->cost_php === null || (is_numeric($record->cost_php) && (float) $record->cost_php >= 0))
                && ($record->billed_demand_kw === null || (is_numeric($record->billed_demand_kw) && (float) $record->billed_demand_kw >= 0)));
            if (! $valid) {
                $issues[] = ['month' => $month, 'kind' => 'billing', 'message' => 'Invalid verified numeric values require a recorded correction.'];

                continue;
            }
            $kwh = round($verified->sum(fn (MonthlyRecord $record) => (float) $record->kwh), 3);
            $costComplete = $verified->every(fn (MonthlyRecord $record) => $record->cost_php !== null);
            $single = $verified->count() === 1 ? $verified->first() : null;
            $days = Carbon::parse($month)->daysInMonth;
            $demand = $single?->billed_demand_kw !== null ? (float) $single->billed_demand_kw : null;
            $loadFactor = $demand !== null && $demand > 0 ? $kwh / ($demand * $days * 24) : null;
            $rollups[$month] = [
                'kwh' => $kwh, 'cost_php' => $costComplete ? round($verified->sum(fn (MonthlyRecord $record) => (float) $record->cost_php), 2) : null,
                'sources' => $verified->map(fn (MonthlyRecord $record) => ['id' => $record->id, ...$record->snapshot()])->values()->all(),
                'metrics' => [
                    'meter_count' => $meters->count(), 'cost_complete' => $costComplete,
                    'kwh_per_m2' => $building->floor_area_m2 !== null && $building->floor_area_m2 > 0 ? round($kwh / $building->floor_area_m2, 6) : null,
                    'billed_demand_kw' => $demand, 'load_factor' => $loadFactor !== null ? round($loadFactor, 6) : null,
                    'load_factor_basis' => 'single-meter billed demand × calendar days × 24h; multi-meter coincident demand unknown',
                ],
            ];
            if ($loadFactor !== null && $loadFactor > 1) {
                $issues[] = ['month' => $month, 'kind' => 'review', 'message' => 'Load factor exceeds 1. Retained verified values require bill-context review; not clamped or removed.'];
            }
        }

        if ($groups->isNotEmpty()) {
            $cursor = Carbon::parse((string) $groups->keys()->first());
            $last = Carbon::parse((string) $groups->keys()->last());
            while ($cursor->lt($last)) {
                if (! $groups->has($cursor->toDateString())) {
                    $issues[] = ['month' => $cursor->toDateString(), 'kind' => 'billing', 'message' => 'Missing billing period. No bill values were imputed.'];
                }
                $cursor->addMonth();
            }
        }

        $rows = [];
        $weatherManifest = [];
        foreach ($rollups as $month => $rollup) {
            $date = Carbon::parse($month);
            $previous = $date->copy()->subMonth()->toDateString();
            $previous2 = $date->copy()->subMonths(2)->toDateString();
            $previous3 = $date->copy()->subMonths(3)->toDateString();
            $lag1 = $rollups[$previous]['kwh'] ?? null;
            $lag2 = $rollups[$previous2]['kwh'] ?? null;
            $lag3 = $rollups[$previous3]['kwh'] ?? null;
            $observed = $weather->get($month);
            $laggedWeather = $weather->get($previous);
            $weatherManifest[$month] = ['id' => $observed?->id, 'hash' => $observed?->content_hash];
            $weatherManifest[$previous] = ['id' => $laggedWeather?->id, 'hash' => $laggedWeather?->content_hash];
            if (! $observed?->is_complete) {
                $issues[] = ['month' => $month, 'kind' => 'weather', 'message' => 'Complete source weather unavailable. Billing analytics retained; weather values/feature readiness are not invented.'];
            }
            $features = [
                'lag_1_kwh' => $lag1, 'lag_2_kwh' => $lag2,
                'previous_3_month_average_kwh' => $lag1 !== null && $lag2 !== null && $lag3 !== null ? round(($lag1 + $lag2 + $lag3) / 3, 6) : null,
                'prior_trend_kwh' => $lag1 !== null && $lag2 !== null ? round($lag1 - $lag2, 3) : null,
                'month_sin' => round(sin(2 * M_PI * $date->month / 12), 8), 'month_cos' => round(cos(2 * M_PI * $date->month / 12), 8),
                'weather_lag_1' => $laggedWeather?->weather_values,
                'weather_lag_1_complete' => $laggedWeather->is_complete ?? false,
            ];
            $rows[] = [
                'month' => $month, 'kwh' => $rollup['kwh'], 'cost_php' => $rollup['cost_php'], 'source_snapshot' => $rollup['sources'],
                'weather_record_id' => $observed?->id, 'weather_snapshot' => ['observed' => $observed?->snapshot(), 'lag_1' => $laggedWeather?->snapshot()], 'weather_complete' => $observed->is_complete ?? false,
                'features' => $features, 'metrics' => $rollup['metrics'],
            ];
        }
        $manifest = [
            'pipeline' => self::VERSION, 'cutoff' => $through, 'weather_location' => app(NasaPowerWeather::class)->locationKey(),
            'building' => $building->only(['id', 'code', 'name', 'floor_area_m2']),
            'required_meters' => $meters->map(fn (Meter $meter) => $meter->only(['id', 'status']))->all(),
            'coverage' => $coverage, 'verified_records' => $verifiedSnapshots, 'weather' => $weatherManifest,
        ];

        return [
            'manifest' => $manifest, 'fingerprint' => DataFingerprint::hash($manifest), 'issues' => $issues, 'rows' => $rows,
            'uploaded_periods' => $groups->count(), 'verified_periods' => count($rollups),
            'billing_gaps' => count(array_filter($issues, fn ($issue) => $issue['kind'] === 'billing')),
        ];
    }
}
