<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            AiSeeder::class,
        ]);

        $admin = User::query()->firstOrCreate(
            ['email' => config('settings.admin.email')],
            [
                'name' => config('settings.admin.name'),
                'password' => config('settings.admin.password'),
                'email_verified_at' => now(),
            ],
        );

        $admin->assignRole(Permissions::SUPER_ADMIN);

        if (app()->environment('local')) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ])->assignRole(Permissions::USER);
        }
    }
}
