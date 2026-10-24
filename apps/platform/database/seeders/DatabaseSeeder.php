<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Seeders are idempotent: they never recreate users nor delete data.
     * The owner account is created with `php artisan starter:owner`.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            AiSeeder::class,
        ]);
    }
}
