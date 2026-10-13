<?php

use App\Models\User;
use App\Services\AccessGrants;
use App\Services\BuiltInRoleSynchronizer;
use App\Support\Permissions\PermissionCacheEpoch;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Support/committed_installation.php';

/*
 * Release 0.9 (R0.9-1E): the permission cache is fenced by a database-backed epoch.
 *
 * SECURITY INVARIANT. Once a role-permission revocation has committed, authorization checks that begin afterward must not
 * grant access through stale shared permission cache data. A decision already under way may finish under the state it read.
 *
 * THE RACE (found in the R0.9-1D review). A reader loads the permission mapping from the database BEFORE a revocation
 * commits and stores it in the shared cache AFTER the writer has cleared the cache. The stale mapping then outlives the
 * revocation for the cache lifetime (24 hours). R0.9-1C's post-commit clear cannot prevent it: it runs before the late store.
 *
 * HOW THESE TESTS PROVE IT. Every scenario runs real, independent PHP processes with their own MariaDB connections against
 * ONE shared cache store (the file store, and the production Redis store where a server is reachable). The reader is
 * stopped by a barrier at the exact vulnerable point, the Laravel `WritingKey` event: the old state has been read and is
 * about to be stored. The writer then runs in the test process. Only when the writer has finished does the test release the
 * reader. Timestamps prove the order (reader stopped < writer started < writer finished < reader resumed < reader stored),
 * so a test cannot pass merely because the reader was never stopped at the vulnerable point. A fresh process then decides.
 *
 * NEGATIVE CONTROL. The same interleaving against the library's own, unfenced registrar must STILL fail the security
 * assertion; if it ever stops doing so (an upstream fix), that test says so and this fence can be reconsidered.
 *
 * Like the other concurrency tests these end RefreshDatabase's transaction so other processes see committed rows; every row
 * is removed in a finally block, and the cache entries live under a prefix unique to the run.
 */

dataset('epoch cache stores', [
    'file store' => fn () => ['driver' => 'file', 'path' => storage_path('framework/cache/permission-epoch-'.uniqid())],
    'redis store (production)' => fn () => ['driver' => 'redis', 'connection' => 'cache', 'prefix' => 'permission-epoch-'.uniqid().':'],
]);

final class PermissionCacheEpochWorld
{
    /** @var list<int> */
    public array $userIds = [];

    /** @var list<string> */
    public array $keys = [];

    public string $barrier = '';
}

/** Run $body against a committed installation and the shared $store, then remove everything it created. */
function pceWorld(array $store, Closure $body, string $registrar = 'epoch'): void
{
    if ($store['driver'] === 'redis') {
        try {
            Redis::connection($store['connection'])->ping();
        } catch (Throwable $exception) {
            test()->markTestSkipped('No Redis server is reachable here ('.get_debug_type($exception).'); the file-store variant covers the race.');
        }
    }

    $cleanup = committedSynchronizedInstallation();
    $world = new PermissionCacheEpochWorld;
    $world->barrier = sys_get_temp_dir().'/pce-'.uniqid();
    mkdir($world->barrier);

    config(['cache.stores.permission-race' => $store, 'permission.cache.store' => 'permission-race', 'permission.cache.expiration_time' => 300]);
    if ($registrar === 'plain') {
        // The library's registrar as it ships; `instance()` bypasses the application's `extend`.
        app()->instance(PermissionRegistrar::class, new PermissionRegistrar(app(CacheManager::class)));
    } else {
        app(PermissionRegistrar::class)->initializeCache();
    }
    app(PermissionRegistrar::class)->clearPermissionsCollection();

    try {
        $body($world);
    } finally {
        foreach (glob($world->barrier.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($world->barrier);

        $repository = Cache::store('permission-race');
        foreach ([...$world->keys, config('permission.cache.key')] as $key) {
            $repository->forget($key);
        }
        if ($store['driver'] === 'file') {
            File::deleteDirectory($store['path']);
        }
        app()->forgetInstance(PermissionRegistrar::class);
        $cleanup($world->userIds);
    }
}

/** A custom role carrying $permissions and a user who holds only that role. */
function pceSupportWorld(PermissionCacheEpochWorld $world, array $permissions = ['tickets.view', 'tickets.create'], string $name = 'support'): array
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web'])->givePermissionTo($permissions);
    $user = User::factory()->create();
    $user->assignRole($role);
    $world->userIds[] = $user->id;
    app(PermissionRegistrar::class)->clearPermissionsCollection();

    return [$role, $user];
}

function pceEnv(): array
{
    $connection = config('database.connections.'.config('database.default'));

    return [
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
    ];
}

function pceReader(array $store, int $userId, array $permissions, array $options = []): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/permission_epoch_reader.php'), json_encode($store), json_encode(['userId' => $userId, 'permissions' => $permissions] + $options)],
        base_path(),
        pceEnv(),
    );
    $process->setTimeout(120);

    return $process;
}

