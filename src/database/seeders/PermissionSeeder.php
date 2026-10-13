<?php

namespace Database\Seeders;

use App\Shared\Permissions\PermissionCatalogue;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Additive only (Release 0.9, H4): creates the catalogue's permissions that are missing and removes nothing, so it is
 * safe against any data. Granting them to the built-in roles is `php artisan roles:sync`.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (PermissionCatalogue::all() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->command?->info('Permissions seeded: '.count(PermissionCatalogue::all()).' total.');
    }
}
