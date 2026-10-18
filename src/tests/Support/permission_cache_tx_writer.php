<?php

/*
 * Child process for PermissionCacheDatabaseStoreTest (R0.9 WP2): a revocation whose transaction stays OPEN until the test
 * decides how it ends.
 *
 * With the database cache store on the default connection, the permission-cache epoch row, the library's cache writes and
 * the revocation share ONE transaction, and that transaction holds InnoDB locks on `cache` rows. If the TEST process owned
 * it, any child that needed one of those locks would wait for the parent while the parent waited for the child, and InnoDB
 * cannot see that cycle (it ends in a lock-wait timeout). So the transaction is owned here: the test process holds no
 * transaction and no lock, and talks to this process only through flag files.
 *
 * Usage: php permission_cache_tx_writer.php <store-json> <options-json>
 *
 * Options:
 *   role, keep   the custom role whose permissions are replaced by `keep`
 *   path         application (default): AccessGrants::syncRolePermissions, i.e. the production path with its post-commit and
 *                post-rollback hooks | library: Role::syncPermissions alone (the library's own epoch advance, no hook) |
 *                raw: the shape of the built-in role synchronization: raw SQL the library never sees, then an explicit
 *                epoch advance in the same transaction. Unlike the other two it loads no permission mapping, so it stores
 *                nothing in the cache
 *
 * Note for the other two paths: syncPermissions loads the permission mapping, and on a cache miss stores it under the CURRENT
 * (still committed) epoch. On the default connection that INSERT is part of this transaction, so it holds a row lock on the
 * key a concurrent reader would store the same entry under.
 *   registrar    epoch (default, the application's) | plain (the library's own, UNFENCED: for the negative control)
 *   reload       also load the permission mapping INSIDE the transaction (the writer's own request authorizing something),
 *                which stores the UNCOMMITTED state in the cache under the epoch it just created
 *   barrier, name  flag-file directory and prefix, as for the readers
 *
 * Protocol (every step is logged to <barrier>/trace.log before the flag that lets the test proceed is created):
 *   <name>.prepared  created while the transaction is open: the revocation (and, with reload, the cache write) is in it,
 *                    uncommitted. Then it waits for <name>.decide, whose content is `commit` or `rollback`
 *                    (60 s, then exit 3: a bounded lifetime, and a failing test rather than a hang).
 *   <name>.done      created after the transaction has ended and the application's own post-commit / post-rollback steps ran.
 *
 * Output: one JSON line. `epochInTransaction` is the epoch this transaction itself created (it is visible to nobody else
 * until the commit); `cacheEntryInTransaction` whether the cache table holds the entry stored under it, as seen from inside.
 */

use App\Services\AccessGrants;
use App\Support\Permissions\PermissionCacheEpoch;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/permission_cache_trace.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$store = json_decode($argv[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$options = json_decode($argv[2] ?? '{}', true, flags: JSON_THROW_ON_ERROR) + ['path' => 'application', 'reload' => false, 'name' => 'writer'];
$barrier = (string) $options['barrier'];
$name = (string) $options['name'];

config(['permission.cache.expiration_time' => 300]);
if (($options['registrar'] ?? 'epoch') === 'plain') {
    // The library's registrar as it ships. `instance()` bypasses the application's `extend`, so nothing is fenced.
    $app->instance(PermissionRegistrar::class, new PermissionRegistrar($app->make(CacheManager::class)));
}
$registrar = $app->make(PermissionRegistrar::class);
$registrar->initializeCache();
$registrar->clearPermissionsCollection();
permissionCacheTrace($barrier, $name, 'booted');

final class WriterRollback extends RuntimeException {}

$result = ['decision' => null, 'epochInTransaction' => null, 'cacheEntryInTransaction' => null];

try {
    DB::transaction(function () use ($app, $registrar, $options, $barrier, $name, &$result) {
        // First callback on this transaction: it runs right after the COMMIT statement, before any application hook.
        DB::afterCommit(fn () => permissionCacheTrace($barrier, $name, 'committed'));

        permissionCacheTrace($barrier, $name, 'tx-open');
        if ($options['path'] === 'raw') {
            $roleId = DB::table('roles')->where('name', $options['role'])->value('id');
            DB::table('role_has_permissions')->where('role_id', $roleId)
                ->whereNotIn('permission_id', DB::table('permissions')->whereIn('name', $options['keep'])->select('id'))->delete();
            $app->make(PermissionCacheEpoch::class)->advance();
        } elseif ($options['path'] === 'library') {
            Role::findByName($options['role'], 'web')->syncPermissions($options['keep']);
        } else {
            $app->make(AccessGrants::class)->syncRolePermissions(Role::findByName($options['role'], 'web'), $options['keep']);
        }
        $result['epochInTransaction'] = $app->make(PermissionCacheEpoch::class)->current();

        if ($options['reload']) {
            $registrar->getPermissions(); // the uncommitted mapping, stored under the uncommitted epoch
            // Through the cache store itself, i.e. over the connection the store writes on: the default connection's own
            // snapshot would not show a write that a separate cache connection committed after the snapshot was taken.
            $result['cacheEntryInTransaction'] = Cache::store()->has($registrar->cacheKey);
        }

        permissionCacheTrace($barrier, $name, 'revoked-uncommitted', ['epoch' => $result['epochInTransaction']]);
        touch("$barrier/$name.prepared");

        $decide = "$barrier/$name.decide";
        $deadline = microtime(true) + 60;
        while (! file_exists($decide)) {
            if (microtime(true) > $deadline) {
                fwrite(STDERR, "writer $name was never told how to end its transaction\n");
                exit(3);
            }
            usleep(500);
        }
        $result['decision'] = trim((string) file_get_contents($decide));
        permissionCacheTrace($barrier, $name, 'decided:'.$result['decision']);

        if ($result['decision'] === 'rollback') {
            throw new WriterRollback('rolled back on request');
        }
    });
} catch (WriterRollback) {
    permissionCacheTrace($barrier, $name, 'rolled-back');
}

permissionCacheTrace($barrier, $name, 'done');
touch("$barrier/$name.done");

echo json_encode($result);
