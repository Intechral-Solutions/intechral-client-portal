<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Permissions (must run before roles)
        $this->call(PermissionSeeder::class);

        // 2. Built-in roles, and 3. dev-only seed data: disposable environments only. Elsewhere the built-in roles are
        // never reset by a seeder (decision H4); they are synchronized with `php artisan roles:sync`.
        if (app()->environment('local', 'testing')) {
            $this->call(RoleSeeder::class);
            $this->call(DevSeeder::class);
        } else {
            $this->command?->warn('Built-in roles were not seeded. Preview and apply them with `php artisan roles:sync`.');
        }
    }
}
