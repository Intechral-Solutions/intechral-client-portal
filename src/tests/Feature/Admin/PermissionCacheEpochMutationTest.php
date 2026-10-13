<?php

use App\Models\User;
use App\Services\AccessGrants;
use App\Services\BuiltInRoleSynchronizer;
use App\Support\Permissions\EpochPermissionRegistrar;
use App\Support\Permissions\PermissionCacheEpoch;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Release 0.9 (R0.9-1E): what the permission-cache epoch covers, in one process. The race itself, with independent
 * processes, is PermissionCacheEpochTest. Here: that the application really runs the fenced registrar, that every route
 * to the library's loader uses the fenced key, that every kind of change to the shared mapping advances the epoch, that
 * the epoch commits and rolls back with the change that caused it, and what happens when the cache is unavailable.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create()->assignRole('operator');
    $this->support = Role::create(['name' => 'support', 'guard_name' => 'web'])->givePermissionTo(['tickets.view', 'tickets.create']);
    $this->holder = User::factory()->create();
    $this->holder->assignRole($this->support);
    app(PermissionRegistrar::class)->clearPermissionsCollection();
});

function pcmEpoch(): string
{
    return app(PermissionCacheEpoch::class)->current();
}

/** @return array{0: Closure, 1: Closure} what to arrange (not counted) and the change that must advance the epoch */
function pcmScenario(string $name): array
{
    $role = fn () => Role::findByName('support', 'web');
    $none = fn () => null;
    $grants = fn () => app(AccessGrants::class);
    $sync = fn () => app(BuiltInRoleSynchronizer::class);
    $applyPlan = fn () => $sync()->apply($sync()->plan()->fingerprint(), allowRevocations: true);

    return match ($name) {
        'a custom role edited through the service' => [$none, fn () => $grants()->syncRolePermissions($role(), ['tickets.create'])],
        'a custom role edited through the Roles screen' => [$none, fn () => test()->actingAs(test()->operator)->put(route('roles.update', $role()), ['permissions' => ['tickets.create']])->assertSessionHasNoErrors()],
        'a custom role created with permissions through the Roles screen' => [$none, fn () => test()->actingAs(test()->operator)->post(route('roles.store'), ['name' => 'auditors', 'permissions' => ['tickets.view']])->assertSessionHasNoErrors()],
        'a custom role created without permissions' => [$none, fn () => Role::create(['name' => 'empty', 'guard_name' => 'web'])],
        'a custom role deleted through the service' => [fn () => Role::create(['name' => 'doomed', 'guard_name' => 'web'])->givePermissionTo('tickets.view'), fn () => $grants()->deleteUnassignedRole(Role::findByName('doomed', 'web'))],
        'a custom role deleted through the Roles screen' => [fn () => Role::create(['name' => 'doomed', 'guard_name' => 'web']), fn () => test()->actingAs(test()->operator)->delete(route('roles.destroy', Role::findByName('doomed', 'web')))->assertSessionHasNoErrors()],
        'a permission created' => [$none, fn () => Permission::create(['name' => 'reports.view', 'guard_name' => 'web'])],
        'a permission renamed' => [$none, fn () => Permission::findByName('tickets.create', 'web')->update(['name' => 'tickets.make'])],
        'a permission deleted' => [$none, fn () => Permission::findByName('tickets.create', 'web')->delete()],
        'a permission given to a role directly' => [$none, fn () => $role()->givePermissionTo('billing.view')],
        'a permission revoked from a role directly' => [$none, fn () => $role()->revokePermissionTo('tickets.view')],
        'a role\'s permissions synced directly' => [$none, fn () => $role()->syncPermissions(['billing.view'])],
        'a role assigned from the permission side' => [$none, fn () => Permission::findByName('billing.view', 'web')->assignRole($role())],
        'a role removed from the permission side' => [$none, fn () => Permission::findByName('tickets.view', 'web')->removeRole($role())],
        'a built-in synchronization that only grants' => [fn () => DB::table('role_has_permissions')->where('role_id', Role::findByName('user', 'web')->id)->where('permission_id', Permission::findByName('tickets.view', 'web')->id)->delete(), $applyPlan],
        'a built-in synchronization that only revokes' => [fn () => DB::table('role_has_permissions')->insert(['role_id' => Role::findByName('user', 'web')->id, 'permission_id' => Permission::findByName('billing.admin', 'web')->id]), $applyPlan],
        'a built-in synchronization that recreates a missing role' => [fn () => DB::table('roles')->where('name', 'user')->delete(), $applyPlan],
        'permission:cache-reset' => [$none, fn () => test()->artisan('permission:cache-reset')->expectsOutput('Permission cache flushed.')->assertSuccessful()->run()],
    };
}

