<?php

/*
 * Child process for PermissionCacheEpochTest (R0.9-1E): another request, or another queue worker, reading the shared
 * permission cache from its own PHP process with its own MariaDB connection. It reads COMMITTED rows only.
 *
 * Usage: php permission_epoch_reader.php <cache-store-config-json> <options-json>
 *
 * Options:
 *   mode         check (default) | worker | epoch
 *   userId       the user whose permissions are checked
 *   rolesFor     permission names whose cached role list is also reported
 *   permissions  permission names to check (check and worker modes)
 *   registrar    epoch (default, the application's) | plain (the library's own, UNFENCED: the negative control)
 *   barrier      a directory shared with the test, and name, a prefix for this reader's flag files
 *   pause        cache-write | after-permissions-query
 *                Stops at that point of the library's reload, writes <barrier>/<name>.<point>.reached and waits for
 *                <barrier>/<name>.<point>.release. cache-write is the exact vulnerable point: the old state has been read
 *                from the database and is about to be stored in the shared cache, under the key chosen before the read.
 *   jobs         worker mode: how many consecutive "jobs" to process in this one persistent process
 *   skipJobEvent worker mode: do not fire JobProcessing between jobs (shows what happens without the reset)
 *   transaction  check mode: run the whole decision inside one REPEATABLE READ transaction whose snapshot is taken before
 *                anything else (a job or request wrapped in a transaction). The epoch is re-read before COMMIT.
 *   lockWaitTimeout  seconds: bound every InnoDB lock wait of this process, so a wait that should never happen fails fast
 *   freezeTime   unix time: freeze Carbon's clock (two readers storing the same entry in the same second write identical rows)
 *   trace        also appended to <barrier>/trace.log, in order, with the test process and the other children (see permission_cache_trace.php)
 *
 * Store argument: a store config (file, redis, a database store) that this process registers as `permission-race`, or
 * {"profile":"database"}: inject nothing and read the cache as the application itself does under CACHE_STORE=database
 * (the Release 0.9 hosting profile), the environment of the process carrying CACHE_STORE, CACHE_PREFIX and DB_CACHE_CONNECTION.
 *
 * Output: one JSON line with the decisions, the cache key the reload used and timestamps that prove the ordering.
 * The wait for a flag file is a barrier, not a timing assumption: the process proceeds only when the test releases it,
 * and gives up (exit 3, which fails the test) after 60 seconds.
 */

use App\Models\User;
use App\Support\Permissions\PermissionCacheEpoch;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;

require __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/permission_cache_trace.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$store = json_decode($argv[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$options = json_decode($argv[2] ?? '{}', true, flags: JSON_THROW_ON_ERROR) + [
    'mode' => 'check', 'permissions' => [], 'registrar' => 'epoch', 'name' => 'reader', 'jobs' => 1,
];
$barrier = $options['barrier'] ?? null;
$name = (string) $options['name'];

// A short lifetime: every generation a run passes through leaves one unreachable entry behind, and it should expire.
if (($store['profile'] ?? null) === 'database') {
    // The application's own wiring under CACHE_STORE=database: the library's `default` store is the database store.
    config(['permission.cache.expiration_time' => 300]);
} else {
    config(['cache.stores.permission-race' => $store, 'permission.cache.store' => 'permission-race', 'permission.cache.expiration_time' => 300]);
}
if (isset($options['lockWaitTimeout'])) {
    foreach (array_unique([null, getenv('DB_CACHE_CONNECTION') ?: null], SORT_REGULAR) as $connectionName) {
        DB::connection($connectionName)->statement('set session innodb_lock_wait_timeout = '.(int) $options['lockWaitTimeout']);
    }
}
if (isset($options['freezeTime'])) {
    Carbon::setTestNow(Carbon::createFromTimestamp((int) $options['freezeTime']));
}
if ($options['registrar'] === 'plain') {
    // The library's registrar as it ships. `instance()` bypasses the application's `extend`, so nothing is fenced.
    $app->instance(PermissionRegistrar::class, new PermissionRegistrar($app->make(CacheManager::class)));
} else {
    $app->make(PermissionRegistrar::class)->initializeCache();
}
$app->make(PermissionRegistrar::class)->clearPermissionsCollection();
permissionCacheTrace($barrier, $name, 'booted');

function flagPath(string $barrier, string $name, string $point, string $kind): string
{
    return "$barrier/$name.$point.$kind";
}

/** Announce that this reader is stopped at $point, then wait until the test releases it. */
function stopAt(?string $barrier, string $name, string $point): float
{
    if ($barrier === null) {
        return microtime(true);
    }

    $reached = flagPath($barrier, $name, $point, 'reached');
    permissionCacheTrace($barrier, $name, "parked@$point");
    file_put_contents("$reached.tmp", (string) microtime(true));
    rename("$reached.tmp", $reached); // appears complete or not at all

    $release = flagPath($barrier, $name, $point, 'release');
    $deadline = microtime(true) + 60;
    while (! file_exists($release)) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "reader $name was never released from $point\n");
            exit(3);
        }
        usleep(500);
    }
    permissionCacheTrace($barrier, $name, "resumed@$point");

    return microtime(true);
}