/** @return array<string, mixed> */
function pceOutput(Process $process): array
{
    $process->wait();
    if ($process->getExitCode() !== 0) {
        throw new RuntimeException('reader failed ('.$process->getExitCode().'): '.$process->getErrorOutput().$process->getOutput());
    }

    $lines = explode("\n", trim($process->getOutput()));

    return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
}

function pceFresh(array $store, int $userId, array $permissions, array $options = []): array
{
    $process = pceReader($store, $userId, $permissions, $options);
    $process->start();

    return pceOutput($process);
}

/** Wait until the reader is stopped at $point (the barrier is a flag file, not a delay) and return when it got there. */
function pceAwait(Process $reader, string $barrier, string $name, string $point): float
{
    $flag = "$barrier/$name.$point.reached";
    $deadline = microtime(true) + 60;
    while (! file_exists($flag)) {
        if (! $reader->isRunning()) {
            throw new RuntimeException("reader $name ended before reaching $point: ".$reader->getOutput().$reader->getErrorOutput());
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException("reader $name never reached $point");
        }
        usleep(500);
    }

    return (float) file_get_contents($flag);
}

function pceRelease(string $barrier, string $name, string $point): float
{
    touch("$barrier/$name.$point.release");

    return microtime(true);
}

function pceEpoch(): string
{
    return app(PermissionCacheEpoch::class)->current();
}

function pceGrantExists(string $role, string $permission): bool
{
    return DB::table('role_has_permissions as rhp')
        ->join('roles as r', 'r.id', '=', 'rhp.role_id')
        ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
        ->where('r.name', $role)->where('p.name', $permission)->exists();
}

/**
 * The critical interleaving. A reader stops where it has read the old state and is about to store it; $writer then changes
 * the permissions and commits; only afterwards is the reader released; then a fresh process decides.
 *
 * @return array<string, mixed>
 */
function pceStraddle(PermissionCacheEpochWorld $world, array $store, int $userId, string $registrar, Closure $writer, string $permission = 'tickets.view', string $point = 'cache-write', array $extra = []): array
{
    $reader = pceReader($store, $userId, [$permission], ['pause' => $point, 'barrier' => $world->barrier, 'name' => 'old', 'registrar' => $registrar] + $extra);
    $reader->start();
    $reachedAt = pceAwait($reader, $world->barrier, 'old', $point);

    $epochBefore = pceEpoch();
    $writerStartedAt = microtime(true);
    $writer();
    $writerFinishedAt = microtime(true);
    $epochAfter = pceEpoch();

    pceRelease($world->barrier, 'old', $point);
    $old = pceOutput($reader);
    $fresh = pceFresh($store, $userId, [$permission], ['registrar' => $registrar] + $extra);

    array_push($world->keys, (string) $old['evidence']['writtenKey'], (string) $fresh['key']);

    return compact('reachedAt', 'epochBefore', 'writerStartedAt', 'writerFinishedAt', 'epochAfter', 'old', 'fresh');
}

