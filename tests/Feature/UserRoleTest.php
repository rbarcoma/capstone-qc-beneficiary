<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Building;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_is_cast_to_the_enum(): void
    {
        $user = User::factory()->role(UserRole::FacilitiesPersonnel)->create();

        $this->assertSame(UserRole::FacilitiesPersonnel, $user->refresh()->role);
    }

    public function test_factory_defaults_to_viewer(): void
    {
        $user = User::factory()->create();

        $this->assertSame(UserRole::Viewer, $user->role);
    }

    public function test_has_role_checks_multiple_roles(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();
        $viewer = User::factory()->role(UserRole::Viewer)->create();

        $this->assertTrue($admin->hasRole(UserRole::Admin, UserRole::EnergyManager));
        $this->assertFalse($viewer->hasRole(UserRole::Admin, UserRole::EnergyManager));
    }

    public function test_only_facilities_personnel_is_building_restricted(): void
    {
        $this->assertFalse(UserRole::Admin->isBuildingRestricted());
        $this->assertFalse(UserRole::EnergyManager->isBuildingRestricted());
        $this->assertTrue(UserRole::FacilitiesPersonnel->isBuildingRestricted());
        $this->assertFalse(UserRole::BudgetOfficer->isBuildingRestricted());
        $this->assertFalse(UserRole::Viewer->isBuildingRestricted());
    }
}

class InertiaAuthSharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_and_allowed_buildings_are_shared_with_inertia(): void
    {
        $building = Building::create(['name' => 'Highrise', 'code' => 'HIGHRISE']);
        $user = User::factory()->role(UserRole::FacilitiesPersonnel)->create();
        $user->buildings()->attach($building);
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('auth.user.role', 'facilities_personnel')
            ->where('auth.user.roleLabel', 'Facilities Personnel')
            ->where('auth.user.allowedBuildingIds', [$building->id]));
    }
}
