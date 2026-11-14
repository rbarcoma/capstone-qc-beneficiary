<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Building;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildingAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_facilities_personnel_is_restricted_to_assigned_buildings(): void
    {
        $highrise = Building::create(['name' => 'Highrise', 'code' => 'HIGHRISE']);
        $treasury = Building::create(['name' => 'Treasury', 'code' => 'TREASURY']);

        $user = User::factory()->role(UserRole::FacilitiesPersonnel)->create();
        $user->buildings()->attach($highrise);

        $this->assertTrue($user->hasBuildingAccess($highrise));
        $this->assertTrue($user->hasBuildingAccess($highrise->id));
        $this->assertFalse($user->hasBuildingAccess($treasury));
        $this->assertSame([$highrise->id], $user->allowedBuildingIds());
    }

    public function test_admin_is_not_building_restricted(): void
    {
        $treasury = Building::create(['name' => 'Treasury', 'code' => 'TREASURY']);

        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->assertTrue($admin->hasBuildingAccess($treasury));
        $this->assertNull($admin->allowedBuildingIds());
    }

    public function test_viewer_is_not_building_restricted_per_proposed_matrix(): void
    {
        $highrise = Building::create(['name' => 'Highrise', 'code' => 'HIGHRISE']);

        $viewer = User::factory()->role(UserRole::Viewer)->create();

        $this->assertTrue($viewer->hasBuildingAccess($highrise));
        $this->assertNull($viewer->allowedBuildingIds());
    }
}
