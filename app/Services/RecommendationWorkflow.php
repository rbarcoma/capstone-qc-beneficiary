<?php

namespace App\Services;

use App\Models\Action;
use App\Models\AuditLog;
use App\Models\Recommendation;
use App\Models\RecommendationDecision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecommendationWorkflow
{
    /** @param array<string, mixed> $input */
    public function decide(Recommendation $recommendation, User $user, array $input): void
    {
        Gate::forUser($user)->authorize('decide', $recommendation);
        $data = Validator::make($input, [
            'version' => ['required', 'integer', 'min:1'], 'decision' => ['required', 'in:accepted,rejected,deferred'], 'note' => ['required', 'string', 'max:2000'],
            'owner_id' => ['exclude_unless:decision,accepted', 'required', 'integer', 'exists:users,id'],
            'due_date' => ['exclude_unless:decision,accepted', 'required', 'date_format:Y-m-d', 'after_or_equal:'.now('Asia/Manila')->toDateString()],
            'evidence' => ['prohibited'], 'priority' => ['prohibited'], 'evaluation_status' => ['prohibited'], 'forecast_id' => ['prohibited'], 'rule_code' => ['prohibited'],
        ])->validate();

        DB::transaction(function () use ($recommendation, $user, $data) {
            $building = $recommendation->forecast->building;
            app(BuildingDataset::class)->inspectLocked($building);
            $actor = User::lockForUpdate()->findOrFail($user->id);
            $current = Recommendation::lockForUpdate()->findOrFail($recommendation->id);
            Gate::forUser($actor)->authorize('decide', $current);
            if ($current->version !== (int) $data['version']) {
                $this->fail('This recommendation changed. Refresh before deciding.');
            }
            $forecast = app(ForecastRead::class)->read($building, $actor)['current'];
            $reason = app(RecommendationReadiness::class)->reason($current, $forecast['id'] ?? null);
            if ($reason !== null) {
                $this->fail($reason);
            }
            $before = $current->toArray();
            $snapshot = $current->only(['forecast_id', 'rule_code', 'policy_fingerprint', 'source_fingerprint', 'title', 'message', 'evidence', 'priority', 'evaluation_status']);
            $action = null;
            if ($data['decision'] === 'accepted') {
                $owner = User::query()->lockForUpdate()->find((int) $data['owner_id']);
                if (! $owner || ! $owner->can('view', $current)) {
                    throw ValidationException::withMessages(['owner_id' => 'Choose an owner with access to this recommendation and building.']);
                }
                $action = Action::create(['recommendation_id' => $current->id, 'owner_id' => $owner->id, 'title' => $current->title, 'due_date' => $data['due_date'], 'status' => 'open']);
            }
            RecommendationDecision::create(['recommendation_id' => $current->id, 'actor_id' => $actor->id, 'from_state' => $current->decision,
                'to_state' => $data['decision'], 'note' => $data['note'], 'recommendation_version' => $current->version, 'source_snapshot' => $snapshot]);
            $current->update(['decision' => $data['decision'], 'version' => $current->version + 1, 'decided_by' => $actor->id, 'decided_at' => now()]);
            AuditLog::create(['actor_id' => $actor->id, 'forecast_id' => $current->forecast_id, 'event' => 'recommendation.decided', 'before' => $before,
                'after' => ['recommendation' => $current->toArray(), 'action' => $action?->toArray()], 'reason' => $data['note']]);
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['recommendation' => $message]);
    }
}
