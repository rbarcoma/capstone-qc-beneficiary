<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Meter;
use App\Models\User;
use App\Services\BuildingDataset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BuildingsController extends Controller
{
    /**
     * Admin Buildings & Meters registry (Figma frame `2024:176`).
     *
     * Accessible to Admin, Energy Manager and Facilities Personnel
     * (building-filtered for restricted roles). The frame's Add building /
     * Register meter forms do not exist in Figma (both "modal" frames only
     * restate the base page); forms use the project's shadcn dialog
     * conventions — a documented design gap.
     *
     * Meralco account references are confidential (proposal §9.1 / §12.1):
     * only masked values are sent to the frontend. Only Admin may manage
     * registry entries.
     */
    public function index(Request $request, BuildingDataset $pipeline): Response
    {
        /** @var User $user */
        $user = $request->user();

        $buildings = Building::query()
            ->with('meters')
            ->when(
                $user->role->isBuildingRestricted(),
                fn ($query) => $query->whereIn(
                    'id',
                    $user->allowedBuildingIds() ?? [],
                ),
            )
            ->orderBy('name')
            ->get();

        $inspections = $buildings->mapWithKeys(fn (Building $building) => [$building->id => $pipeline->inspect($building)]);
        $rows = $buildings->map(fn (Building $building) => [
            'id' => $building->id,
            'name' => $building->name,
            'code' => $building->code,
            'floorAreaM2' => $building->floor_area_m2 === null ? null : (float) $building->floor_area_m2,
            'operatingHours' => $building->operating_hours,
            'notes' => $building->notes,
            'metersCount' => $building->meters->count(),
            'accountRefMasked' => $building->meters->first()?->maskedRef(),
            'meters' => $building->meters->map(fn (Meter $meter) => [
                'id' => $meter->id,
                'accountRefMasked' => $meter->maskedRef(),
                'tariffClass' => $meter->tariff_class,
                'status' => $meter->status,
            ]),
            'verifiedMonths' => $inspections[$building->id]['verified_periods'],
        ]);

        $selectedBuildingCode = $request->query('building');
        abort_unless($selectedBuildingCode === null || (is_string($selectedBuildingCode)
            && $buildings->contains('code', $selectedBuildingCode)), 404);

        return Inertia::render('buildings-meters', [
            'buildings' => $rows,
            'selectedBuildingCode' => $selectedBuildingCode,
            'canManage' => Gate::allows('manage', Building::class),
            'latestVerifiedPeriod' => $buildings->filter(fn (Building $building) => $selectedBuildingCode === null || $building->code === $selectedBuildingCode)
                ->flatMap(fn (Building $building) => array_column($inspections[$building->id]['rows'], 'month'))->max(),
        ]);
    }

    /**
     * Register a building (Admin only, policy `manage`).
     *
     * Attributes per proposal Table 9: name, code (HIGHRISE/TREASURY style),
     * floor area m², operating hours, notes.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Building::class);

        Building::create($this->validateBuilding($request));

        return back();
    }

    public function update(Request $request, Building $building): RedirectResponse
    {
        Gate::authorize('manage', Building::class);

        $building->update($this->validateBuilding($request, $building));

        return to_route('buildings-meters');
    }

    public function destroy(Building $building): RedirectResponse
    {
        Gate::authorize('manage', Building::class);

        if ($building->meters()->exists()) {
            throw ValidationException::withMessages([
                'registry' => 'Remove the registered meters before removing this building.',
            ]);
        }

        $building->delete();

        return to_route('buildings-meters');
    }

    /** @return array<string, mixed> */
    private function validateBuilding(Request $request, ?Building $building = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:32',
                'regex:/^[A-Z0-9_]+$/',
                Rule::unique('buildings', 'code')->ignore($building),
            ],
            'floor_area_m2' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'operating_hours' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);
    }

    /**
     * Register a meter / Meralco account against a building (Admin only).
     *
     * The raw account reference is stored, never returned to the client.
     */
    public function storeMeter(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Building::class);

        $validated = $request->validate([
            'building_id' => ['required', 'integer', Rule::exists('buildings', 'id')->whereNull('deleted_at')],
            'meralco_account_ref' => [
                'required',
                'string',
                'max:255',
                'unique:meters,meralco_account_ref',
            ],
            'tariff_class' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        Meter::create([
            ...$validated,
            'status' => $validated['status'] ?? 'active',
        ]);

        return back();
    }

    public function updateMeter(Request $request, Meter $meter): RedirectResponse
    {
        Gate::authorize('manage', Building::class);

        // Leave a blank replacement reference unchanged; never prefill the raw value.
        $validated = $request->validate([
            'meralco_account_ref' => ['nullable', 'string', 'max:255', Rule::unique('meters', 'meralco_account_ref')->ignore($meter)],
            'tariff_class' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        if (($validated['meralco_account_ref'] ?? null) === null) {
            unset($validated['meralco_account_ref']);
        }

        $meter->update($validated);

        return back();
    }

    public function destroyMeter(Meter $meter): RedirectResponse
    {
        Gate::authorize('manage', Building::class);

        DB::transaction(function () use ($meter) {
            // Intake locks the same meter before inserting its first record.
            $current = Meter::lockForUpdate()->findOrFail($meter->id);
            if ($current->monthlyRecords()->exists()) {
                throw ValidationException::withMessages(['registry' => 'This meter has billing history. Mark it inactive instead of removing it.']);
            }
            $current->delete();
        });

        return back();
    }
}
