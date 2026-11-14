<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\User;
use App\Services\MonthlyRecordIntake;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataManagementController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $buildings = Building::query()->with('meters')->when($user->role->isBuildingRestricted(),
            fn ($query) => $query->whereIn('id', $user->allowedBuildingIds() ?? []))
            ->orderBy('name')->get();
        $selected = $request->query('building', $buildings->first()?->code);
        abort_unless($selected === null || (is_string($selected) && $buildings->contains('code', $selected)), 404);
        $building = $buildings->keyBy('code')->get($selected ?? '');
        $records = MonthlyRecord::query()->forUser($user)->whereHas('meter', fn ($query) => $query->where('building_id', $building->id ?? 0))
            ->with('meter')->orderByDesc('month')->orderBy('meter_id')->paginate(25)->withQueryString();

        return Inertia::render('data-management', [
            'buildings' => $buildings->map(fn (Building $item) => [
                'id' => $item->id, 'code' => $item->code, 'name' => $item->name,
                'meters' => $item->meters->map(fn (Meter $meter) => ['id' => $meter->id, 'label' => $meter->maskedRef().' · '.$meter->status]),
            ]),
            'selectedBuildingCode' => $selected,
            'latestVerifiedPeriod' => MonthlyRecord::query()->forUser($user)->verified()
                ->whereHas('meter', fn ($query) => $query->where('building_id', $building->id ?? 0))->max('month'),
            'records' => $records->through(fn (MonthlyRecord $record) => $record->reviewData($user)),
            'intakeResult' => $request->session()->get('intakeResult'),
            'demoRequested' => $request->boolean('demo'),
        ]);
    }

    public function import(Request $request, MonthlyRecordIntake $intake): RedirectResponse
    {
        Gate::authorize('create', MonthlyRecord::class);
        $request->validate(['csv' => ['required', 'file', 'max:2048', 'extensions:csv', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel']]);
        /** @var UploadedFile $file */
        $file = $request->file('csv');
        /** @var User $user */
        $user = $request->user();

        return back()->with('intakeResult', $intake->import($file, $user));
    }

    public function store(Request $request, MonthlyRecordIntake $intake): RedirectResponse
    {
        Gate::authorize('create', MonthlyRecord::class);
        $request->validate(['meter_id' => ['required', 'integer']]);
        $meter = Meter::with('building')->findOrFail($request->integer('meter_id'));
        /** @var User $user */
        $user = $request->user();
        abort_unless($meter->building && $user->hasBuildingAccess($meter->building), 403);
        $row = [...$request->only(['month', 'kwh', 'billed_demand_kw', 'cost_php', 'power_factor', 'notes']), 'building_code' => $meter->building->code];

        return back()->with('intakeResult', $intake->storeRows([1 => $row], $user, 'manual', $meter->id));
    }

    public function template(): StreamedResponse
    {
        Gate::authorize('create', MonthlyRecord::class);

        return response()->streamDownload(function () {
            $stream = fopen('php://output', 'w');
            if ($stream !== false) {
                fputcsv($stream, MonthlyRecordIntake::COLUMNS, ',', '"', '');
                fclose($stream);
            }
        }, 'qc-monthly-records-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