/** The proof that the reader really was stopped at the vulnerable point and stored only after the writer was done. */
function pceExpectVulnerableOrdering(array $run): void
{
    $evidence = $run['old']['evidence'];

    expect($run['reachedAt'])->toBeLessThan($run['writerStartedAt'], 'the reader had already read the old state before the writer began')
        ->and($run['writerFinishedAt'])->toBeLessThan($evidence['resumedAt'], 'the reader resumed only after the writer had finished')
        ->and($evidence['resumedAt'])->toBeLessThanOrEqual($evidence['writtenAt'])
        ->and($evidence['pausedKey'])->toBe($evidence['writtenKey'], 'the reader stored exactly the entry it had been stopped on');
}

describe('the straddle: a reader holding the old mapping stores it after the revocation has committed', function () {
    it('A: an ordinary revocation reaches every process that begins after the commit', function (array $store) {
        pceWorld($store, function ($world) use ($store) {
            [$role, $user] = pceSupportWorld($world);
            $run = pceStraddle($world, $store, $user->id, 'epoch', fn () => app(AccessGrants::class)->syncRolePermissions($role, ['tickets.create']));

            pceExpectVulnerableOrdering($run);
            expect(pceGrantExists('support', 'tickets.view'))->toBeFalse('the revocation is committed')
                ->and($run['old']['has']['tickets.view'])->toBeTrue('the decision already under way finishes under the state it read')
                ->and($run['epochAfter'])->not->toBe($run['epochBefore'], 'the generation changed with the commit')
                ->and($run['old']['evidence']['writtenKey'])->toEndWith(':'.$run['epochBefore'], 'the late store went to the OBSOLETE generation')
                ->and($run['fresh']['key'])->toEndWith(':'.$run['epochAfter'])
                ->and($run['fresh']['has']['tickets.view'])->toBeFalse('a process that begins after the commit sees the revocation')
                ->and($run['fresh']['has'])->not->toHaveKey('tickets.create');
        });
    })->with('epoch cache stores');

    it('NEGATIVE CONTROL: the library\'s unfenced registrar loses the same race, so the test above has teeth', function (array $store) {
        pceWorld($store, function ($world) use ($store) {
            [$role, $user] = pceSupportWorld($world);
            $run = pceStraddle($world, $store, $user->id, 'plain', fn () => app(AccessGrants::class)->syncRolePermissions($role, ['tickets.create']));

            pceExpectVulnerableOrdering($run);
            expect(pceGrantExists('support', 'tickets.view'))->toBeFalse('the revocation is committed')
                ->and($run['fresh']['has']['tickets.view'])->toBeTrue('CONTROL: without the fence a process that begins after the commit is still granted the revoked permission')
                ->and($run['fresh']['registrar'])->toBe(PermissionRegistrar::class);
        }, 'plain');
    })->with('epoch cache stores');

    it('B: a caller-owned outer transaction, committed with the reader released after the commit', function (array $store) {
        pceWorld($store, function ($world) use ($store) {
            [$role, $user] = pceSupportWorld($world);
            $run = pceStraddle($world, $store, $user->id, 'epoch', fn () => DB::transaction(fn () => app(AccessGrants::class)->syncRolePermissions($role, ['tickets.create'])));

            pceExpectVulnerableOrdering($run);
            expect($run['fresh']['has']['tickets.view'])->toBeFalse()
                ->and($run['old']['evidence']['writtenKey'])->toEndWith(':'.$run['epochBefore']);
        });
    })->with('epoch cache stores');

    it('B: a caller-owned outer transaction, with the reader storing BEFORE the outer commit', function (array $store) {
        pceWorld($store, function ($world) use ($store) {
            [$role, $user] = pceSupportWorld($world);
            $reader = pceReader($store, $user->id, ['tickets.view'], ['pause' => 'cache-write', 'barrier' => $world->barrier, 'name' => 'old']);
            $reader->start();
            pceAwait($reader, $world->barrier, 'old', 'cache-write');
            $epochBefore = pceEpoch();

            DB::transaction(function () use ($world, $role, $reader, &$old) {
                app(AccessGrants::class)->syncRolePermissions($role, ['tickets.create']);
                // The outer transaction has not committed, so the reader still sees the old rows and stores them now.
                pceRelease($world->barrier, 'old', 'cache-write');
                $old = pceOutput($reader);
            });

            $fresh = pceFresh($store, $user->id, ['tickets.view']);
            $world->keys[] = $fresh['key'];
            expect($old['has']['tickets.view'])->toBeTrue()
                ->and($old['evidence']['writtenKey'])->toEndWith(':'.$epochBefore)
                ->and($fresh['has']['tickets.view'])->toBeFalse('the commit moved the generation past the entry the reader stored')
                ->and($fresh['key'])->not->toEndWith(':'.$epochBefore);
        });
    })->with('epoch cache stores');

    it('C: a rolled-back transaction leaves the generation and every committed grant exactly as they were', function (array $store) {
        pceWorld($store, function ($world) use ($store) {
            [$role, $user] = pceSupportWorld($world);
            $epochInTransaction = null;

            $run = pceStraddle($world, $store, $user->id, 'epoch', function () use ($role, &$epochInTransaction) {
                try {
                    DB::transaction(function () use ($role, &$epochInTransaction) {
                        $role->syncPermissions(['tickets.create']); // the library alone: no application hook
                        app(PermissionRegistrar::class)->getPermissions(); // reloads the UNCOMMITTED state into the shared cache
                        $epochInTransaction = pceEpoch();

                        throw new RuntimeException('roll back');
                    });
                } catch (RuntimeException) {
                }
            });

            $uncommittedKey = config('permission.cache.key').':'.$epochInTransaction;
            expect($epochInTransaction)->not->toBe($run['epochBefore'], 'the generation moved inside the transaction')
                ->and($run['epochAfter'])->toBe($run['epochBefore'], 'and rolled back with it')
                ->and(pceGrantExists('support', 'tickets.view'))->toBeTrue()
                ->and(Cache::store('permission-race')->has($uncommittedKey))->toBeTrue('the rolled-back state really was stored in the shared cache')
                ->and($run['fresh']['key'])->toEndWith(':'.$run['epochBefore'])
                ->and($run['fresh']['has']['tickets.view'])->toBeTrue('and no process ever reads it: the committed grant stands');
            $world->keys[] = $uncommittedKey;
        });
    })->with('epoch cache stores');

    it('C: a rollback through the application hooks also leaves the committed grants authoritative', function (array $store) {
        pceWorld($store, function ($world) use ($store) {
            [$role, $user] = pceSupportWorld($world);

            $run = pceStraddle($world, $store, $user->id, 'epoch', function () use ($role) {
                try {
                    DB::transaction(function () use ($role) {
                        app(AccessGrants::class)->syncRolePermissions($role, ['tickets.create']);

                        throw new RuntimeException('roll back');
                    });
                } catch (RuntimeException) {
                }
            });

            expect(pceGrantExists('support', 'tickets.view'))->toBeTrue()
                ->and($run['fresh']['has']['tickets.view'])->toBeTrue();
        });
    })->with('epoch cache stores');

    it('E: deleting a role removes it from the mapping seen by every process that begins afterward', function (array $store) {
        pceWorld($store, function ($world) use ($store) {
            [, $user] = pceSupportWorld($world);
            Role::create(['name' => 'doomed', 'guard_name' => 'web'])->givePermissionTo('tickets.create');

            $run = pceStraddle($world, $store, $user->id, 'epoch', fn () => app(AccessGrants::class)->deleteUnassignedRole(Role::findByName('doomed', 'web')), 'tickets.create', 'cache-write', ['rolesFor' => ['tickets.create']]);

            pceExpectVulnerableOrdering($run);
            expect(Role::where('name', 'doomed')->exists())->toBeFalse()
                ->and($run['old']['roles']['tickets.create'])->toContain('doomed', 'support')
                ->and($run['fresh']['roles']['tickets.create'])->not->toContain('doomed')
                ->and($run['fresh']['roles']['tickets.create'])->toContain('support')
                ->and($run['fresh']['key'])->toEndWith(':'.$run['epochAfter']);
        });
    })->with('epoch cache stores');

    it('I: a built-in role synchronization that only revokes reaches every process that begins afterward', function (array $store) {
        pceWorld($store, function ($world) use ($store) {
            Role::findByName('user', 'web')->givePermissionTo('billing.admin'); // drift: the plan will revoke it
            $user = User::factory()->create();
            $user->assignRole('user');
            $world->userIds[] = $user->id;
            app(PermissionRegistrar::class)->clearPermissionsCollection();

            $synchronizer = app(BuiltInRoleSynchronizer::class);
            $plan = $synchronizer->plan();
            expect(array_values(array_unique(array_column($plan->actions, 'action'))))->toBe(['revoke'], 'a revocation-only plan: no role or permission is created, so only the explicit advance can move the generation');

            $run = pceStraddle($world, $store, $user->id, 'epoch', fn () => $synchronizer->apply($plan->fingerprint(), allowRevocations: true), 'billing.admin');

            pceExpectVulnerableOrdering($run);
            expect(pceGrantExists('user', 'billing.admin'))->toBeFalse()
                ->and($run['old']['has']['billing.admin'])->toBeTrue()
                ->and($run['epochAfter'])->not->toBe($run['epochBefore'])
                ->and($run['fresh']['has']['billing.admin'])->toBeFalse();
        });
    })->with('epoch cache stores');

    it('J: a reader interrupted between the library\'s permissions query and its roles query can only fill the obsolete generation', function (array $store) {
        pceWorld($store, function ($world) use ($store) {
            [$role, $user] = pceSupportWorld($world);

            $run = pceStraddle($world, $store, $user->id, 'epoch', fn () => app(AccessGrants::class)->syncRolePermissions($role, ['tickets.create']), 'tickets.view', 'after-permissions-query');

            expect($run['reachedAt'])->toBeLessThan($run['writerStartedAt'])
                ->and($run['writerFinishedAt'])->toBeLessThan($run['old']['evidence']['resumedAt'])
                ->and($run['old']['evidence']['writtenKey'])->toEndWith(':'.$run['epochBefore'], 'a mixed read is stored under the obsolete generation')
                ->and($run['fresh']['key'])->toEndWith(':'.$run['epochAfter'])
                ->and($run['fresh']['has']['tickets.view'])->toBeFalse();
        });
    })->with('epoch cache stores');
});