it('runs the fenced registrar wherever the library resolves its registrar', function () {
    $registrar = app(PermissionRegistrar::class);

    expect($registrar)->toBeInstanceOf(EpochPermissionRegistrar::class)
        ->and(app()[PermissionRegistrar::class])->toBe($registrar)
        ->and(app(PermissionCacheEpoch::class))->toBe(app(PermissionCacheEpoch::class));
});

it('uses the generation key for every route to the library\'s loader, and never the unsuffixed key', function (string $route) {
    $registrar = app(PermissionRegistrar::class);
    $registrar->clearPermissionsCollection();
    $base = config('permission.cache.key');
    $user = $this->holder;

    match ($route) {
        'Permission::findByName' => Permission::findByName('tickets.view', 'web'),
        'Permission::findById' => Permission::findById(Permission::findByName('tickets.view', 'web')->id, 'web'),
        'User::hasPermissionTo' => $user->hasPermissionTo('tickets.view'),
        'User::checkPermissionTo' => $user->checkPermissionTo('tickets.view'),
        'the Gate (can:, @can, policies)' => Gate::forUser($user)->allows('tickets.view'),
        'User::permission() scope' => User::permission('tickets.view')->get(),
        'Role::hasPermissionTo' => $this->support->hasPermissionTo('tickets.view'),
    };

    expect($registrar->cacheKey)->toBe($base.':'.pcmEpoch())
        ->and(Cache::store()->has($base))->toBeFalse('nothing reads or writes the unsuffixed key')
        ->and(Cache::store()->has($registrar->cacheKey))->toBeTrue();
})->with([
    'Permission::findByName',
    'Permission::findById',
    'User::hasPermissionTo',
    'User::checkPermissionTo',
    'the Gate (can:, @can, policies)',
    'User::permission() scope',
    'Role::hasPermissionTo',
]);

it('advances the epoch for every kind of change to the shared permission mapping', function (string $name) {
    [$arrange, $change] = pcmScenario($name);
    $arrange();
    app(PermissionRegistrar::class)->clearPermissionsCollection();
    $before = pcmEpoch();

    $change();

    expect(pcmEpoch())->not->toBe($before);
})->with([
    'a custom role edited through the service',
    'a custom role edited through the Roles screen',
    'a custom role created with permissions through the Roles screen',
    'a custom role created without permissions',
    'a custom role deleted through the service',
    'a custom role deleted through the Roles screen',
    'a permission created',
    'a permission renamed',
    'a permission deleted',
    'a permission given to a role directly',
    'a permission revoked from a role directly',
    'a role\'s permissions synced directly',
    'a role assigned from the permission side',
    'a role removed from the permission side',
    'a built-in synchronization that only grants',
    'a built-in synchronization that only revokes',
    'a built-in synchronization that recreates a missing role',
    'permission:cache-reset',
]);

it('advances the epoch INSIDE the synchronization\'s own transaction, so a plan that only revokes is covered without any hook', function () {
    DB::table('role_has_permissions')->insert(['role_id' => Role::findByName('user', 'web')->id, 'permission_id' => Permission::findByName('billing.admin', 'web')->id]);
    $synchronizer = app(BuiltInRoleSynchronizer::class);
    $plan = $synchronizer->plan();
    $before = pcmEpoch();

    DB::transaction(function () use ($synchronizer, $plan, $before) {
        $synchronizer->apply($plan->fingerprint(), allowRevocations: true);

        // The application's post-commit hooks run only when this OUTER transaction ends, so a different epoch here is the
        // synchronization's own, in-transaction advance.
        expect(array_values(array_unique(array_column($plan->actions, 'action'))))->toBe(['revoke'])
            ->and(pcmEpoch())->not->toBe($before);
    });
});

