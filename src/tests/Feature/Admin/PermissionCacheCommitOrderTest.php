<?php

use App\Models\User;
use App\Services\AccessGrants;
use App\Services\BuiltInRoleSynchronizer;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Support/committed_installation.php';

/*
 * R0.9-1C review finding M-1: a role-permission change or role deletion clears the shared permission cache only once
 * it is COMMITTED.
 *
 * The cache maps each permission to the roles that carry it. The permission library clears it inside the guarded
 * transaction, before COMMIT; a concurrent request can then reload the old, still-committed mapping and keep it for
 * the cache lifetime (24 hours), so a revoked permission would go on granting access. Each test reproduces that
 * interleaving deterministically, without sleeps: a TransactionCommitting listener (fired after all of the
 * transaction's work, immediately before COMMIT) runs a separate PHP process, with its own MariaDB connection, that
 * reloads the shared cache from the committed rows. The test checks that the reader really saw the old mapping, and
 * that a fresh reader after the commit sees the new one.
 *
 * The cache store must be shared between processes and outside the database transaction, as Redis is in production.
 * The file store has the same two properties and is always available. The Redis variant runs where REDIS_HOST names a
 * reachable server (`REDIS_HOST=redis` on the dev stack) and is skipped otherwise: `.env.testing` leaves Redis
 * unconfigured and the hosted CI Pest job has only MariaDB.
 *
 * Like the other concurrency tests it ends RefreshDatabase's transaction so the reader can see committed rows. Every
 * row it commits has a name unique to the run and is deleted in a finally block.
 */

dataset('shared permission cache stores', [
    'file store' => fn () => ['driver' => 'file', 'path' => storage_path('framework/cache/permission-race-'.uniqid())],
    'redis store (production)' => fn () => ['driver' => 'redis', 'connection' => 'cache', 'prefix' => 'permission-race-'.uniqid().':'],
]);

/** Point this process's permission library at $store, the store the reader processes use too. */
function useSharedPermissionCache(array $store): void
{
    if ($store['driver'] === 'redis') {
        try {
            Redis::connection($store['connection'])->ping();
        } catch (Throwable $exception) {
            test()->markTestSkipped('No Redis server is reachable here ('.get_debug_type($exception).'); the file-store variant covers the race.');
        }
    }

    // A short lifetime (also set in permission_cache_reader.php): since R0.9-1E every reload is stored under a generation
    // key, and the entries of the generations a run passes through are left behind. They are unreachable, and they expire.
    config(['cache.stores.permission-race' => $store, 'permission.cache.store' => 'permission-race', 'permission.cache.expiration_time' => 300]);
    app(PermissionRegistrar::class)->initializeCache();
    app(PermissionRegistrar::class)->clearPermissionsCollection();
}

function forgetSharedPermissionCache(array $store): void
{
    // The unsuffixed key (what the library used before R0.9-1E) and the key of the generation this process last loaded.
    Cache::store('permission-race')->forget(config('permission.cache.key'));
    Cache::store('permission-race')->forget(app(PermissionRegistrar::class)->cacheKey);

    if ($store['driver'] === 'file') {
        File::deleteDirectory($store['path']);
    }
}

/**
 * Another request's view of the permission cache: the role ids it maps $permission to and, given a user, whether
 * that user has it. Run in its own process, so it reads only committed rows when it reloads the cache.
 *
 * @return array{roles: list<int>|null, has: bool|null}
 */
function readPermissionCacheElsewhere(array $store, string $permission, ?int $userId = null): array
{
    $connection = config('database.connections.'.config('database.default'));
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/permission_cache_reader.php'), json_encode($store), $permission, ...($userId === null ? [] : [(string) $userId])],
        base_path(),
        [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => config('database.default'),
            'DB_HOST' => $connection['host'],
            'DB_PORT' => $connection['port'],
            'DB_DATABASE' => $connection['database'],
            'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'],
            'DB_URL' => '',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ],
    );
    $process->setTimeout(60);
    $process->mustRun();

    $lines = explode("\n", trim($process->getOutput()));

    return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * When the next top-level transaction is about to COMMIT, let another process reload the cache, and return what it
 * saw through $observed.
 */
function reloadPermissionCacheJustBeforeCommit(array $store, string $permission, ?int $userId, ?array &$observed): void
{
    $armed = true;

    Event::listen(TransactionCommitting::class, function () use (&$armed, &$observed, $store, $permission, $userId) {
        if (! $armed) {
            return;
        }

        $armed = false;
        $observed = readPermissionCacheElsewhere($store, $permission, $userId);
    });
}

/**
 * Committed: a permission, a role carrying it and a user holding the role. Returns [permission, role, user, cleanup].
 */
function committedRoleGrant(): array
{
    DB::commit(); // end the RefreshDatabase transaction so the reader process sees our rows

    // And transact as production does. The testing transactions manager treats the first open transaction as the
    // RefreshDatabase wrapper, so with the wrapper gone it would run a caller's after-commit callbacks inside the
    // caller's own transaction, before its commit.
    DB::connection()->setTransactionManager(new DatabaseTransactionsManager);

    $suffix = uniqid();
    $permission = Permission::create(['name' => "m1.revocable-{$suffix}", 'guard_name' => 'web']);
    $role = Role::create(['name' => "m1-role-{$suffix}", 'guard_name' => 'web'])->givePermissionTo($permission);
    $user = User::factory()->create();
    $user->assignRole($role);

    $cleanup = function () use ($permission, $role, $user) {
        $user->delete(); // Eloquent, so its role row goes with it
        Role::whereKey($role->id)->first()?->delete();
        $permission->delete();

        expect(DB::table('users')->where('id', $user->id)->exists())->toBeFalse()
            ->and(DB::table('roles')->where('id', $role->id)->exists())->toBeFalse()
            ->and(DB::table('permissions')->where('id', $permission->id)->exists())->toBeFalse();
    };

    return [$permission, $role, $user, $cleanup];
}