describe('consecutive revocations', function () {
    it('D: stale readers that finish out of order never resurrect an earlier state', function (array $store, string $order) {
        pceWorld($store, function ($world) use ($store, $order) {
            [$role, $user] = pceSupportWorld($world);
            $grants = app(AccessGrants::class);
            $options = fn (string $name) => ['pause' => 'cache-write', 'barrier' => $world->barrier, 'name' => $name];
            $permissions = ['tickets.view', 'tickets.create'];

            $first = pceReader($store, $user->id, $permissions, $options('r1'));
            $first->start();
            pceAwait($first, $world->barrier, 'r1', 'cache-write');
            $e0 = pceEpoch();
            $grants->syncRolePermissions($role, ['tickets.create']); // revokes tickets.view
            $e1 = pceEpoch();

            $second = pceReader($store, $user->id, $permissions, $options('r2'));
            $second->start();
            pceAwait($second, $world->barrier, 'r2', 'cache-write');
            $grants->syncRolePermissions(Role::findByName('support', 'web'), []); // revokes tickets.create
            $e2 = pceEpoch();

            $readers = ['r1' => $first, 'r2' => $second];
            foreach ($order === 'second reader first' ? ['r2', 'r1'] : ['r1', 'r2'] as $name) {
                pceRelease($world->barrier, $name, 'cache-write');
                $out[$name] = pceOutput($readers[$name]);
            }
            $fresh = pceFresh($store, $user->id, $permissions);
            array_push($world->keys, $out['r1']['evidence']['writtenKey'], $out['r2']['evidence']['writtenKey'], $fresh['key']);

            expect([$e0, $e1, $e2])->each->toBeString()
                ->and(count(array_unique([$e0, $e1, $e2])))->toBe(3, 'each commit produced a new generation')
                ->and($out['r1']['has'])->toBe(['tickets.view' => true, 'tickets.create' => true])
                ->and($out['r2']['has'])->toBe(['tickets.view' => false, 'tickets.create' => true])
                ->and($out['r1']['evidence']['writtenKey'])->toEndWith(':'.$e0)
                ->and($out['r2']['evidence']['writtenKey'])->toEndWith(':'.$e1)
                ->and($fresh['key'])->toEndWith(':'.$e2)
                ->and($fresh['has'])->toBe(['tickets.view' => false, 'tickets.create' => false]);
        });
    })->with('epoch cache stores')->with(['first reader first', 'second reader first']);
});

