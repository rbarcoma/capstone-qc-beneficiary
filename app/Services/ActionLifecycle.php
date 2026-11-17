<?php

namespace App\Services;

use App\Models\Action;
use App\Models\ActionUpdate;
use App\Models\AuditLog;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ActionLifecycle
{
    /** @param array<string, mixed> $input */
    public function update(Action $action, User $user, array $input): void
    {
        Gate::forUser($user)->authorize('update', $action);
        $data = Validator::make($input, [
            'version' => ['required', 'integer', 'min:1'], 'status' => ['required', Rule::in(Action::STATUSES)],
            'outcome' => ['required', 'string', 'max:4000', 'regex:/\S/u'],
            'recommendation_id' => ['prohibited'], 'owner_id' => ['prohibited'], 'due_date' => ['prohibited'],
            'title' => ['prohibited'], 'completed_at' => ['prohibited'], 'forecast_id' => ['prohibited'],
        ])->validate();

        DB::transaction(function () use ($action, $user, $data) {
            // Same relative order as P7: actor → recommendation → action.
            $actor = User::lockForUpdate()->findOrFail($user->id);
            $recommendation = Recommendation::lockForUpdate()->findOrFail($action->recommendation_id);
            $current = Action::lockForUpdate()->findOrFail($action->id);
            $current->setRelation('recommendation', $recommendation);
            Gate::forUser($actor)->authorize('update', $current);
            if ($current->version !== (int) $data['version']) {
                $this->fail('This action changed. Refresh before recording follow-up.');
            }
            if ($current->status !== 'open' || $current->completed_at !== null) {
                $this->fail('Only open actions can be updated. Completed history cannot be overwritten.');
            }
            if (! $recommendation->decisions()->where('to_state', 'accepted')->exists()) {
                $this->fail('A retained accepted recommendation decision is required for action follow-up.');
            }
            // Tracking an already accepted action is factual follow-through, not
            // new advice: it does not require a still-current forecast or policy.
            $before = $current->snapshot();
            $current->update(['status' => $data['status'], 'outcome' => $data['outcome'], 'version' => $current->version + 1,
                'completed_at' => $data['status'] === 'completed' ? now() : null]);
            $after = $current->snapshot();
            ActionUpdate::create(['action_id' => $current->id, 'actor_id' => $actor->id, 'action_version' => $before['version'], 'before' => $before, 'after' => $after]);
            AuditLog::create(['actor_id' => $actor->id, 'forecast_id' => $recommendation->forecast_id,
                'event' => 'action.updated', 'before' => $before, 'after' => $after, 'reason' => $data['outcome']]);
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['action' => $message]);
    }
}
