<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\MonthlyRecord;
use App\Models\User;

class MonthlyRecordPolicy
{
    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::EnergyManager, UserRole::FacilitiesPersonnel);
    }

    public function view(User $user, MonthlyRecord $record): bool
    {
        return $this->create($user) && $record->meter?->building !== null
            && $user->hasBuildingAccess($record->meter->building);
    }

    public function verify(User $user, MonthlyRecord $record): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::EnergyManager)
            && ($record->last_edited_by ?? $record->uploaded_by) !== $user->id;
    }

    public function correct(User $user, MonthlyRecord $record): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::EnergyManager);
    }
}