describe('a writer that dies, and a cache that is lost', function () {
    it('F: a writer killed right after COMMIT, before any post-commit callback, has already moved the generation', function (array $store) {
        pceWorld($store, function ($world) use ($store) {
            [, $user] = pceSupportWorld($world);
            $writer = new Process(
                [PHP_BINARY, base_path('tests/Support/permission_epoch_writer.php'), json_encode(['role' => 'support', 'keep' => ['tickets.create'], 'barrier' => $world->barrier])],
                base_path(),
                pceEnv(),
            );
            $writer->setTimeout(120);

            $run = pceStraddle($world, $store, $user->id, 'epoch', function () use ($writer, $world) {
                try {
                    $writer->run();
                } catch (ProcessSignaledException) {
                    // SIGKILL is the point of this writer; Symfony reports a signalled child as an exception.
                }
                expect(file_exists($world->barrier.'/writer.committed'))->toBeTrue('the writer reached its commit')
                    ->and($writer->hasBeenSignaled())->toBeTrue('and was killed right after it, not allowed to finish')
                    ->and($writer->getTermSignal())->toBe(9);
            });

            pceExpectVulnerableOrdering($run);
            expect(pceGrantExists('support', 'tickets.view'))->toBeFalse('the revocation committed')
                ->and($run['epochAfter'])->not->toBe($run['epochBefore'], 'with no application hook having run, the committed transaction itself moved the generation')
                ->and($run['fresh']['has']['tickets.view'])->toBeFalse();
        });
    })->with('epoch cache stores');

    it('G: after the shared cache is flushed, the next processes rebuild it under the current generation', function (array $store) {
        pceWorld($store, function ($world) use ($store) {
            [$role, $user] = pceSupportWorld($world);
            $before = pceFresh($store, $user->id, ['tickets.view']);
            $world->keys[] = $before['key'];
            expect($before['has']['tickets.view'])->toBeTrue();

            Cache::store('permission-race')->forget($before['key']); // the keys of this run: never a FLUSHDB on a shared Redis
            expect(Cache::store('permission-race')->has($before['key']))->toBeFalse();

            app(AccessGrants::class)->syncRolePermissions($role, ['tickets.create']);
            $after = pceFresh($store, $user->id, ['tickets.view']);
            $world->keys[] = $after['key'];

            expect($after['has']['tickets.view'])->toBeFalse()
                ->and($after['key'])->not->toBe($before['key'])
                ->and(Cache::store('permission-race')->has($after['key']))->toBeTrue('the new process stored its reload under the new generation');
        });
    })->with('epoch cache stores');

    it('G: losing the generation row mints a new one, and an old entry can never become active again', function (array $store) {
        pceWorld($store, function ($world) use ($store) {
            [$role, $user] = pceSupportWorld($world);
            $granting = pceFresh($store, $user->id, ['tickets.view']);
            $world->keys[] = $granting['key'];
            $oldEpoch = pceEpoch();
            expect($granting['has']['tickets.view'])->toBeTrue();

            app(AccessGrants::class)->syncRolePermissions($role, ['tickets.create']); // revoked; the entry that GRANTS is still in the store
            expect(Cache::store('permission-race')->has($granting['key']))->toBeTrue();

            // What `cache:clear` on the database store, or a restore, does to the row.
            DB::table(app(PermissionCacheEpoch::class)->tableName())->where('key', app(PermissionCacheEpoch::class)->key())->delete();
            $after = pceFresh($store, $user->id, ['tickets.view']);
            $world->keys[] = $after['key'];

            expect(pceEpoch())->not->toBe($oldEpoch)
                ->and($after['key'])->not->toBe($granting['key'])
                ->and($after['has']['tickets.view'])->toBeFalse('the stale entry exists but is unreachable under a fresh random token');
        });
    })->with('epoch cache stores');
});

