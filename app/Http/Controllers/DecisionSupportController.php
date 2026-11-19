<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Building;
use App\Models\Recommendation;
use App\Models\RecommendationDecision;
use App\Models\User;
use App\Services\DecisionSupportInformation;
use App\Services\RecommendationEvaluator;
use App\Services\RecommendationPolicy;
use App\Services\RecommendationReadiness;
use App\Services\RecommendationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DecisionSupportController extends Controller
{
    public function index(Request $request, DecisionSupportInformation $information, RecommendationReadiness $readiness, RecommendationPolicy $policy): Response
    {
        /** @var User $user */
        $user = $request->user();
        $buildings = Building::query()->when($user->role->isBuildingRestricted(), fn ($query) => $query->whereIn('id', $user->allowedBuildingIds() ?? []))->orderBy('name')->get();
        $code = $request->query('building', $buildings->first()?->code);
        abort_unless($code === null || (is_string($code) && $buildings->contains('code', $code)), 404);
        $building = $buildings->keyBy('code')->get($code ?? '');
        $buildingId = $building === null ? 0 : $building->id;
        $information = $building ? $information->read($building, $user) : null;
        $data = $information['forecast'] ?? null;
        $targetMonth = $data['current']['targetMonth'] ?? null;
        $activation = $building ? $policy->resolve($building, $targetMonth, $user) : null;
        $contract = $policy->reviewContract($activation);
        $available = $contract['fingerprint'] !== null && $targetMonth !== null;
        $query = Recommendation::query()->whereHas('forecast', fn ($query) => $query->where('building_id', $buildingId))
            ->when($user->hasRole(UserRole::FacilitiesPersonnel), fn ($query) => $query->where('contains_cost_data', false)->where('rule_code', '!=', 'R1'));
        $current = (clone $query)->where('forecast_id', $data['current']['id'] ?? 0)
            ->where('source_fingerprint', $data['eligibility']['datasetFingerprint'] ?? '')
            ->where('evaluation_status', 'evaluated')->where('policy_fingerprint', $contract['fingerprint'])->whereIn('rule_code', $contract['rules']);
        $recommendations = $query->with(['forecast', 'action.owner', 'decisions' => fn ($query) => $query->latest('id')])->latest('id')->paginate(15)->withQueryString();
        $recommendations->through(function (Recommendation $item) use ($user, $data, $readiness, $contract) {
            $sourceCurrent = ($data['current']['id'] ?? null) === $item->forecast_id && $item->source_fingerprint === $item->forecast->dataset_fingerprint;
            $policyCurrent = $item->evaluation_status === 'evaluated' && $contract['fingerprint'] === $item->policy_fingerprint && in_array($item->rule_code, $contract['rules'], true);
            $reason = $readiness->reason($item, $data['current']['id'] ?? null, $contract);

            return ['id' => $item->id, 'version' => $item->version, 'ruleCode' => $item->rule_code, 'title' => $item->title,
                'subjectKey' => $item->subject_key,
                'targetMonth' => $item->forecast->target_month->toDateString(), 'forecastId' => $item->forecast_id,
                'policyCurrent' => $policyCurrent, 'priority' => $sourceCurrent && $policyCurrent ? $item->priority : null, 'message' => $sourceCurrent ? $item->message : null,
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
            'comparison' => $information['comparison'] ?? null, 'readiness' => $information['readiness'] ?? null,
            'exploratoryHistory' => $information['exploratoryHistory'] ?? null,
            'conservationCatalogue' => $information['conservationCatalogue'] ?? null,
            'historicalRates' => $information['historicalRates'] ?? null,
            'policyAvailable' => $available, 'recommendations' => $recommendations, 'owners' => $owners,
            'buildingId' => $building?->id,
            'evaluation' => ['available' => ($activation['state'] ?? null) === 'active' && ($information['comparison']['state'] ?? null) === 'available',
                'tariffActive' => ($information['historicalRates'] ?? null) !== null && isset($activation['tariffs']['R1']),
                'canEvaluate' => $building instanceof Building
                && $user->hasRole(UserRole::Admin, UserRole::EnergyManager) && $user->canAccessModule('decision_support'),
                'reason' => ($activation['state'] ?? null) === 'active' && ($information['comparison']['state'] ?? null) !== 'available'
                    ? 'A current verified comparison is required.' : ($activation['reason'] ?? 'Select a building before evaluating.')],
            'counts' => ['evaluated' => $available ? (clone $current)->count() : 0,
                'high' => $available ? (clone $current)->where('priority', 'high')->count() : 0,
                'accepted' => $available ? (clone $current)->where('decision', 'accepted')->count() : 0],
            'today' => now('Asia/Manila')->toDateString(), 'operationResult' => $request->session()->get('decisionResult'),
            'dssAccess' => ['configuration' => $user->hasRole(UserRole::Admin, UserRole::EnergyManager, UserRole::BudgetOfficer),
                'billingEvidence' => $user->hasRole(UserRole::Admin, UserRole::EnergyManager) && $user->canAccessModule('verification_queue')],
        ]);
    }

    public function decide(Request $request, Recommendation $recommendation, RecommendationWorkflow $workflow): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workflow->decide($recommendation, $user, $request->all());

        return back()->with('decisionResult', 'Decision recorded and audited. Acceptance creates one assigned action.');
    }

    public function evaluate(Request $request, Building $building, RecommendationEvaluator $evaluator): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $evaluator->evaluate($building, $user);

        return back()->with('decisionResult', $result['message']);
    }
}
