<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\User;
use App\Services\ForecastRead;
use App\Services\ForecastScenario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SolarScenarioController extends Controller
{
    public function index(Request $request, ForecastRead $reader): Response
    {
        /** @var User $user */
        $user = $request->user();
        $buildings = Building::query()->when($user->role->isBuildingRestricted(), fn ($query) => $query->whereIn('id', $user->allowedBuildingIds() ?? []))->orderBy('name')->get();
        $code = $request->query('building', $buildings->first()?->code);
        abort_unless($code === null || (is_string($code) && $buildings->contains('code', $code)), 404);
        $building = $buildings->keyBy('code')->get($code ?? '');
        $view = $building ? $reader->read($building, $user) : null;
        $result = $request->session()->get('scenarioResult');
        $canViewCost = ForecastScenario::canViewInstallationCost($user);
        if (! $canViewCost && $result !== null) {
            unset($result['installation']);
        }
        // Never redisplay a flash result against a changed/superseded source run.
        if ($result && ($view['current']['id'] ?? null) !== $result['forecastId']) {
            $result = null;
        }

        return Inertia::render('solar-scenarios', ['buildings' => $buildings->map(fn (Building $item) => $item->only(['id', 'name', 'code'])),
            'selectedBuildingCode' => $code, 'latestVerifiedPeriod' => $view['latestVerifiedPeriod'] ?? null,
            'currentForecast' => $view['current'] ?? null, 'canViewInstallationCost' => $canViewCost, 'scenarioResult' => $result]);
    }

    public function calculate(Request $request, ForecastScenario $scenarios): RedirectResponse
    {
        $request->validate(['building_id' => ['required', 'integer']]);
        /** @var User $user */
        $user = $request->user();
        $building = Building::whereKey($request->integer('building_id'))->firstOrFail();
        $result = $scenarios->calculate($building, $user, $request->only(['expected_forecast_id', 'reduction_percent', 'capacity_kwp', 'performance_ratio', 'installation', 'tariff_php_per_kwh', 'target_month', 'solar_irradiance']));

        return back()->with('scenarioResult', $result);
    }
}
