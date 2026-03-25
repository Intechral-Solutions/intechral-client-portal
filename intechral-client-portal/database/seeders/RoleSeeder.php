<?php

namespace Database\Seeders;

use App\Shared\Permissions\PermissionCatalogue;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ── Operator role (all permissions) ──────────────────
        $operator = Role::firstOrCreate(['name' => 'operator', 'guard_name' => 'web']);
        $operator->syncPermissions(Permission::all());

        // ── User role (minimal default permissions) ───────────
        $user = Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
        $user->syncPermissions(PermissionCatalogue::userDefaults());

        $this->command->info('Built-in roles seeded: operator, user.');
    }
}