describe('a persistent queue worker', function () {
    it('H: processes consecutive jobs across a committed revocation and never carries the old mapping into the next job', function (array $store) {
        pceWorld($store, function ($world) use ($store) {
            [$role, $user] = pceSupportWorld($world);
            $worker = pceReader($store, $user->id, ['tickets.view'], ['mode' => 'worker', 'jobs' => 2, 'barrier' => $world->barrier, 'name' => 'worker']);
            $worker->start();
            pceAwait($worker, $world->barrier, 'worker', 'job-1');
            $epochBefore = pceEpoch();

            app(AccessGrants::class)->syncRolePermissions($role, ['tickets.create']);
            $epochAfter = pceEpoch();
            pceRelease($world->barrier, 'worker', 'job-1');
            $jobs = pceOutput($worker)['jobs'];
            array_push($world->keys, $jobs[1]['key'], $jobs[2]['key']);

            expect($jobs[1]['has']['tickets.view'])->toBeTrue('job 1 began before the revocation')
                ->and($jobs[1]['key'])->toEndWith(':'.$epochBefore)
                ->and($jobs[2]['has']['tickets.view'])->toBeFalse('job 2 began after it')
                ->and($jobs[2]['key'])->toEndWith(':'.$epochAfter);
        });
    })->with('epoch cache stores');

    it('H: CONTROL: without the job-boundary reset the same persistent process would keep granting', function (array $store) {
        pceWorld($store, function ($world) use ($store) {
            [$role, $user] = pceSupportWorld($world);
            $worker = pceReader($store, $user->id, ['tickets.view'], ['mode' => 'worker', 'jobs' => 2, 'skipJobEvent' => true, 'barrier' => $world->barrier, 'name' => 'worker']);
            $worker->start();
            pceAwait($worker, $world->barrier, 'worker', 'job-1');

            app(AccessGrants::class)->syncRolePermissions($role, ['tickets.create']);
            pceRelease($world->barrier, 'worker', 'job-1');
            $jobs = pceOutput($worker)['jobs'];
            array_push($world->keys, $jobs[1]['key'], $jobs[2]['key']);

            expect($jobs[2]['has']['tickets.view'])->toBeTrue('CONTROL: the registrar is a singleton; it is the JobProcessing reset that protects a worker')
                ->and($jobs[2]['key'])->toBe($jobs[1]['key']);
        });
    })->with('epoch cache stores');
});

