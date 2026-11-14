<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\User;
use App\Services\BuildingAnalytics;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request, BuildingAnalytics $analytics): Response
    {
        /** @var User $user */
        $user = $request->user();
        $buildings = $this->buildings($user);
        $code = $request->query('building', $buildings->first()?->code);
        abort_unless($code === null || (is_string($code) && $buildings->contains('code', $code)), 404);
        $building = $buildings->keyBy('code')->get($code ?? '');

        return Inertia::render('energy-analytics', [
            'buildings' => $buildings->map(fn (Building $item) => $item->only(['id', 'code', 'name'])), 'selectedBuildingCode' => $code,
            'analytics' => $building ? $analytics->read($building, $user) : null,
        ]);
    }

    public function comparison(Request $request, BuildingAnalytics $analytics): Response
    {
        /** @var User $user */
        $user = $request->user();
        $buildings = $this->buildings($user);
        $data = [];
        $common = null;
        foreach ($buildings as $building) {
            $result = $analytics->read($building, $user);
            $data[$building->id] = $result;
            $periods = array_column($result['rows'], 'month');
            $common = $common === null ? $periods : array_values(array_intersect($common, $periods));
        }
        $periods = $common ?? [];
        sort($periods);
        $period = $request->query('period', $periods === [] ? null : $periods[count($periods) - 1]);
        abort_unless($period === null || (is_string($period) && in_array($period, $periods, true)), 404);
        $rows = [];
        foreach ($buildings as $building) {
            $result = $data[$building->id];
            $value = collect($result['rows'])->firstWhere('month', $period);
            $rows[] = [
                'id' => $building->id, 'name' => $building->name, 'kwh' => $value['kwh'] ?? null, 'costPhp' => $value['costPhp'] ?? null,
                'kwhPerM2' => $value['kwhPerM2'] ?? null, 'loadFactor' => $value['loadFactor'] ?? null,
                'latestMonth' => $result['summary']['month'] ?? null, 'stale' => $result['stale'],
                'datasetVersion' => $result['dataset']['version'] ?? null,
            ];
        }
        foreach (['kwh', 'costPhp', 'kwhPerM2'] as $field) {
            $ordered = array_unique(array_column(array_filter($rows, fn ($row) => $row[$field] !== null), $field));
            rsort($ordered, SORT_NUMERIC);
            foreach ($rows as &$row) {
                $row[$field.'Rank'] = $row[$field] === null ? null : array_search($row[$field], $ordered) + 1;
            }
            unset($row);
        }

        return Inertia::render('building-comparison', [
            'rows' => $rows, 'periods' => $periods, 'selectedPeriod' => $period,
            'portfolioKwh' => $period !== null && $rows !== [] ? array_sum(array_column($rows, 'kwh')) : null,
            'floorAreaCount' => $buildings->filter(fn (Building $building) => $building->floor_area_m2 !== null && $building->floor_area_m2 > 0)->count(),
            'canViewCost' => $data === [] ? false : reset($data)['canViewCost'],
        ]);
    }

    /** @return Collection<int, Building> */
    private function buildings(User $user): Collection
    {
        return Building::query()->when($user->role->isBuildingRestricted(), fn ($query) => $query->whereIn('id', $user->allowedBuildingIds() ?? []))->orderBy('name')->get();
    }
}
