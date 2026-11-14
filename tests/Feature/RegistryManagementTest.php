<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Meter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_building_metadata_and_keep_its_code(): void
    {
        $building = Building::factory()->create(['code' => 'HIGHRISE']);

        $this->actingAs(User::factory()->admin()->create())
            ->patch("/buildings/{$building->id}", [
                'name' => 'Highrise Building', 'code' => 'HIGHRISE',
                'floor_area_m2' => '1234.56', 'operating_hours' => '08:00–17:00', 'notes' => 'Updated metadata',
            ])->assertRedirect('/buildings-meters')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('buildings', [
            'id' => $building->id, 'name' => 'Highrise Building', 'floor_area_m2' => '1234.56',
            'operating_hours' => '08:00–17:00', 'notes' => 'Updated metadata',
        ]);
    }

    public function test_building_update_rejects_duplicate_codes_and_invalid_metadata(): void
    {
        Building::factory()->create(['code' => 'HIGHRISE']);
        $building = Building::factory()->create(['code' => 'TREASURY']);

        $this->actingAs(User::factory()->admin()->create())
            ->patch("/buildings/{$building->id}", [
                'name' => '', 'code' => 'HIGHRISE', 'floor_area_m2' => -1,
            ])->assertSessionHasErrors(['name', 'code', 'floor_area_m2']);

        $this->assertSame('TREASURY', $building->fresh()->code);
    }

    public function test_building_removal_requires_meters_to_be_removed_first_and_preserves_rows(): void
    {
        $meter = Meter::factory()->create();
        $building = $meter->building;
        $user = User::factory()->admin()->create();
        $user->buildings()->attach($building);

        $this->actingAs($user)->delete("/buildings/{$building->id}")
            ->assertSessionHasErrors('registry');
        $this->assertNotSoftDeleted($building);
        $this->assertNotSoftDeleted($meter);

        $this->delete("/meters/{$meter->id}")->assertRedirect();
        $this->delete("/buildings/{$building->id}")->assertRedirect('/buildings-meters');
        $this->assertSoftDeleted($meter);
        $this->assertSoftDeleted($building);
        $this->assertDatabaseHas('user_building', ['user_id' => $user->id, 'building_id' => $building->id]);
    }

    public function test_removed_registry_entries_are_not_listed_or_editable(): void
    {
        $building = Building::factory()->create();
        $meter = Meter::factory()->create(['building_id' => $building->id]);
        $meter->delete();
        $building->delete();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/buildings-meters')->assertInertia(fn ($page) => $page->has('buildings', 0));
        $this->patch("/buildings/{$building->id}", [])->assertNotFound();
        $this->delete("/buildings/{$building->id}")->assertNotFound();
        $this->patch("/meters/{$meter->id}", [])->assertNotFound();
        $this->delete("/meters/{$meter->id}")->assertNotFound();
    }

    public function test_admin_can_update_meter_without_resending_raw_reference(): void
    {
        $meter = Meter::factory()->create(['meralco_account_ref' => 'TEST-PRIVATE-1234']);

        $this->actingAs(User::factory()->admin()->create())
            ->patch("/meters/{$meter->id}", [
                'meralco_account_ref' => '', 'tariff_class' => 'Commercial', 'status' => 'inactive',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('meters', [
            'id' => $meter->id, 'meralco_account_ref' => 'TEST-PRIVATE-1234',
            'tariff_class' => 'Commercial', 'status' => 'inactive',
        ]);
    }

    public function test_meter_reference_can_be_replaced_but_duplicates_and_invalid_status_are_rejected(): void
    {
        $meter = Meter::factory()->create();
        Meter::factory()->create(['meralco_account_ref' => 'DUPLICATE-5678']);
        $this->actingAs(User::factory()->admin()->create())
            ->patch("/meters/{$meter->id}", ['meralco_account_ref' => 'REPLACED-1234', 'status' => 'active'])
            ->assertSessionHasNoErrors();
        $this->assertSame('REPLACED-1234', $meter->fresh()->meralco_account_ref);

        $this->patch("/meters/{$meter->id}", ['meralco_account_ref' => 'DUPLICATE-5678', 'status' => 'unknown'])
            ->assertSessionHasErrors(['meralco_account_ref', 'status']);
        $this->assertSame('REPLACED-1234', $meter->fresh()->meralco_account_ref);
    }

    public function test_all_non_admin_roles_are_denied_every_registry_write(): void
    {
        $building = Building::factory()->create();
        $meter = Meter::factory()->create(['building_id' => $building->id]);

        foreach (['energy_manager', 'facilities_personnel', 'budget_officer', 'viewer'] as $role) {
            $user = User::factory()->role($role)->create();
            $user->buildings()->attach($building);
            $this->actingAs($user);
            $this->post('/buildings', [])->assertForbidden();
            $this->post('/meters', [])->assertForbidden();
            $this->patch("/buildings/{$building->id}", [])->assertForbidden();
            $this->delete("/buildings/{$building->id}")->assertForbidden();
            $this->patch("/meters/{$meter->id}", [])->assertForbidden();
            $this->delete("/meters/{$meter->id}")->assertForbidden();
        }

        $this->assertNotSoftDeleted($building);
        $this->assertNotSoftDeleted($meter);
    }

    public function test_guests_cannot_modify_registry_entries(): void
    {
        $meter = Meter::factory()->create();
        $this->patch("/buildings/{$meter->building_id}", [])->assertRedirect('/login');
        $this->delete("/buildings/{$meter->building_id}")->assertRedirect('/login');
        $this->patch("/meters/{$meter->id}", [])->assertRedirect('/login');
        $this->delete("/meters/{$meter->id}")->assertRedirect('/login');
    }

    public function test_registry_lists_every_meter_masked_and_exposes_management_capability_by_role(): void
    {
        $building = Building::factory()->create();
        Meter::factory()->create(['building_id' => $building->id, 'meralco_account_ref' => 'SECRET-FIRST-1234']);
        Meter::factory()->create(['building_id' => $building->id, 'meralco_account_ref' => 'SECRET-SECOND-5678']);

        foreach (['admin' => true, 'energy_manager' => false, 'facilities_personnel' => false] as $role => $canManage) {
            $user = User::factory()->role($role)->create();
            $user->buildings()->attach($building);
            $response = $this->actingAs($user)->get('/buildings-meters');
            $response->assertInertia(fn ($page) => $page
                ->where('canManage', $canManage)->has('buildings.0.meters', 2)
                ->where('buildings.0.meters.0.accountRefMasked', '•••• 1234')
                ->where('buildings.0.meters.1.accountRefMasked', '•••• 5678'));
            $this->assertStringNotContainsString('SECRET-FIRST-1234', $response->getContent());
            $this->assertStringNotContainsString('SECRET-SECOND-5678', $response->getContent());
        }
    }

    public function test_short_account_references_are_completely_masked(): void
    {
        $this->assertSame('••••', (new Meter(['meralco_account_ref' => '1234']))->maskedRef());
        $this->assertSame('••••', (new Meter(['meralco_account_ref' => '1']))->maskedRef());
    }

    public function test_registry_filter_cannot_select_an_unassigned_or_invalid_building(): void
    {
        $assigned = Building::factory()->create(['code' => 'HIGHRISE']);
        Building::factory()->create(['code' => 'TREASURY']);
        $user = User::factory()->role('facilities_personnel')->create();
        $user->buildings()->attach($assigned);

        $this->actingAs($user)->get('/buildings-meters?building=HIGHRISE')
            ->assertInertia(fn ($page) => $page->where('selectedBuildingCode', 'HIGHRISE')->has('buildings', 1));
        $this->get('/buildings-meters?building=TREASURY')->assertNotFound();
        $this->get('/buildings-meters?building[]=HIGHRISE')->assertNotFound();
    }

    public function test_removed_buildings_cannot_receive_new_meters_and_codes_stay_reserved(): void
    {
        $building = Building::factory()->create(['code' => 'HIGHRISE']);
        $building->delete();

        $this->actingAs(User::factory()->admin()->create())
            ->post('/meters', ['building_id' => $building->id, 'meralco_account_ref' => 'TEST-1234'])
            ->assertSessionHasErrors('building_id');
        $this->post('/buildings', ['name' => 'Duplicate', 'code' => 'HIGHRISE'])->assertSessionHasErrors('code');
        $this->assertDatabaseCount('meters', 0);
    }

    public function test_seeding_does_not_recreate_a_removed_pilot(): void
    {
        $this->seed();
        $building = Building::where('code', 'HIGHRISE')->firstOrFail();
        $building->delete();
        $this->seed();
        $this->assertDatabaseCount('buildings', 2);
        $this->assertSoftDeleted($building);
    }
}