describe('the generation row', function () {
    it('K: concurrent first use converges on one token and leaves one row', function () {
        $store = ['driver' => 'file', 'path' => storage_path('framework/cache/permission-epoch-'.uniqid())];
        pceWorld($store, function ($world) use ($store) {
            $epoch = app(PermissionCacheEpoch::class);
            DB::table($epoch->tableName())->where('key', $epoch->key())->delete(); // the very first use

            $readers = [];
            foreach (range(1, 6) as $number) {
                $reader = pceReader($store, 0, [], ['mode' => 'epoch', 'barrier' => $world->barrier, 'name' => "r$number"]);
                $reader->start();
                $readers[] = $reader;
            }
            foreach ($readers as $number => $reader) {
                $flag = $world->barrier.'/r'.($number + 1).'.ready';
                $deadline = microtime(true) + 60;
                while (! file_exists($flag) && $reader->isRunning() && microtime(true) < $deadline) {
                    usleep(500);
                }
            }
            touch($world->barrier.'/go'); // every reader is booted and polling: release them together

            $tokens = array_map(fn (Process $reader) => pceOutput($reader)['epoch'], $readers);

            expect(count(array_unique($tokens)))->toBe(1, 'six processes, one token')
                ->and($tokens[0])->toMatch('/^[0-9a-f]{32}$/')
                ->and(DB::table($epoch->tableName())->where('key', $epoch->key())->count())->toBe(1)
                ->and(DB::table($epoch->tableName())->where('key', $epoch->key())->value('value'))->toBe($tokens[0]);
        });
    });

    it('reads the generation from the primary connection, never from a replica', function () {
        $store = ['driver' => 'file', 'path' => storage_path('framework/cache/permission-epoch-'.uniqid())];
        pceWorld($store, function () {
            $committed = pceEpoch();
            $default = config('database.connections.'.config('database.default'));
            // A deployment with read/write splitting whose replica is unreachable (nothing listens on port 1).
            config(['database.connections.epoch-split' => [...$default, 'read' => ['host' => ['127.0.0.1'], 'port' => 1], 'write' => ['host' => [$default['host']]]]]);
            $connection = DB::connection('epoch-split');

            expect(fn () => $connection->table('cache')->where('key', app(PermissionCacheEpoch::class)->key())->value('value'))
                ->toThrow(QueryException::class, 'Connection refused');

            $manager = Mockery::mock(DatabaseManager::class);
            $manager->shouldReceive('connection')->andReturn($connection);

            expect((new PermissionCacheEpoch($manager))->current())->toBe($committed, 'the read goes to the write connection, so a lagging or dead replica cannot matter');
            DB::purge('epoch-split');
        });
    });
});

