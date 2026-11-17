<?php

namespace App\Http\Controllers;

use App\Models\Action;
use App\Models\ActionUpdate;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\User;
use App\Services\ActionLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ActionTrackerController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $buildings = Building::query()->when($user->role->isBuildingRestricted(), fn ($query) => $query->whereIn('id', $user->allowedBuildingIds() ?? []))->orderBy('name')->get(['id', 'code', 'name']);
        $filters = $request->validate(['building' => ['nullable', 'string', Rule::in($buildings->pluck('code')->all())],
            'status' => ['nullable', Rule::in(Action::STATUSES)], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        $query = Action::query()->forUser($user);
        if (! empty($filters['building'])) {
            $query->whereHas('recommendation.forecast.building', fn ($query) => $query->where('code', $filters['building']));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return Inertia::render('action-tracker', [
            'buildings' => $buildings, 'filters' => ['building' => $filters['building'] ?? '', 'status' => $filters['status'] ?? ''],
            'metrics' => ['tracked' => (clone $query)->count(), 'open' => (clone $query)->where('status', 'open')->count(), 'completed' => (clone $query)->where('status', 'completed')->count()],
            'actions' => $query->with(['owner:id,name', 'recommendation.forecast.building'])
                ->withExists(['recommendation as has_acceptance' => fn ($query) => $query->whereHas('decisions', fn ($query) => $query->where('to_state', 'accepted'))])
                ->latest('id')->paginate(20)->withQueryString()->through(fn (Action $action) => [
                    'id' => $action->id, 'version' => $action->version, 'title' => $action->title, 'status' => $action->status, 'outcome' => $action->outcome,
                    'ownerName' => $action->owner_id === null ? 'Former user' : $action->owner->name, 'dueDate' => $action->due_date->toDateString(),
                    'completedAt' => $action->completed_at?->toIso8601String(),
                    'ruleCode' => $action->recommendation->rule_code, 'recommendationId' => $action->recommendation_id,
                    'forecastId' => $action->recommendation->forecast_id, 'targetMonth' => $action->recommendation->forecast->target_month->toDateString(),
                    'buildingName' => $action->recommendation->forecast->building->name,
                    'canUpdate' => $user->can('update', $action) && $action->status === 'open' && $action->completed_at === null && $action->has_acceptance,
                ]),
            'operationResult' => $request->session()->get('actionResult'),
        ]);
    }

    public function update(Request $request, Action $action, ActionLifecycle $lifecycle): RedirectResponse
    {
        $this->authorizeAction('update', $action, $request);
        /** @var User $user */
        $user = $request->user();
        $lifecycle->update($action, $user, $request->all());

        return back()->with('actionResult', 'Action follow-up recorded and audited. No energy saving is automatically attributed.');
    }

    public function history(Request $request, Action $action): JsonResponse
    {
        $this->authorizeAction('view', $action, $request);

        return response()->json($action->updates()->with('actor:id,name')->latest('id')->paginate(10)->through(fn (ActionUpdate $update) => [
            'id' => $update->id, 'actorName' => $update->actor_id === null ? 'Former user' : $update->actor->name,
            'actorId' => $update->actor_id, 'createdAt' => $update->created_at?->toIso8601String(),
            'version' => $update->action_version, 'before' => $update->before, 'after' => $update->after,
        ]))->header('Cache-Control', 'private, no-store');
    }

    private function authorizeAction(string $ability, Action $action, Request $request): void
    {
        if (Gate::denies($ability, $action)) {
            AuditLog::create(['actor_id' => $request->user()?->id, 'event' => 'authorization.denied', 'reason' => 'action.'.$ability, 'after' => ['action_id' => $action->id]]);
        }
        Gate::authorize($ability, $action);
    }
}