$evidence = ['reachedAt' => null, 'resumedAt' => null, 'pausedKey' => null, 'writtenKey' => null, 'writtenAt' => null, 'writeOutcome' => null];

if ($options['mode'] === 'epoch') {
    touch("$barrier/$name.ready"); // booted and about to poll: the test releases every reader together
    $go = "$barrier/go";
    $deadline = microtime(true) + 60;
    while (! file_exists($go)) {
        if (microtime(true) > $deadline) {
            exit(3);
        }
        usleep(200);
    }
    echo json_encode(['epoch' => $app->make(PermissionCacheEpoch::class)->current(), 'pid' => getmypid()]);
    exit(0);
}

$pause = $options['pause'] ?? null;
$armed = $pause !== null;

if ($pause === 'cache-write') {
    Event::listen(WritingKey::class, function (WritingKey $event) use (&$armed, &$evidence, $barrier, $name) {
        if ($armed && str_starts_with($event->key, 'spatie.permission.cache')) {
            $armed = false;
            $evidence['pausedKey'] = $event->key;
            $evidence['reachedAt'] = microtime(true);
            $evidence['resumedAt'] = stopAt($barrier, $name, 'cache-write');
        }
    });
}

if ($pause === 'after-permissions-query') {
    DB::listen(function (QueryExecuted $query) use (&$armed, &$evidence, $barrier, $name) {
        // The loader's two statements are `select * from permissions` and then the roles query. Stop between them.
        if ($armed && trim($query->sql) === 'select * from `permissions`') {
            $armed = false;
            $evidence['reachedAt'] = microtime(true);
            $evidence['resumedAt'] = stopAt($barrier, $name, 'after-permissions-query');
        }
    });
}

// The database store's put() is an upsert that reports success only when a row CHANGED. When an identical entry (same
// mapping, same expiration second) is already there, MariaDB reports zero affected rows and Laravel fires KeyWriteFailed
// although the entry is in the store. Both events therefore mean "the write was issued"; `writeOutcome` says which.
$recordWrite = function (string $key, string $outcome) use (&$evidence, $barrier, $name) {
    if (str_starts_with($key, 'spatie.permission.cache')) {
        $evidence['writtenKey'] = $key;
        $evidence['writtenAt'] = microtime(true);
        $evidence['writeOutcome'] = $outcome;
        permissionCacheTrace($barrier, $name, "cache-write:$outcome", ['key' => $key]);
    }
};
Event::listen(KeyWritten::class, fn (KeyWritten $event) => $recordWrite($event->key, 'written'));
Event::listen(KeyWriteFailed::class, fn (KeyWriteFailed $event) => $recordWrite($event->key, 'unchanged'));

$decide = function () use ($options): array {
    $user = User::findOrFail((int) $options['userId']);

    return collect($options['permissions'])->mapWithKeys(fn (string $permission) => [$permission => $user->checkPermissionTo($permission)])->all();
};

if ($options['mode'] === 'worker') {
    // One persistent process, as `queue:work` is: the registrar is a singleton that survives from job to job.
    $jobs = [];
    for ($job = 1; $job <= (int) $options['jobs']; $job++) {
        if ($job > 1 && empty($options['skipJobEvent'])) {
            event(new JobProcessing('redis', Mockery::mock(Job::class)->shouldIgnoreMissing()));
        }
        $jobs[$job] = ['has' => $decide(), 'key' => $app->make(PermissionRegistrar::class)->cacheKey];
        permissionCacheTrace($barrier, $name, "job-$job decided", ['has' => $jobs[$job]['has']]);
        if ($job < (int) $options['jobs']) {
            stopAt($barrier, $name, "job-$job");
        }
    }
    echo json_encode(['jobs' => $jobs]);
    exit(0);
}

if (! empty($options['transaction'])) {
    DB::beginTransaction();
    DB::table('cache')->limit(1)->get(); // the first consistent read fixes this transaction's snapshot
    permissionCacheTrace($barrier, $name, 'snapshot');
}

$has = $decide();
permissionCacheTrace($barrier, $name, 'decided', ['has' => $has]);
$registrar = $app->make(PermissionRegistrar::class);
$epochSeen = ! empty($options['transaction']) ? $app->make(PermissionCacheEpoch::class)->current() : null;
if (! empty($options['transaction'])) {
    DB::commit();
}

echo json_encode([
    'has' => $has,
    'key' => $registrar->cacheKey,
    'registrar' => $registrar::class,
    'store' => $registrar->getCacheStore()::class,
    'epochSeen' => $epochSeen,
    'roles' => collect($options['rolesFor'] ?? [])->mapWithKeys(fn (string $permission) => [
        $permission => $registrar->getPermissions(['name' => $permission], true)->first()?->roles->pluck('name')->sort()->values()->all(),
    ])->all(),
    'evidence' => $evidence,
]);