describe('failure semantics', function () {
    it('L: if the generation cannot be read, the check fails instead of falling back to a possibly stale cache', function () {
        $store = ['driver' => 'file', 'path' => storage_path('framework/cache/permission-epoch-'.uniqid())];
        pceWorld($store, function ($world) use ($store) {
            [, $user] = pceSupportWorld($world);
            $warm = pceFresh($store, $user->id, ['tickets.view']); // a granting entry is in the shared store
            $world->keys[] = $warm['key'];

            config(['cache.stores.database.table' => 'missing_epoch_table']);
            app(PermissionRegistrar::class)->clearPermissionsCollection();

            try {
                expect(fn () => User::findOrFail($user->id)->checkPermissionTo('tickets.view'))->toThrow(QueryException::class)
                    ->and(Cache::store('permission-race')->has($warm['key']))->toBeTrue('the entry was not consulted and not removed: the check simply cannot proceed');
            } finally {
                config(['cache.stores.database.table' => 'cache']);
            }
        });
    });

    it('L: if the generation cannot be advanced, the grant change does not commit', function () {
        $store = ['driver' => 'file', 'path' => storage_path('framework/cache/permission-epoch-'.uniqid())];
        pceWorld($store, function ($world) use ($store) {
            [$role, $user] = pceSupportWorld($world);
            $before = pceEpoch();
            DB::unprepared('DROP TRIGGER IF EXISTS permission_epoch_write_fails');
            DB::unprepared("CREATE TRIGGER permission_epoch_write_fails BEFORE UPDATE ON `cache` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'epoch write refused'");

            try {
                expect(fn () => app(AccessGrants::class)->syncRolePermissions($role, ['tickets.create']))->toThrow(QueryException::class, 'epoch write refused');
            } finally {
                DB::unprepared('DROP TRIGGER IF EXISTS permission_epoch_write_fails');
            }

            $fresh = pceFresh($store, $user->id, ['tickets.view']);
            $world->keys[] = $fresh['key'];
            expect(pceGrantExists('support', 'tickets.view'))->toBeTrue('the revocation did not commit')
                ->and(pceEpoch())->toBe($before)
                ->and($fresh['has']['tickets.view'])->toBeTrue('and so nothing is inconsistent: grants and generation are both unchanged');
        });
    });
});
