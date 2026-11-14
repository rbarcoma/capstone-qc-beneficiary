<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Render the admin dashboard with data-driven props.
     *
     * Domain tables (buildings, monthly records, forecasts) arrive in later
     * phases (P3+). Every stat must be derived from the database, never
     * hardcoded, so before those phases exist the dashboard renders real
     * empty states (no buildings, zero months, no forecast). P3 replaces
     * these placeholders with real queries.
     */
    public function index(Request $request): Response
    {
        $selectedBuildingCode = $request->query('building');

        return Inertia::render('dashboard', [
            'buildings' => [],
            'selectedBuildingCode' => $selectedBuildingCode,
            'latestVerifiedPeriod' => null,
            'kpis' => [
                'pilotBuildings' => 0,
                'verifiedMonths' => 0,
                'nextForecast' => null,
                'pendingVerification' => 0,
            ],
            'readiness' => [],
            'trend' => [],
        ]);
    }
}
