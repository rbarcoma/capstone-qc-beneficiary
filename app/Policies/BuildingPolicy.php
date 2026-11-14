<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Building;
use App\Models\User;

/**
 * Entity-level authorization for buildings (docs/ROLES_AND_PERMISSIONS.md §3).
 *
 * Route-level role middleware gates the registry; this policy enforces
 * per-building restriction for building-restricted roles (Facilities
 * Personnel) and admin-only management. Never replace with frontend checks.
 */
class BuildingPolicy
{
    /**
     * Registry viewing: Admin and Energy Manager see all buildings;
     * building-restricted roles see only assigned buildings.
     */
    public function view(User $user, Building $building): bool
    {
        return $user->hasBuildingAccess($building);
    }

    /**
     * Creating, editing and deleting registry entries is Admin-only
     * (module M2: "Users: Admin (manage), others view per permissions").
     */
    public function manage(User $user): bool
    {
        return $user->hasRole(UserRole::Admin);
    }
}
