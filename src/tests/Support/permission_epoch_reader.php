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
 *
 * Output: one JSON line with the decisions, the cache key the reload used and timestamps that prove the ordering.
 * The wait for a flag file is a barrier, not a timing assumption: the process proceeds only when the test releases it,
 * and gives up (exit 3, which fails the test) after 60 seconds.
 */

use App\Models\User;
use App\Support\Permissions\PermissionCacheEpoch;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$store = json_decode($argv[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$options = json_decode($argv[2] ?? '{}', true, flags: JSON_THROW_ON_ERROR) + [
    'mode' => 'check', 'permissions' => [], 'registrar' => 'epoch', 'name' => 'reader', 'jobs' => 1,
];
$barrier = $options['barrier'] ?? null;
$name = (string) $options['name'];

// A short lifetime: every generation a run passes through leaves one unreachable entry behind, and it should expire.
config(['cache.stores.permission-race' => $store, 'permission.cache.store' => 'permission-race', 'permission.cache.expiration_time' => 300]);
if ($options['registrar'] === 'plain') {
    // The library's registrar as it ships. `instance()` bypasses the application's `extend`, so nothing is fenced.
    $app->instance(PermissionRegistrar::class, new PermissionRegistrar($app->make(CacheManager::class)));
} else {
    $app->make(PermissionRegistrar::class)->initializeCache();
}
$app->make(PermissionRegistrar::class)->clearPermissionsCollection();

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

    return microtime(true);
}

$evidence = ['reachedAt' => null, 'resumedAt' => null, 'pausedKey' => null, 'writtenKey' => null, 'writtenAt' => null];

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

Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$evidence) {
    if (str_starts_with($event->key, 'spatie.permission.cache')) {
        $evidence['writtenKey'] = $event->key;
        $evidence['writtenAt'] = microtime(true);
    }
});

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
        if ($job < (int) $options['jobs']) {
            stopAt($barrier, $name, "job-$job");
        }
    }
    echo json_encode(['jobs' => $jobs]);
    exit(0);
}

$has = $decide();
$registrar = $app->make(PermissionRegistrar::class);

echo json_encode([
    'has' => $has,
    'key' => $registrar->cacheKey,
    'registrar' => $registrar::class,
    'roles' => collect($options['rolesFor'] ?? [])->mapWithKeys(fn (string $permission) => [
        $permission => $registrar->getPermissions(['name' => $permission], true)->first()?->roles->pluck('name')->sort()->values()->all(),
    ])->all(),
    'evidence' => $evidence,
]);
