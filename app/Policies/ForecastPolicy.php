<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Forecast;
use App\Models\User;

class ForecastPolicy
{
    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::EnergyManager);
    }

    public function view(User $user, Forecast $forecast): bool
    {
        return $forecast->building !== null && $user->hasBuildingAccess($forecast->building);
    }
}
