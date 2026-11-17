<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Action;
use App\Models\User;

class ActionPolicy
{
    public function view(User $user, Action $action): bool
    {
        return $action->recommendation->decision === 'accepted' && $user->can('view', $action->recommendation);
    }

    public function update(User $user, Action $action): bool
    {
        // Proposal Table 13 and Table 14 step 8 place action follow-through with EM.
        return $user->hasRole(UserRole::EnergyManager) && $this->view($user, $action);
    }
}
