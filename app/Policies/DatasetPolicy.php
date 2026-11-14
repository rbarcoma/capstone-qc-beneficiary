<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Dataset;
use App\Models\User;

class DatasetPolicy
{
    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::EnergyManager);
    }

    public function view(User $user, Dataset $dataset): bool
    {
        return $dataset->building !== null && $user->hasBuildingAccess($dataset->building);
    }
}
