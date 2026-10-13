<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Release 0.9 (R0.9-1D, decision H4): `RoleSeeder` is not an uncontrolled production synchronization. In a disposable
 * (local or testing) environment it still resets the built-in roles to the code; anywhere else it refuses.
 */

function inEnvironment(string $environment, Closure $callback): void
{
    $original = app()->environment();
    app()->detectEnvironment(fn () => $environment);

    try {
        $callback();
    } finally {
        app()->detectEnvironment(fn () => $original);
    }
}

it('refuses outside local and testing, and changes nothing', function (string $environment) {
    $this->seed(PermissionSeeder::class);
    Role::create(['name' => 'user', 'guard_name' => 'web'])->givePermissionTo('billing.admin');
    $state = [DB::table('roles')->get()->all(), DB::table('role_has_permissions')->get()->all(), Activity::count()];

    inEnvironment($environment, function () {
        expect(fn () => app(RoleSeeder::class)->run())->toThrow(RuntimeException::class, 'roles:sync');
    });

    expect([DB::table('roles')->get()->all(), DB::table('role_has_permissions')->get()->all(), Activity::count()])->toEqual($state)
        ->and(Role::where('name', 'operator')->exists())->toBeFalse();
})->with(['production', 'staging']);

it('seeds only the additive permissions through DatabaseSeeder outside local and testing, no roles and no dev accounts', function () {
    inEnvironment('production', function () {
        (new DatabaseSeeder)->setContainer(app())->run();
    });

    expect(Permission::count())->toBeGreaterThan(0)
        ->and(Role::count())->toBe(0)
        ->and(User::count())->toBe(0);
});

it('still resets the built-in roles in a testing database, without an audit entry, revocations included', function () {
    $this->seedRolesAndPermissions();
    Role::findByName('user', 'web')->givePermissionTo('billing.admin');
    Role::findByName('operator', 'web')->permissions()->detach(Permission::findByName('cms.admin')->id);

    app(RoleSeeder::class)->run();

    expect(Role::findByName('user', 'web')->hasPermissionTo('billing.admin'))->toBeFalse()
        ->and(Role::findByName('operator', 'web')->hasPermissionTo('cms.admin'))->toBeTrue()
        ->and(Activity::where('description', 'synchronized built-in roles')->exists())->toBeFalse();
});

it('does not remove a custom role\'s grants when it resets the built-in roles', function () {
    $this->seedRolesAndPermissions();
    Role::create(['name' => 'support-agent', 'guard_name' => 'web'])->givePermissionTo(['billing.admin', 'tickets.view']);

    app(RoleSeeder::class)->run();

    expect(Role::findByName('support-agent', 'web')->permissions()->pluck('name')->sort()->values()->all())->toBe(['billing.admin', 'tickets.view']);
});