it('does not let a reader that reloads the cache before the commit keep a revoked permission alive', function (array $store) {
    useSharedPermissionCache($store);
    [$permission, $role, $user, $cleanup] = committedRoleGrant();

    try {
        // The old mapping is committed and cached, as it is for any role in use.
        expect(readPermissionCacheElsewhere($store, $permission->name, $user->id))->toBe(['roles' => [$role->id], 'has' => true]);

        reloadPermissionCacheJustBeforeCommit($store, $permission->name, $user->id, $observed);
        app(AccessGrants::class)->syncRolePermissions($role, []);

        expect($observed)->toBe(['roles' => [$role->id], 'has' => true], 'the reader reloaded the OLD mapping just before the commit')
            ->and(DB::table('role_has_permissions')->where('role_id', $role->id)->exists())->toBeFalse()
            ->and(readPermissionCacheElsewhere($store, $permission->name, $user->id))->toBe(['roles' => [], 'has' => false], 'a fresh reader after the commit');
    } finally {
        $cleanup();
        forgetSharedPermissionCache($store);
    }
})->with('shared permission cache stores');

it('waits for the caller\'s outer transaction to commit before clearing the cache', function (array $store) {
    useSharedPermissionCache($store);
    [$permission, $role, $user, $cleanup] = committedRoleGrant();

    try {
        reloadPermissionCacheJustBeforeCommit($store, $permission->name, $user->id, $observed);

        // Clearing as soon as the guard returned would be too early here: the change is not committed until the
        // outer transaction is, and the reader reloads the old mapping just before that.
        DB::transaction(fn () => app(AccessGrants::class)->syncRolePermissions($role, []));

        expect($observed)->toBe(['roles' => [$role->id], 'has' => true], 'the reader reloaded the OLD mapping just before the outer commit')
            ->and(readPermissionCacheElsewhere($store, $permission->name, $user->id))->toBe(['roles' => [], 'has' => false], 'a fresh reader after the commit');
    } finally {
        $cleanup();
        forgetSharedPermissionCache($store);
    }
})->with('shared permission cache stores');

it('does not let a reader that reloads the cache before the commit keep a deleted role in it', function (array $store) {
    useSharedPermissionCache($store);
    [$permission, $heldRole, $user, $cleanup] = committedRoleGrant();
    $unheld = Role::create(['name' => "m1-unheld-{$permission->id}", 'guard_name' => 'web'])->givePermissionTo($permission);

    try {
        reloadPermissionCacheJustBeforeCommit($store, $permission->name, null, $observed);

        expect(app(AccessGrants::class)->deleteUnassignedRole($unheld))->toBe(0);

        expect($observed['roles'])->toBe(collect([$heldRole->id, $unheld->id])->sort()->values()->all(), 'the reader reloaded the mapping with the role still in it')
            ->and(DB::table('roles')->where('id', $unheld->id)->exists())->toBeFalse()
            ->and(readPermissionCacheElsewhere($store, $permission->name)['roles'])->toBe([$heldRole->id], 'a fresh reader after the commit');
    } finally {
        Role::whereKey($unheld->id)->first()?->delete();
        $cleanup();
        forgetSharedPermissionCache($store);
    }
})->with('shared permission cache stores');

it('clears the cache when the caller\'s outer transaction rolls back after reloading it from its own uncommitted rows', function () {
    $this->seedRolesAndPermissions();
    $role = Role::findByName('user', 'web');
    $user = User::factory()->create();
    $user->assignRole($role);
    $permission = $role->permissions()->firstOrFail()->name;
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    try {
        DB::transaction(function () use ($role) {
            app(AccessGrants::class)->syncRolePermissions($role, []);
            app(PermissionRegistrar::class)->getPermissions(); // caches the uncommitted mapping (the role carries nothing)

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    app(PermissionRegistrar::class)->clearPermissionsCollection(); // read the shared store afresh, as the next request does
    expect(User::findOrFail($user->id)->hasPermissionTo($permission))->toBeTrue();
});

it('does not let a reader that reloads the cache before the commit keep a permission the built-in role synchronization revoked', function (array $store) {
    useSharedPermissionCache($store);
    $cleanup = committedSynchronizedInstallation();
    $member = User::factory()->create()->assignRole('user');
    $userRole = Role::findByName('user', 'web');
    $operatorRole = Role::findByName('operator', 'web');
    $userRole->givePermissionTo('billing.admin'); // committed drift: the plan revokes it
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    try {
        expect(readPermissionCacheElsewhere($store, 'billing.admin', $member->id))->toBe(['roles' => [$operatorRole->id, $userRole->id], 'has' => true]);

        $synchronizer = app(BuiltInRoleSynchronizer::class);
        $plan = $synchronizer->plan();
        reloadPermissionCacheJustBeforeCommit($store, 'billing.admin', $member->id, $observed);
        $synchronizer->apply($plan->fingerprint(), allowRevocations: true);

        expect($observed)->toBe(['roles' => [$operatorRole->id, $userRole->id], 'has' => true], 'the reader reloaded the OLD mapping just before the commit')
            ->and(DB::table('role_has_permissions')->where('role_id', $userRole->id)->where('permission_id', Permission::findByName('billing.admin')->id)->exists())->toBeFalse()
            ->and(readPermissionCacheElsewhere($store, 'billing.admin', $member->id))->toBe(['roles' => [$operatorRole->id], 'has' => false], 'a fresh reader after the commit');
    } finally {
        $cleanup([$member->id]);
        forgetSharedPermissionCache($store);
    }
})->with('shared permission cache stores');
