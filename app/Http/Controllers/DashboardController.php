<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Dataset;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Services\BuildingAnalytics;
use App\Services\BuildingDataset;
use App\Services\ForecastEligibility;
use App\Services\ForecastRead;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Render the admin dashboard with data-driven props.
     *
     * P4 counts and coverage use authorized stored/verified monthly records.
     * Trend analytics and forecast eligibility belong to P5/P6.
     */
    public function index(Request $request, BuildingDataset $pipeline, BuildingAnalytics $analytics, ForecastRead $forecasts): Response
    {
        /** @var User $user */
        $user = $request->user();

        $buildings = Building::query()
            ->when($user->role->isBuildingRestricted(), fn ($query) => $query->whereIn('id', $user->allowedBuildingIds() ?? []))
            ->orderBy('name')->get();

        $selectedBuildingCode = $request->query('building', $buildings->first()?->code);
        abort_unless($selectedBuildingCode === null || (is_string($selectedBuildingCode)
            && $buildings->contains('code', $selectedBuildingCode)), 404);

        $inspections = $buildings->mapWithKeys(fn (Building $building) => [$building->id => $pipeline->inspect($building)]);
        $readiness = $buildings->map(function (Building $building) use ($inspections) {
            $data = $inspections[$building->id];

            return ['building' => $building->name, 'verifiedCoverage' => $data['verified_periods'],
                'latestMonth' => $data['rows'] === [] ? null : $data['rows'][count($data['rows']) - 1]['month'],
                'forecastReady' => app(ForecastEligibility::class)->check($building, Dataset::where('building_id', $building->id)->where('fingerprint', $data['fingerprint'])->first(), $data)['eligible']];
        });
        $selected = $buildings->keyBy('code')->get($selectedBuildingCode ?? '');
        $selectedData = $selected ? $inspections[$selected->id] : null;
        $trend = $selected ? $analytics->read($selected, $user)['rows'] : [];
        $issued = $selected ? $forecasts->read($selected, $user)['current'] : null;

        $records = MonthlyRecord::query()->forUser($user)
            ->when($selectedBuildingCode, fn ($query) => $query->whereHas('meter.building', fn ($building) => $building->where('code', $selectedBuildingCode)));

        return Inertia::render('dashboard', [
            'buildings' => $buildings
                ->map(fn (Building $building) => [
                    'code' => $building->code,
                    'name' => $building->name,
                ])
                ->all(),
            'selectedBuildingCode' => $selectedBuildingCode,
            'latestVerifiedPeriod' => $selectedData && $selectedData['rows'] !== [] ? $selectedData['rows'][count($selectedData['rows']) - 1]['month'] : null,
            'kpis' => [
                'pilotBuildings' => $buildings->count(),
                'verifiedMonths' => $selectedData['verified_periods'] ?? 0,
                'nextForecast' => $issued['targetMonth'] ?? null,
                'pendingVerification' => (clone $records)->where('verification_status', 'draft')->count(),
            ],
            'readiness' => $readiness->all(),
            'trend' => array_map(fn ($row) => ['month' => $row['month'], 'kwh' => $row['kwh']], $trend),
        ]);
    }
}
