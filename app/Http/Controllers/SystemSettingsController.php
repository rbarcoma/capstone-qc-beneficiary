<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\User;
use App\Services\BuildingDataset;
use App\Services\ForecastEligibility;
use App\Services\NasaPowerWeather;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class SystemSettingsController extends Controller
{
    public function index(Request $request, BuildingDataset $datasets): Response
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser(User::findOrFail($user->id))->authorize('view-system-settings');
        $buildings = Building::orderBy('name')->get();
        $filters = $request->validate(['building' => ['nullable', 'string', Rule::in($buildings->pluck('code')->all())]]);
        $building = $buildings->firstWhere('code', $filters['building'] ?? null) ?? $buildings->first();
        $inspection = $building ? $datasets->inspect($building) : null;
        $rows = $inspection['rows'] ?? [];
        $response = Inertia::render('system-settings', [
            'buildings' => $buildings->map(fn (Building $item) => $item->only(['id', 'code', 'name'])),
            'selectedBuilding' => $building?->code,
            'latestVerifiedPeriod' => $rows === [] ? null : $rows[count($rows) - 1]['month'],
            // Explicit allowlist. Never serialize config(), environment, raw
            // policy fingerprints, billing values or infrastructure credentials.
            'configuration' => [
                'tariffPhpPerKwh' => null, 'monthlyTargetKwh' => null, 'changeReviewPercent' => null,
                'pvCapacityKwp' => null, 'pvPerformanceRatio' => null, 'ruleGenerationEnabled' => false,
                'policyState' => config('recommendations.policy_fingerprint') === null ? 'Disabled — C5 approval required' : 'Review required — no approved evaluator implemented',
                'minimumObservations' => ForecastEligibility::MINIMUM_OBSERVATIONS,
                'forecastHorizon' => 'Next eligible month only', 'primaryModel' => 'Linear Regression', 'baseline' => 'Previous-three-month average',
                'weatherSource' => 'NASA POWER', 'weatherParameters' => array_keys(NasaPowerWeather::UNITS),
                'pipelineVersion' => BuildingDataset::VERSION,
            ],
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
