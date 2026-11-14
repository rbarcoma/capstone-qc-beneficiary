<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Meter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildingsRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_registry_with_real_rows(): void
    {
        $building = Building::factory()->create([
            'name' => 'Highrise Building',
            'code' => 'HIGHRISE',
        ]);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get('/buildings-meters');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('buildings-meters')
            ->has('buildings', 1)
            ->where('buildings.0.code', 'HIGHRISE')
            ->where('buildings.0.accountRefMasked', null));
    }

    public function test_energy_manager_sees_registry(): void
    {
        Building::factory()->create();

        $this->actingAs(User::factory()->role('energy_manager')->create())
            ->get('/buildings-meters')
            ->assertOk();
    }

    public function test_facilities_sees_only_assigned_buildings(): void
    {
        $assigned = Building::factory()->create(['code' => 'HIGHRISE']);
        Building::factory()->create(['code' => 'TREASURY']);

        $user = User::factory()->role('facilities_personnel')->create();
        $user->buildings()->attach($assigned->id);

        $this->actingAs($user)
            ->get('/buildings-meters')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('buildings', 1)
                ->where('buildings.0.code', 'HIGHRISE'));
    }

    public function test_facilities_without_assignments_sees_empty_registry(): void
    {
        Building::factory()->create();

        $this->actingAs(User::factory()->role('facilities_personnel')->create())
            ->get('/buildings-meters')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('buildings', 0));
    }

    public function test_budget_officer_and_viewer_are_denied(): void
    {
        foreach (['budget_officer', 'viewer'] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/buildings-meters')
                ->assertForbidden();
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/buildings-meters')->assertRedirect('/login');
    }

    public function test_admin_can_register_a_building(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())
            ->post('/buildings', [
                'name' => 'Annex Building',
                'code' => 'ANNEX',
                'floor_area_m2' => '1250.5',
                'operating_hours' => '08:00-18:00',
                'notes' => 'Pilot candidate',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('buildings', [
            'code' => 'ANNEX',
            'name' => 'Annex Building',
            'floor_area_m2' => 1250.5,
        ]);
    }

    public function test_building_code_must_be_uppercase_and_unique(): void
    {
        Building::factory()->create(['code' => 'HIGHRISE']);

        $this->actingAs(User::factory()->admin()->create())
            ->from('/buildings-meters')
            ->post('/buildings', ['name' => 'X', 'code' => 'highrise'])
            ->assertSessionHasErrors('code');

        $this->post('/buildings', ['name' => 'X', 'code' => 'HIGHRISE'])
            ->assertSessionHasErrors('code');
    }

    public function test_only_admin_can_register_buildings(): void
    {
        foreach (['energy_manager', 'facilities_personnel'] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->post('/buildings', ['name' => 'X', 'code' => 'NOPE'])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('buildings', 0);
    }

    public function test_admin_can_register_a_meter(): void
    {
        $building = Building::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post('/meters', [
                'building_id' => $building->id,
                'meralco_account_ref' => '25-1234567-89',
                'tariff_class' => 'Commercial',
                'status' => 'active',
            ]);

        $this->assertDatabaseHas('meters', [
            'building_id' => $building->id,
            'meralco_account_ref' => '25-1234567-89',
        ]);
    }

    public function test_meter_requires_existing_building_and_unique_ref(): void
    {
        $building = Building::factory()->create();

        Meter::factory()->create([
            'building_id' => $building->id,
            'meralco_account_ref' => 'REF-1',
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->post('/meters', [
                'building_id' => 99999,
                'meralco_account_ref' => 'REF-1',
            ])
            ->assertSessionHasErrors(['building_id', 'meralco_account_ref']);
    }

    public function test_account_ref_is_masked_never_raw(): void
    {
        $building = Building::factory()->create();
        Meter::factory()->create([
            'building_id' => $building->id,
            'meralco_account_ref' => '25-1234567-89',
        ]);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get('/buildings-meters');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('buildings.0.accountRefMasked', '•••• 7-89')
                ->etc());
        $this->assertStringNotContainsString(
            '25-1234567-89',
            $response->getContent(),
            'Raw Meralco account reference must never reach the frontend.',
        );
    }
}
