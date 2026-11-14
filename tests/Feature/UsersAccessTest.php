<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_users_and_access(): void
    {
        $user = User::factory()->role(UserRole::Admin)->create();
        $this->actingAs($user);

        $response = $this->get(route('users-access'));

        $response->assertInertia(fn ($page) => $page
            ->component('users-access')
            ->has('roles', 4)
            ->has('matrix', 7));
    }

    public function test_non_admin_roles_are_denied_server_side(): void
    {
        foreach (UserRole::cases() as $role) {
            if ($role === UserRole::Admin) {
                continue;
            }

            $user = User::factory()->role($role)->create();
            $this->actingAs($user)
                ->get(route('users-access'))
                ->assertForbidden();
        }
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('users-access'))->assertRedirect(route('login'));
    }
}
