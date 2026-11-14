<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Recommendation;
use App\Models\User;

class RecommendationPolicy
{
    public function view(User $user, Recommendation $recommendation): bool
    {
        return $recommendation->forecast !== null && $user->can('view', $recommendation->forecast)
            && (! $user->hasRole(UserRole::FacilitiesPersonnel) || (! $recommendation->contains_cost_data && $recommendation->rule_code !== 'R1'));
    }

    public function decide(User $user, Recommendation $recommendation): bool
    {
        return $user->hasRole(UserRole::EnergyManager) && $this->view($user, $recommendation);
    }
}
