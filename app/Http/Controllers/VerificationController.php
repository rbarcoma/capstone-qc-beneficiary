<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\MonthlyRecordRevision;
use App\Models\User;
use App\Services\ForecastActuals;
use App\Services\MonthlyRecordIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class VerificationController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $buildings = Building::orderBy('name')->get(['id', 'code', 'name']);
        $selected = $request->query('building', $buildings->first()?->code);
        abort_unless($selected === null || (is_string($selected) && $buildings->contains('code', $selected)), 404);
        $buildingId = $buildings->keyBy('code')->get($selected ?? '')->id ?? 0;
        $records = MonthlyRecord::query()->whereHas('meter', fn ($query) => $query->where('building_id', $buildingId));

        return Inertia::render('verification-queue', [
            'buildings' => $buildings, 'selectedBuildingCode' => $selected,
            'latestVerifiedPeriod' => (clone $records)->verified()->max('month'),
            'metrics' => [
                'draft' => (clone $records)->where('verification_status', 'draft')->count(),
                'verified' => (clone $records)->verified()->count(),
                'corrections' => MonthlyRecordRevision::whereIn('monthly_record_id', (clone $records)->select('id'))->count(),
            ],
            'records' => $records->where('verification_status', 'draft')->with('meter')->orderBy('month')->paginate(25)->withQueryString()
                ->through(fn (MonthlyRecord $record) => $record->reviewData($user)),
        ]);
    }

    public function verify(Request $request, MonthlyRecord $record): RedirectResponse
    {
        $this->authorizeAction('verify', $record, $request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'confirmed_against_bill' => ['required', 'accepted']]);
        DB::transaction(function () use ($data, $record, $request) {
            $current = $this->lockForChange($record);
            Gate::authorize('verify', $current);
            $this->checkVersion($current, (int) $data['version']);
            if ($current->verification_status !== 'draft') {
                throw ValidationException::withMessages(['record' => 'This record is already verified. Refresh the page.']);
            }
            $before = $current->snapshot();
            $current->update(['verification_status' => 'verified', 'verified_by' => $request->user()?->id, 'verified_at' => now(), 'version' => $current->version + 1]);
            AuditLog::create(['actor_id' => $request->user()?->id, 'event' => 'monthly_record.verified', 'monthly_record_id' => $current->id, 'before' => $before, 'after' => $current->snapshot()]);
            $this->reconcileActuals($current, $request);
        }, 3);

        return back();
    }

    public function correct(Request $request, MonthlyRecord $record): RedirectResponse
    {
        $this->authorizeAction('correct', $record, $request);
        // Meter assignment is fixed; a corrected month must remain unique.
        $data = $request->validate([
            ...MonthlyRecordIntake::valueRules(),
            'month' => ['sometimes', ...MonthlyRecordIntake::valueRules()['month']],
            'reason' => ['required', 'string', 'max:2000'], 'version' => ['required', 'integer', 'min:1'],
        ]);
        DB::transaction(function () use ($data, $record, $request) {
            $current = $this->lockForChange($record);
            Gate::authorize('correct', $current);
            $this->checkVersion($current, (int) $data['version']);
            if (isset($data['month']) && MonthlyRecord::where('meter_id', $current->meter_id)
                ->where('month', $data['month'])->whereKeyNot($current->id)->exists()) {
                throw ValidationException::withMessages(['month' => 'That meter/month already exists. No record was overwritten.']);
            }
            $before = $current->snapshot();
            $current->update([
                ...Arr::except($data, ['reason', 'version']), 'verification_status' => 'draft',
                'verified_by' => null, 'verified_at' => null, 'last_edited_by' => $request->user()?->id,
                'version' => $current->version + 1,
            ]);
            MonthlyRecordRevision::create([
                'monthly_record_id' => $current->id, 'actor_id' => $request->user()?->id,
                'reason' => $data['reason'], 'before' => $before, 'after' => $current->snapshot(),
            ]);
            AuditLog::create([
                'actor_id' => $request->user()?->id, 'event' => 'monthly_record.corrected', 'monthly_record_id' => $current->id,
                'before' => $before, 'after' => $current->snapshot(), 'reason' => $data['reason'],
            ]);
            $this->reconcileActuals($current, $request);
        }, 3);

        return back();
    }

    public function history(Request $request, MonthlyRecord $record): JsonResponse
    {
        $this->authorizeAction('correct', $record, $request);

        return response()->json($record->revisions()->latest('id')->paginate(10));
    }

    private function authorizeAction(string $action, MonthlyRecord $record, Request $request): void
    {
        if (Gate::denies($action, $record)) {
            AuditLog::create(['actor_id' => $request->user()?->id, 'event' => 'monthly_record.permission_denied', 'monthly_record_id' => $record->id, 'reason' => $action]);
        }
        Gate::authorize($action, $record);
    }

    private function lockForChange(MonthlyRecord $record): MonthlyRecord
    {
        $meter = Meter::findOrFail($record->meter_id);
        // Same order as preprocessing/issuance: building → meter → record.
        Building::lockForUpdate()->findOrFail($meter->building_id);
        Meter::lockForUpdate()->findOrFail($meter->id);

        return MonthlyRecord::lockForUpdate()->findOrFail($record->id);
    }

    private function reconcileActuals(MonthlyRecord $record, Request $request): void
    {
        /** @var User $actor */
        $actor = $request->user();
        $building = $record->meter?->building;
        if ($building) {
            app(ForecastActuals::class)->reconcile($building, $actor);
        }
    }

    private function checkVersion(MonthlyRecord $record, int $version): void
    {
        if ($record->version !== $version) {
            throw ValidationException::withMessages(['record' => 'This record changed since you opened it. Refresh and review the latest values.']);
        }
    }
}
