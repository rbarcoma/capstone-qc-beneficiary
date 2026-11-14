<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Building;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Idempotent: creates the initial admin only when no admin exists, so
     * running this against existing local data is safe. Credentials come
     * from env (ADMIN_NAME / ADMIN_EMAIL / ADMIN_PASSWORD) with documented
     * local-dev defaults. Pilot buildings (HIGHRISE, TREASURY) are
     * configuration data per docs/DATABASE_DESIGN.md §5 — created only
     * when absent; no billing data is seeded here.
     */
    public function run(): void
    {
        if (! User::where('role', UserRole::Admin)->exists()) {
            User::create([
                'name' => config('seeding.admin.name'),
                'email' => config('seeding.admin.email'),
                'password' => Hash::make(config('seeding.admin.password')),
                'role' => UserRole::Admin,
                'email_verified_at' => now(),
            ]);
        }

        foreach ([['Highrise', 'HIGHRISE'], ['Treasury', 'TREASURY']] as [$name, $code]) {
            Building::withTrashed()->firstOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
