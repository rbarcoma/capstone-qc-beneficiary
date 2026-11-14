<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_admin_and_pilot_buildings(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'role' => UserRole::Admin->value]);
        $this->assertDatabaseHas('buildings', ['code' => 'HIGHRISE']);
        $this->assertDatabaseHas('buildings', ['code' => 'TREASURY']);
    }

    public function test_seeder_is_idempotent_and_does_not_duplicate_admin(): void
    {
        User::factory()->role(UserRole::Admin)->create(['email' => 'other-admin@example.com']);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::where('role', UserRole::Admin)->count());
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
    }
}
