<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Building;
use App\Models\Recommendation;
use App\Models\RecommendationDecision;
use App\Models\User;
use App\Services\ForecastRead;
use App\Services\RecommendationReadiness;
use App\Services\RecommendationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DecisionSupportController extends Controller
{
    public function index(Request $request, ForecastRead $forecasts, RecommendationReadiness $readiness): Response
    {
        /** @var User $user */
        $user = $request->user();
        $buildings = Building::query()->when($user->role->isBuildingRestricted(), fn ($query) => $query->whereIn('id', $user->allowedBuildingIds() ?? []))->orderBy('name')->get();
        $code = $request->query('building', $buildings->first()?->code);
        abort_unless($code === null || (is_string($code) && $buildings->contains('code', $code)), 404);
        $building = $buildings->keyBy('code')->get($code ?? '');
        $buildingId = $building === null ? 0 : $building->id;
        $data = $building ? $forecasts->read($building, $user) : null;
        $query = Recommendation::query()->whereHas('forecast', fn ($query) => $query->where('building_id', $buildingId))
            ->when($user->hasRole(UserRole::FacilitiesPersonnel), fn ($query) => $query->where('contains_cost_data', false)->where('rule_code', '!=', 'R1'));
        $current = (clone $query)->where('forecast_id', $data['current']['id'] ?? 0)
            ->where('source_fingerprint', $data['eligibility']['datasetFingerprint'] ?? '')
            ->where('evaluation_status', 'evaluated')->where('policy_fingerprint', config('recommendations.policy_fingerprint'));
        $recommendations = $query->with(['forecast', 'action.owner', 'decisions' => fn ($query) => $query->latest('id')])->latest('id')->paginate(15)->withQueryString();
        $recommendations->through(function (Recommendation $item) use ($user, $data, $readiness) {
            $sourceCurrent = $data !== null && ($data['current']['id'] ?? null) === $item->forecast_id && $item->source_fingerprint === $item->forecast->dataset_fingerprint;
            $reason = $readiness->reason($item, $data['current']['id'] ?? null);

            return ['id' => $item->id, 'version' => $item->version, 'ruleCode' => $item->rule_code, 'title' => $item->title,
                'targetMonth' => $item->forecast->target_month->toDateString(), 'forecastId' => $item->forecast_id,
                'priority' => $sourceCurrent ? $item->priority : null, 'message' => $sourceCurrent ? $item->message : null,
                'evidence' => $sourceCurrent ? $item->evidence : null, 'sourceCurrent' => $sourceCurrent,
                'decision' => $item->decision, 'evaluationStatus' => $item->evaluation_status,
                'canDecide' => $reason === null && $user->can('decide', $item), 'blockedReason' => $reason,
                'history' => $item->decisions->map(fn (RecommendationDecision $decision) => $decision->only(['id', 'actor_id', 'from_state', 'to_state', 'note', 'recommendation_version', 'created_at'])),
                'action' => $item->action ? ['id' => $item->action->id, 'ownerName' => $item->action->owner_id === null ? 'Former user' : $item->action->owner->name, 'dueDate' => $item->action->due_date->toDateString(), 'status' => $item->action->status] : null];
        });
        $owners = $user->hasRole(UserRole::EnergyManager) && $building ? User::query()
            ->where(fn ($query) => $query->where('role', '!=', UserRole::FacilitiesPersonnel->value)->orWhereHas('buildings', fn ($query) => $query->whereKey($building->id)))
            ->orderBy('name')->get()->filter(fn (User $owner) => $owner->canAccessModule('action_tracker'))->map(fn (User $owner) => $owner->only(['id', 'name', 'role']))->values() : [];

        return Inertia::render('decision-support', [
            'buildings' => $buildings->map(fn (Building $item) => $item->only(['id', 'code', 'name'])), 'selectedBuildingCode' => $code,
            'latestVerifiedPeriod' => $data['latestVerifiedPeriod'] ?? null, 'currentForecastId' => $data['current']['id'] ?? null,
            'policyAvailable' => $readiness->policyAvailable(), 'recommendations' => $recommendations, 'owners' => $owners,
            'counts' => ['evaluated' => $readiness->policyAvailable() ? (clone $current)->count() : 0,
                'high' => $readiness->policyAvailable() ? (clone $current)->where('priority', 'high')->count() : 0,
                'accepted' => $readiness->policyAvailable() ? (clone $current)->where('decision', 'accepted')->count() : 0],
            'today' => now('Asia/Manila')->toDateString(), 'operationResult' => $request->session()->get('decisionResult'),
        ]);
    }

    public function decide(Request $request, Recommendation $recommendation, RecommendationWorkflow $workflow): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workflow->decide($recommendation, $user, $request->all());

        return back()->with('decisionResult', 'Decision recorded and audited. Acceptance creates one assigned action.');
    }
}
