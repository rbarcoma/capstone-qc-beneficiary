<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_facilities_dashboard_only_exposes_assigned_buildings_and_counts(): void
    {
        $assigned = Building::factory()->create(['code' => 'HIGHRISE', 'name' => 'Assigned Building']);
        Building::factory()->create(['code' => 'TREASURY', 'name' => 'Unassigned Building']);
        $user = User::factory()->role('facilities_personnel')->create();
        $user->buildings()->attach($assigned);

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->has('buildings', 1)->where('buildings.0.code', 'HIGHRISE')
            ->where('kpis.pilotBuildings', 1)->where('kpis.verifiedMonths', 0)
            ->has('readiness', 1)->where('readiness.0.building', 'Assigned Building'));
        $this->get('/dashboard?building=TREASURY')->assertNotFound();
    }

    public function test_facilities_without_assignments_has_empty_dashboard(): void
    {
        Building::factory()->create();
        $this->actingAs(User::factory()->role('facilities_personnel')->create())
            ->get('/dashboard')->assertInertia(fn ($page) => $page
            ->has('buildings', 0)->has('readiness', 0)->where('kpis.pilotBuildings', 0));
    }

    public function test_dashboard_uses_real_counts_and_excludes_removed_buildings(): void
    {
        Building::factory()->count(2)->create();
        Building::factory()->create()->delete();

        $this->actingAs(User::factory()->admin()->create())->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('buildings', 2)->has('readiness', 2)
                ->where('kpis.pilotBuildings', 2)->where('kpis.verifiedMonths', 0));
    }
}
