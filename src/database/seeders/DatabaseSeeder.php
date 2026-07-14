<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Permissions (must run before roles)
        $this->call(PermissionSeeder::class);

        // 2. Built-in roles
        $this->call(RoleSeeder::class);

        // 3. Dev-only seed data
        if (app()->environment('local', 'testing')) {
            $this->call(DevSeeder::class);
        }
    }
}
