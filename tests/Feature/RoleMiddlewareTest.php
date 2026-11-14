<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_allows_users_with_a_permitted_role(): void
    {
        Route::get('/test/role-allowed', fn () => 'ok')
            ->middleware(['auth', 'role:admin,energy_manager'])->name('test.role-allowed');

        $user = User::factory()->role(UserRole::EnergyManager)->create();
        $response = $this->actingAs($user)->get('/test/role-allowed');

        $response->assertOk()->assertSee('ok');
    }

    public function test_route_denies_users_without_a_permitted_role(): void
    {
        Route::get('/test/role-denied', fn () => 'ok')
            ->middleware(['auth', 'role:admin'])->name('test.role-denied');

        $user = User::factory()->role(UserRole::Viewer)->create();
        $response = $this->actingAs($user)->get('/test/role-denied');

        $response->assertForbidden();
    }

    public function test_route_denies_guests(): void
    {
        Route::get('/test/role-guest', fn () => 'ok')
            ->middleware(['auth', 'role:admin'])->name('test.role-guest');

        $this->get('/test/role-guest')->assertRedirect(route('login'));
    }
}
