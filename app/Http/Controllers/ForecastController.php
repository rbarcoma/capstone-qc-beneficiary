<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Forecast;
use App\Models\User;
use App\Services\ForecastActuals;
use App\Services\ForecastIssuance;
use App\Services\ForecastRead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use JsonException;
use RuntimeException;

class ForecastController extends Controller
{
    public function index(Request $request, ForecastRead $reader): Response
    {
        return Inertia::render('forecasting', $this->props($request, $reader));
    }

    public function evaluation(Request $request, ForecastRead $reader): Response
    {
        return Inertia::render('model-evaluation', $this->props($request, $reader));
    }

    public function issue(Request $request, ForecastIssuance $issuer): RedirectResponse
    {
        Gate::authorize('create', Forecast::class);
        $request->validate(['building_id' => ['required', 'integer'], 'expected_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'target_month' => ['prohibited'], 'horizon' => ['prohibited'], 'dataset_id' => ['prohibited'], 'model' => ['prohibited']]);
        /** @var User $user */
        $user = $request->user();
        $building = Building::whereKey($request->integer('building_id'))->firstOrFail();
        abort_unless($user->hasBuildingAccess($building), 403);
        try {
            $result = $issuer->issue($building, $user, $request->string('expected_fingerprint')->toString());
        } catch (RuntimeException|JsonException $error) {
            AuditLog::create(['actor_id' => $user->id, 'event' => 'forecast.failed', 'reason' => $error::class, 'after' => ['building_id' => $building->id]]);

            return back()->withErrors(['forecast' => 'The forecast worker failed. No run was issued; check the prepared runtime and retry.']);
        }

        return back()->with('forecastResult', ['message' => $result['created'] ? 'Next-month forecast issued and audited.' : 'Existing forecast reused; the target has not advanced.']);
    }

    public function reconcile(Request $request, ForecastActuals $actuals): RedirectResponse
    {
        Gate::authorize('create', Forecast::class);
        $request->validate(['building_id' => ['required', 'integer']]);
        /** @var User $actor */
        $actor = $request->user();
        $building = Building::whereKey($request->integer('building_id'))->firstOrFail();
        abort_unless($actor->hasBuildingAccess($building), 403);
        $actuals->reconcile($building, $actor);

        return back()->with('forecastResult', ['message' => 'Realized errors reconciled against current complete verified target bills.']);
    }

    /** @return array<string, mixed> */
    private function props(Request $request, ForecastRead $reader): array
    {
        /** @var User $user */
        $user = $request->user();
        $buildings = Building::query()->when($user->role->isBuildingRestricted(), fn ($query) => $query->whereIn('id', $user->allowedBuildingIds() ?? []))->orderBy('name')->get();
        $code = $request->query('building', $buildings->first()?->code);
        abort_unless($code === null || (is_string($code) && $buildings->contains('code', $code)), 404);
        $run = $request->query('run');
        abort_unless($run === null || (is_string($run) && ctype_digit($run) && (int) $run > 0), 404);
        $building = $buildings->keyBy('code')->get($code ?? '');

        return ['buildings' => $buildings->map(fn (Building $item) => $item->only(['id', 'code', 'name'])), 'selectedBuildingCode' => $code,
            'data' => $building ? $reader->read($building, $user, $run === null ? null : (int) $run) : null,
            'operationResult' => $request->session()->get('forecastResult')];
    }
}
