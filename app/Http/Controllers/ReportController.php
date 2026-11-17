<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Building;
use App\Models\User;
use App\Services\ReportDownload;
use App\Services\ReportSnapshot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function index(Request $request, ReportSnapshot $reader): Response
    {
        /** @var User $user */
        $user = $request->user();
        $data = DB::transaction(function () use ($request, $reader, $user) {
            $buildings = Building::query()->when($user->role->isBuildingRestricted(), fn ($query) => $query->whereIn('id', $user->allowedBuildingIds() ?? []))
                ->orderBy('name')->get(['id', 'name', 'code']);
            $filters = $request->validate(['building' => ['nullable', 'string', Rule::in($buildings->pluck('code')->all())]]);
            $selected = $buildings->firstWhere('code', $filters['building'] ?? null) ?? $buildings->first();
            $snapshot = $selected ? $reader->read($selected, $user) : null;

            return ['buildings' => $buildings, 'selectedBuilding' => $selected?->code,
                'report' => $snapshot ? [
                    'buildingId' => $selected->id, 'fingerprint' => $reader->fingerprint($snapshot),
                    'latestPeriod' => $snapshot['latestPeriod'], 'datasetVersion' => $snapshot['dataset']['version'] ?? null,
                    'stale' => $snapshot['stale'], 'billingRows' => count($snapshot['billing']), 'forecastId' => $snapshot['forecast']['id'] ?? null,
                    'recommendationState' => $snapshot['recommendationState'], 'budgetState' => $snapshot['budgetState'],
                    'canViewCost' => $snapshot['canViewCost'],
                ] : null];
        });
        $response = Inertia::render('reports', $data)->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function download(Request $request, Building $building, string $type, ReportDownload $downloads): Response
    {
        $input = $request->validate(['fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);
        /** @var User $user */
        $user = $request->user();
        try {
            $file = $downloads->generate($building, $user, $type, $input['fingerprint']);
        } catch (AuthorizationException $exception) {
            AuditLog::create(['actor_id' => $user->id, 'event' => 'authorization.denied', 'reason' => 'report.download', 'after' => ['building_id' => $building->id]]);
            throw $exception;
        }

        return response($file['bytes'], 200, [
            'Content-Type' => $file['mime'], 'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
