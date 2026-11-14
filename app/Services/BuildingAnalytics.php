<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Building;
use App\Models\Dataset;
use App\Models\ProcessedRecord;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class BuildingAnalytics
{
    public function __construct(private BuildingDataset $pipeline) {}

    /** @return array{dataset: array<string, mixed>|null, stale: bool, rows: list<array<string, mixed>>, summary: array<string, mixed>|null, canViewCost: bool, issues: list<array<string, mixed>>} */
    public function read(Building $building, User $user): array
    {
        abort_unless($user->hasBuildingAccess($building), 403);
        $inspection = $this->pipeline->inspect($building);
        $dataset = Dataset::where('building_id', $building->id)->where('fingerprint', $inspection['fingerprint'])->first();
        $hasOldDataset = Dataset::where('building_id', $building->id)->exists();
        $canViewCost = $user->hasRole(UserRole::Admin, UserRole::EnergyManager, UserRole::BudgetOfficer, UserRole::Viewer);
        if (! $dataset) {
            return ['dataset' => null, 'stale' => $hasOldDataset, 'rows' => [], 'summary' => null, 'canViewCost' => $canViewCost, 'issues' => $inspection['issues']];
        }
        Gate::forUser($user)->authorize('view', $dataset);
        $records = $dataset->records()->orderBy('month')->get()->keyBy(fn (ProcessedRecord $row) => $row->month->toDateString());
        $rows = [];
        foreach ($records as $month => $record) {
            $previous = $records->get($record->month->copy()->subMonth()->toDateString());
            $lastYear = $records->get($record->month->copy()->subYear()->toDateString());
            $rows[] = [
                'month' => $month, 'kwh' => (float) $record->kwh, 'costPhp' => $canViewCost && $record->cost_php !== null ? (float) $record->cost_php : null,
                'momPercent' => $this->percent($record->kwh, $previous?->kwh), 'yoyPercent' => $this->percent($record->kwh, $lastYear?->kwh),
                'kwhPerM2' => $record->metrics['kwh_per_m2'], 'loadFactor' => $record->metrics['load_factor'],
                'billedDemandKw' => $record->metrics['billed_demand_kw'], 'weatherComplete' => $record->weather_complete,
                'costMomPercent' => $canViewCost ? $this->percent($record->cost_php, $previous?->cost_php) : null,
            ];
        }
        $latest = $rows === [] ? null : $rows[count($rows) - 1];

        return [
            'dataset' => $dataset->only(['id', 'version', 'fingerprint', 'rows_count', 'weather_complete_count']), 'stale' => false,
            'rows' => $rows, 'summary' => $latest, 'canViewCost' => $canViewCost, 'issues' => $inspection['issues'],
        ];
    }

    private function percent(?string $current, ?string $previous): ?float
    {
        return $current !== null && $previous !== null && (float) $previous !== 0.0
            ? round(((float) $current - (float) $previous) / (float) $previous * 100, 3) : null;
    }
}