it('does not advance the epoch for changes the shared mapping does not contain (a user\'s own roles and permissions)', function () {
    $user = User::factory()->create();
    $before = pcmEpoch();

    $user->assignRole('user');
    $user->givePermissionTo('crm.view');
    app(AccessGrants::class)->syncUserRoles($user, ['operator']);
    $user->removeRole('operator');

    expect(pcmEpoch())->toBe($before)
        ->and($user->fresh()->checkPermissionTo('crm.view'))->toBeTrue('a user\'s own grants come from the database on every request');
});

it('commits and rolls back with the change that caused it', function () {
    $before = pcmEpoch();

    try {
        DB::transaction(function () use ($before) {
            $this->support->syncPermissions(['billing.view']); // the library alone: no application hook
            expect(pcmEpoch())->not->toBe($before);

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect(pcmEpoch())->toBe($before)
        ->and($this->support->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['tickets.create', 'tickets.view']);

    DB::transaction(fn () => $this->support->syncPermissions(['billing.view']));
    expect(pcmEpoch())->not->toBe($before);
});

it('hands out fresh, well-formed, never repeated tokens and keeps exactly one row', function () {
    $epoch = app(PermissionCacheEpoch::class);
    $tokens = [$epoch->current()];
    foreach (range(1, 100) as $unused) {
        $tokens[] = $epoch->advance();
    }

    expect(count(array_unique($tokens)))->toBe(101)
        ->and($tokens)->each->toMatch('/^[0-9a-f]{32}$/')
        ->and($epoch->current())->toBe(end($tokens))
        ->and(DB::table($epoch->tableName())->where('key', $epoch->key())->count())->toBe(1);
});

it('keeps the authorization result right after an application revocation, through the existing hooks', function () {
    $registrar = app(PermissionRegistrar::class);
    expect($this->holder->checkPermissionTo('tickets.view'))->toBeTrue();
    $before = pcmEpoch();

    app(AccessGrants::class)->syncRolePermissions($this->support, ['tickets.create']);
    $registrar->clearPermissionsCollection(); // the next request

    expect($this->holder->fresh()->checkPermissionTo('tickets.view'))->toBeFalse()
        ->and($this->holder->fresh()->checkPermissionTo('tickets.create'))->toBeTrue()
        ->and(pcmEpoch())->not->toBe($before)
        ->and($registrar->cacheKey)->toBe(config('permission.cache.key').':'.pcmEpoch(), 'the active generation key, not the old unsuffixed one, holds the live mapping');
});

it('resets the registrar\'s in-memory copy before each queued job, but not for the sync connection', function (string $connection, bool $reloads) {
    $user = $this->holder;
    expect($user->checkPermissionTo('tickets.view'))->toBeTrue(); // loads the registrar's copy
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = $query->sql;
    });

    $user->checkPermissionTo('tickets.view');
    expect($statements)->toBe([], 'a loaded copy is reused without touching the database');

    event(new JobProcessing($connection, Mockery::mock(Job::class)->shouldIgnoreMissing()));
    $user->checkPermissionTo('tickets.view');

    $reloaded = collect($statements)->contains(fn (string $sql) => str_contains($sql, 'spatie.permission.epoch') || str_contains($sql, '`cache`'));
    expect($reloaded)->toBe($reloads);
})->with([
    'redis (a queue worker)' => ['redis', true],
    'database (a queue worker)' => ['database', true],
    'sync (inside the request that dispatched it)' => ['sync', false],
]);

it('lets a revocation commit when the shared cache is unavailable, and reports the cache failure', function () {
    Exceptions::fake();
    app(CacheManager::class)->extend('epoch-test-down', fn () => Cache::repository(new class extends ArrayStore
    {
        public function forget($key)
        {
            throw new RuntimeException('cache is down');
        }
    }));
    config(['cache.stores.epoch-test-down' => ['driver' => 'epoch-test-down'], 'permission.cache.store' => 'epoch-test-down']);
    app(PermissionRegistrar::class)->initializeCache();
    $before = pcmEpoch();

    app(AccessGrants::class)->syncRolePermissions($this->support, ['tickets.create']);

    expect(DB::table('role_has_permissions')->where('role_id', $this->support->id)->where('permission_id', Permission::findByName('tickets.view', 'web')->id)->exists())->toBeFalse('the revocation committed')
        ->and(pcmEpoch())->not->toBe($before, 'the authoritative generation moved without any help from the cache');
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'cache is down');
});
