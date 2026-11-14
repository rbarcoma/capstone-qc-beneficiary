<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Dataset;
use App\Models\User;
use App\Models\WeatherRecord;
use App\Services\BuildingDataset;
use App\Services\NasaPowerWeather;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PreprocessingController extends Controller
{
    public function index(Request $request, BuildingDataset $pipeline, NasaPowerWeather $weather): Response
    {
        // Fingerprints/intensity need the complete building metadata internally.
        $buildings = Building::orderBy('name')->get();
        $code = $request->query('building', $buildings->first()?->code);
        abort_unless($code === null || (is_string($code) && $buildings->contains('code', $code)), 404);
        $building = $buildings->keyBy('code')->get($code ?? '');
        $inspection = $building ? $pipeline->inspect($building) : null;
        $current = $building ? Dataset::where('building_id', $building->id)->where('fingerprint', $inspection['fingerprint'])->first() : null;
        $versions = Dataset::where('building_id', $building->id ?? 0)->latest('version')->limit(10)->get();

        return Inertia::render('preprocessing', [
            'buildings' => $buildings->map(fn (Building $item) => $item->only(['id', 'code', 'name'])), 'selectedBuildingCode' => $code,
            'latestVerifiedPeriod' => $inspection && $inspection['rows'] !== [] ? $inspection['rows'][count($inspection['rows']) - 1]['month'] : null,
            'metrics' => ['uploaded' => $inspection['uploaded_periods'] ?? 0, 'verified' => $inspection['verified_periods'] ?? 0, 'gaps' => $inspection['billing_gaps'] ?? 0],
            'issues' => $inspection['issues'] ?? [], 'currentDataset' => $current?->only(['id', 'version', 'rows_count', 'weather_complete_count', 'fingerprint']),
            'versions' => $versions->map(fn (Dataset $dataset) => ['id' => $dataset->id, 'version' => $dataset->version, 'cutoff' => $dataset->cutoff_month->toDateString(), 'rows' => $dataset->rows_count, 'current' => $dataset->id === $current?->id]),
            'lastCompletedMonth' => substr($pipeline->cutoff(), 0, 7),
            'weather' => WeatherRecord::where('location_key', $weather->locationKey())->orderByDesc('month')->paginate(12)->withQueryString()->through(fn (WeatherRecord $record) => [
                'id' => $record->id, 'month' => $record->month->toDateString(), 'values' => $record->weather_values, 'units' => $record->units,
                'complete' => $record->is_complete, 'quality' => $record->quality,
                'source' => $record->source_metadata, 'fetchedAt' => $record->fetched_at,
            ]),
            'operationResult' => $request->session()->get('operationResult'),
        ]);
    }

    public function build(Request $request, BuildingDataset $pipeline): RedirectResponse
    {
        Gate::authorize('create', Dataset::class);
        $data = $request->validate(['building_id' => ['required', 'integer']]);
        /** @var User $user */
        $user = $request->user();
        $result = $pipeline->build(Building::whereKey($request->integer('building_id'))->firstOrFail(), $user);

        return back()->with('operationResult', ['message' => 'Dataset v'.$result['dataset']->version.($result['created'] ? ' created from complete verified building months.' : ' reused; inputs are unchanged.')]);
    }

    public function syncWeather(Request $request, NasaPowerWeather $weather): RedirectResponse
    {
        Gate::authorize('create', Dataset::class);
        $data = $request->validate(['start' => ['required', 'string'], 'end' => ['required', 'string']]);
        /** @var User $user */
        $user = $request->user();
        $result = $weather->sync($data['start'], $data['end'], $user);

        return back()->with('operationResult', ['message' => 'NASA POWER sync: '.$result['created'].' new, '.$result['updated'].' updated, '.$result['unchanged'].' unchanged; '.$result['incomplete'].' incomplete months explicitly flagged.']);
    }

    public function lineage(Dataset $dataset): JsonResponse
    {
        Gate::authorize('create', Dataset::class);
        Gate::authorize('view', $dataset);

        return response()->json([
            'dataset' => $dataset->only(['id', 'version', 'pipeline_version', 'cutoff_month', 'fingerprint', 'input_manifest', 'issues', 'created_at']),
            'records' => $dataset->records()->orderBy('month')->paginate(5),
        ]);
    }
}
