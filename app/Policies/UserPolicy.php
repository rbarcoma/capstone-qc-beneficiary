<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole(UserRole::Admin) && $actor->canAccessModule('users_access');
    }

    public function view(User $actor, User $subject): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(User $actor, User $subject): bool
    {
        return $this->viewAny($actor);
    }

    public function changeStatus(User $actor, User $subject): bool
    {
        return $this->viewAny($actor) && $actor->id !== $subject->id;
    }
}
