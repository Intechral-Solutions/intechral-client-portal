<?php

use App\Models\User;
use App\Support\Permissions\EpochPermissionRegistrar;
use App\Support\Permissions\PermissionCacheEpoch;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Support/committed_installation.php';
require_once __DIR__.'/../../Support/permission_cache_harness.php';
require_once __DIR__.'/../../Support/permission_cache_trace.php';

uses()->group('database-cache-security');

/*
 * Release 0.9 (R0.9 WP2): the permission-cache security invariant on the DATABASE cache store (CACHE_STORE=database, the
 * approved shared-hosting profile), with the real MariaDB cache driver and independent PHP processes.
 *
 * SECURITY INVARIANT. Once a role-permission revocation has committed, an authorization check that begins afterward must
 * not grant through stale shared permission-cache data. A decision already under way may finish under the state it read.
 * (The mechanism is the epoch fence, PermissionCacheEpoch; PermissionCacheEpochTest proves it on the file and Redis stores.)
 *
 * WHY THE DATABASE STORE NEEDS ITS OWN TESTS. On the file and Redis stores a cache write is independent of the writer's
 * transaction. Here the cache is rows of the `cache` table on the SAME connection the permission change uses, so
 *  - a cache write made inside the writer's transaction commits or rolls back WITH it;
 *  - the writer's transaction holds InnoDB locks on `cache` rows (the epoch row, and any entry it inserted), which another process's
 *    cache write can wait for (see the "locking" group; this is latency, never authorization);
 *  - an upsert of an identical entry changes no row, so MariaDB reports 0 affected rows and Laravel fires KeyWriteFailed
 *    although the entry is in the table. KeyWritten and KeyWriteFailed both mean "the write was issued": the tests read the
 *    table, not the event, to decide what is stored.
 *
 * HOW THESE TESTS STAY DETERMINISTIC.
 *  1. The test process owns no transaction and no lock. The revocation runs in a child (permission_cache_tx_writer.php) that
 *     parks with its transaction open and ends it only when told. A test process that owned that transaction while waiting
 *     for a child that needs one of its locks would deadlock without InnoDB being able to see it (50 s lock-wait timeout).
 *  2. Every process appends its events to ONE ordered trace (permission_cache_trace.php) before it signals anything that lets
 *     another process proceed. The interleavings themselves are enforced by the flag-file barriers: a parked reader or writer
 *     cannot go on until the test releases it, so the order the scenario needs is the only order that can happen. Each
 *     scenario then asserts the events that matter as an ORDERED SUBSEQUENCE of the trace (dcsExpectOrder; other events may fall
 *     between them), so it cannot pass merely because a reader never reached the vulnerable point, or because two things that
 *     should be ordered happened to be. It is not an assertion that the trace contains nothing else.
 *  3. A reader that must write its cache entry while a writer's transaction is open does so only against a writer that holds no
 *     row of that entry (the raw writer path loads no mapping) and with a lock wait bounded to a few seconds, so a violated
 *     assumption fails loudly and quickly instead of hanging. A reader whose write COULD wait for the writer (it stores the
 *     very row a loading writer has inserted, DB-L3) is never waited for while that transaction is open: it is released only
 *     after the transaction has ended. A write that waits can only complete after the commit, which is the post-commit
 *     "straddle" of scenario A.
 *  4. No wait is a sleep or a poll of information_schema: a wait is for a flag file that the other process creates, bounded
 *     at 60 s, and a timeout, a crash or a lock-wait error fails the test. Nothing here is skipped.
 *
 * ISOLATION. Everything runs in the testing database. Each test uses one cache prefix of its own (CACHE_PREFIX in the
 * children, `cache.prefix` here, so the epoch row and the entries share it, as in production) and deletes every row under it
 * and every row it committed; it then proves that every table except `migrations` is empty.
 *
 * Like the other concurrency tests these end RefreshDatabase's transaction so the children see committed rows. Because they
 * leave the database provably empty again, they re-open a transaction before teardown, which keeps Laravel from re-running
 * `migrate:fresh` (about 16 s) after every one of them.
 */

dataset('database cache layouts', [
    'default connection (the Release 0.9 profile)' => fn () => ['connection' => null],
    'separate cache connection (DB_CACHE_CONNECTION=mariadb; not the approved profile)' => fn () => ['connection' => 'mariadb'],
]);

dataset('release 0.9 profile', [
    'default connection (the Release 0.9 profile)' => fn () => ['connection' => null],
]);

final class DatabaseCacheWorld
{
    /** @var list<int> */
    public array $userIds = [];

    /** @var list<Process> */
    public array $processes = [];

    public string $barrier = '';

    public string $prefix = '';

    public ?string $connection = null;
}

/** Facts about the server the whole suite relies on; empty unless the testing database is in the expected, clean state. */
function dcsNonEmptyTables(): array
{
    $tables = array_map(
        fn (object $row) => $row->name,
        DB::select("select table_name as name from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE'"),
    );

    return array_values(array_filter($tables, fn (string $table) => $table !== 'migrations' && DB::table($table)->exists()));
}

/**
 * Run $body against a committed installation with the database store as the permission cache, then remove everything.
 * $connection null is the approved profile (the store uses the default connection, as the epoch row and the permission
 * models do); 'mariadb' is DB_CACHE_CONNECTION pointing at a second connection (config/database.php defines one) to the same database.
 */
function dcsWorld(?string $connection, Closure $body): void
{
    expect(dcsNonEmptyTables())->toBe([], 'the testing database starts empty');

    $world = new DatabaseCacheWorld;
    $world->connection = $connection;
    $world->prefix = 'wp2dbcache-'.bin2hex(random_bytes(4)).'-';
    $world->barrier = sys_get_temp_dir().'/dcs-'.bin2hex(random_bytes(4));
    mkdir($world->barrier);

    // The profile first, then the installation: every cache row this test or its children write, the installation's included
    // (each permission it creates advances the epoch), is under the test's own prefix, and so is found and removed.
    config([
        'cache.default' => 'database',
        'cache.prefix' => $world->prefix,
        'cache.stores.database.connection' => null,
        'permission.cache.expiration_time' => 300,
    ]);
    Cache::forgetDriver('database');
    app(PermissionRegistrar::class)->initializeCache();
    $cleanup = committedSynchronizedInstallation();

    // A separate cache connection only after the installation: a process that advances the epoch in a transaction on the
    // default connection and writes cache entries on a second one can WAIT FOR ITS OWN TRANSACTION (see the "locking" group
    // and docs/architecture/role-governance.md), and the installation is one long such transaction.
    config(['cache.stores.database.connection' => $connection]);
    Cache::forgetDriver('database');
    app(PermissionRegistrar::class)->initializeCache();
    app(PermissionRegistrar::class)->clearPermissionsCollection();

    $ok = false;
    try {
        $body($world);
        $ok = true;
    } finally {
        foreach ($world->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0); // a child that holds a transaction releases it by dying
            }
        }
        foreach (glob($world->barrier.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($world->barrier);

        $cleanup($world->userIds);
        DB::table('cache')->where('key', 'like', $world->prefix.'%')->delete();

        if ($ok) {
            expect(dcsNonEmptyTables())->toBe([], 'the test left the database empty again');
            DB::beginTransaction(); // so teardown rolls back instead of scheduling another migrate:fresh
        }
    }
}

/** A custom role carrying two permissions and a user who holds only that role. Nothing of this is in the cache yet. */
function dcsSupportWorld(DatabaseCacheWorld $world): array
{
    $role = Role::create(['name' => 'support', 'guard_name' => 'web'])->givePermissionTo(['tickets.view', 'tickets.create']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $world->userIds[] = $user->id;
    app(PermissionRegistrar::class)->clearPermissionsCollection();
    DB::table('cache')->where('key', 'like', $world->prefix.'spatie.permission.cache:%')->delete(); // the setup may have cached a mapping

    return [$role, $user];
}

function dcsEnv(DatabaseCacheWorld $world): array
{
    return [
        'CACHE_STORE' => 'database',
        'CACHE_PREFIX' => $world->prefix,
        'DB_CACHE_CONNECTION' => $world->connection ?? false, // false: not set at all
    ];
}

/** A reader (or a persistent worker, with mode=worker) in its own process, reading the cache as the application does under CACHE_STORE=database. */
function dcsReader(DatabaseCacheWorld $world, string $name, int $userId, array $permissions, array $options = []): Process
{
    $process = pceReader(['profile' => 'database'], $userId, $permissions, ['barrier' => $world->barrier, 'name' => $name] + $options, dcsEnv($world));
    $world->processes[] = $process;

    return $process;
}

/** A reader that starts, decides and ends (no barrier of its own). */
function dcsFresh(DatabaseCacheWorld $world, string $name, int $userId, array $permissions, array $options = []): array
{
    $process = dcsReader($world, $name, $userId, $permissions, $options);
    $process->start();

    return pceOutput($process);
}

function dcsAwaitFlag(Process $process, string $flag, string $what): void
{
    $deadline = microtime(true) + 60;
    while (! file_exists($flag)) {
        if (! $process->isRunning()) {
            throw new RuntimeException("$what ended before it reached its barrier: ".$process->getOutput().$process->getErrorOutput());
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException("$what never reached its barrier");
        }
        usleep(500);
    }
}

function dcsAwaitParked(Process $process, DatabaseCacheWorld $world, string $name, string $point): void
{
    dcsAwaitFlag($process, "$world->barrier/$name.$point.reached", "reader $name at $point");
}

function dcsRelease(DatabaseCacheWorld $world, string $name, string $point): void
{
    permissionCacheTrace($world->barrier, 'test', "release $name@$point");
    touch("$world->barrier/$name.$point.release");
}

/**
 * Start a revocation in its own process and return once its transaction is OPEN with the change in it, uncommitted.
 *
 * @return array{process: Process, name: string}
 */
function dcsWriterOpen(DatabaseCacheWorld $world, array $options = []): array
{
    $options = $options + ['role' => 'support', 'keep' => ['tickets.create'], 'barrier' => $world->barrier, 'name' => 'writer'];
    $process = new Process([PHP_BINARY, base_path('tests/Support/permission_cache_tx_writer.php'), '{}', json_encode($options)], base_path(), pceEnv(dcsEnv($world)));
    $process->setTimeout(120);
    $world->processes[] = $process;
    $process->start();
    dcsAwaitFlag($process, "$world->barrier/{$options['name']}.prepared", "writer {$options['name']}");

    return ['process' => $process, 'name' => $options['name']];
}

/** End that transaction ('commit' or 'rollback') and wait for the writer, including its application hooks, to finish. */
function dcsWriterEnd(DatabaseCacheWorld $world, array $writer, string $decision): array
{
    permissionCacheTrace($world->barrier, 'test', "decide:$decision");
    $path = "$world->barrier/{$writer['name']}.decide";
    file_put_contents("$path.tmp", $decision);
    rename("$path.tmp", $path);

    return pceOutput($writer['process']);
}

function dcsWriterRun(DatabaseCacheWorld $world, array $options = [], string $decision = 'commit'): array
{
    return dcsWriterEnd($world, dcsWriterOpen($world, $options), $decision);
}

function dcsEpoch(): string
{
    return app(PermissionCacheEpoch::class)->current();
}

function dcsEntryExists(DatabaseCacheWorld $world, string $epoch): bool
{
    return DB::table('cache')->where('key', $world->prefix.config('permission.cache.key').':'.$epoch)->exists();
}

function dcsEntryRows(DatabaseCacheWorld $world, string $epoch): int
{
    return DB::table('cache')->where('key', $world->prefix.config('permission.cache.key').':'.$epoch)->count();
}

/**
 * Make sure a reader that starts now MISSES under $epoch, without ever touching a row a writer's open transaction holds.
 * On the default connection the writer's own reload (every syncPermissions loads the mapping and stores it under the current
 * epoch) is an uncommitted INSERT: invisible to other connections, so a reader misses by itself, and deleting that row from
 * here would wait for the writer's lock. With a separate cache connection the same write commits at once, so it is removed.
 */
function dcsGoCold(DatabaseCacheWorld $world, string $epoch): void
{
    if ($world->connection !== null) {
        DB::table('cache')->where('key', $world->prefix.config('permission.cache.key').':'.$epoch)->delete();
    }
}

/** The write a reader's cache put issues (an upsert of one entry), made from the test process's own connection. */
function dcsReaderWrite(DatabaseCacheWorld $world, string $epoch = 'f00dfeedf00dfeedf00dfeedf00dfeed'): void
{
    DB::table('cache')->upsert([['key' => $world->prefix.'spatie.permission.cache:'.$epoch, 'value' => serialize('probe'), 'expiration' => time() + 300]], 'key');
}

/** The sequence of events, in the order they happened, must contain $expected as a subsequence (`*` matches any text). */
function dcsExpectOrder(DatabaseCacheWorld $world, array $expected): void
{
    $events = array_map(fn (array $event) => $event['actor'].' '.$event['event'], permissionCacheTraceRead($world->barrier));
    $from = 0;
    foreach ($expected as $pattern) {
        $found = null;
        for ($index = $from; $index < count($events); $index++) {
            if (fnmatch($pattern, $events[$index])) {
                $found = $index;
                break;
            }
        }
        expect($found)->not->toBeNull("expected '$pattern' after event #$from in:\n".implode("\n", $events));
        $from = $found + 1;
    }
}

/** What every child must confirm: it really ran the application's fenced registrar on the database store. */
function dcsExpectDatabaseStore(array ...$outputs): void
{
    foreach ($outputs as $output) {
        expect($output['store'])->toBe(DatabaseStore::class)
            ->and($output['registrar'])->toBe(EpochPermissionRegistrar::class);
    }
}

const DCS_BOTH = ['tickets.view', 'tickets.create'];

describe('preconditions', function () {
    it('DB-P: runs on the pinned server and the profile the Release 0.9 hosting uses', function () {
        $version = DB::selectOne('select version() as v')->v;
        $required = getenv('REQUIRE_MARIADB_VERSION');
        $engine = DB::selectOne("select engine as e from information_schema.tables where table_schema = database() and table_name = 'cache'")->e;

        expect($version)->toContain('MariaDB')
            ->and($required === false || $required === '' || str_starts_with($version, $required))->toBeTrue("MariaDB $required is required here, the server reports $version")
            ->and(DB::selectOne('select @@transaction_isolation as i')->i)->toBe('REPEATABLE-READ')
            ->and(strtoupper($engine))->toBe('INNODB')
            ->and(DB::getDatabaseName())->toBe('intechral_client_portal_testing')
            // CACHE_STORE=database reaches the permission library through its `default` store, on the default connection.
            ->and(config('permission.cache.store'))->toBe('default')
            ->and(config('cache.stores.database.driver'))->toBe('database')
            ->and(config('cache.stores.database.connection'))->toBeNull()
            ->and(config('cache.stores.database.table'))->toBe('cache');
    });
});

describe('the straddle: a reader holding the old mapping stores it after the revocation has committed', function () {
    it('DB-A: a stale reader cannot restore a permission revoked by a committed writer; a reader that begins afterward sees the revocation', function (array $layout) {
        dcsWorld($layout['connection'], function ($world) {
            [, $user] = dcsSupportWorld($world);
            $e0 = dcsEpoch();

            $old = dcsReader($world, 'old', $user->id, DCS_BOTH, ['pause' => 'cache-write']);
            $old->start();
            dcsAwaitParked($old, $world, 'old', 'cache-write'); // it has read epoch E0 and the OLD rows, and is about to store them

            $writer = dcsWriterOpen($world);
            $epochWhileOpen = dcsEpoch();
            $grantWhileOpen = pceGrantExists('support', 'tickets.view');
            $written = dcsWriterEnd($world, $writer, 'commit');
            $eAfter = dcsEpoch();

            dcsRelease($world, 'old', 'cache-write');
            $oldOut = pceOutput($old);
            $fresh = dcsFresh($world, 'fresh', $user->id, DCS_BOTH);

            dcsExpectOrder($world, [
                'old booted', 'old parked@cache-write',
                'writer tx-open', 'writer revoked-uncommitted',
                'test decide:commit', 'writer committed', 'writer done',
                'test release old@cache-write', 'old resumed@cache-write', 'old cache-write:*', 'old decided',
                'fresh booted', 'fresh decided',
            ]);
            dcsExpectDatabaseStore($oldOut, $fresh);
            expect($grantWhileOpen)->toBeTrue('nobody else sees the uncommitted revocation')
                ->and($epochWhileOpen)->toBe($e0, 'and nobody else sees the new epoch before the commit')
                ->and($written['epochInTransaction'])->not->toBe($e0, 'the transaction did create a new epoch')
                ->and(pceGrantExists('support', 'tickets.view'))->toBeFalse('the revocation is committed')
                ->and($eAfter)->not->toBe($e0)
                ->and($oldOut['has'])->toBe(['tickets.view' => true, 'tickets.create' => true], 'the decision already under way finishes under the state it read')
                ->and($oldOut['evidence']['pausedKey'])->toBe($oldOut['evidence']['writtenKey'], 'it stored exactly the entry it had been stopped on')
                ->and($oldOut['evidence']['writtenKey'])->toEndWith(':'.$e0, 'under the OBSOLETE epoch')
                ->and(dcsEntryExists($world, $e0))->toBeTrue('the stale mapping really is in the shared table')
                ->and($fresh['key'])->toEndWith(':'.$eAfter)
                ->and($fresh['has'])->toBe(['tickets.view' => false, 'tickets.create' => true], 'a process that begins after the commit sees the revocation');
        });
    })->with('database cache layouts');

    it('DB-A-control: NEGATIVE CONTROL: the library\'s unfenced registrar loses the same race on the database store, so the test above has teeth', function (array $layout) {
        dcsWorld($layout['connection'], function ($world) {
            [, $user] = dcsSupportWorld($world);
            $plain = ['registrar' => 'plain'];

            $old = dcsReader($world, 'old', $user->id, DCS_BOTH, ['pause' => 'cache-write'] + $plain);
            $old->start();
            dcsAwaitParked($old, $world, 'old', 'cache-write');
            dcsWriterRun($world, $plain);
            dcsRelease($world, 'old', 'cache-write');
            pceOutput($old);
            $fresh = dcsFresh($world, 'fresh', $user->id, DCS_BOTH, $plain);

            dcsExpectOrder($world, ['old parked@cache-write', 'writer committed', 'writer done', 'old resumed@cache-write', 'old cache-write:*', 'fresh decided']);
            expect(pceGrantExists('support', 'tickets.view'))->toBeFalse('the revocation is committed')
                ->and($fresh['registrar'])->toBe(PermissionRegistrar::class)
                ->and($fresh['store'])->toBe(DatabaseStore::class)
                ->and($fresh['has']['tickets.view'])->toBeTrue('CONTROL: without the fence a process that begins after the commit is still granted the revoked permission')
                ->and($fresh['key'])->toBe('spatie.permission.cache');
        });
    })->with('release 0.9 profile');

    it('DB-B: processes that begin after the commit use the new epoch and all observe the revocation, whichever of them stores the entry', function (array $layout) {
        dcsWorld($layout['connection'], function ($world) {
            [, $user] = dcsSupportWorld($world);
            $e0 = dcsEpoch();
            $warm = dcsFresh($world, 'warm', $user->id, DCS_BOTH); // the old mapping is committed in the cache, as for any role in use

            dcsWriterRun($world);
            $eAfter = dcsEpoch();

            // Three cold readers at once: they miss, load the same mapping and store the same entry. MariaDB reports
            // 0 affected rows for an identical upsert, so some of them see KeyWriteFailed although the entry is there.
            $readers = array_map(fn (string $name) => dcsReader($world, $name, $user->id, DCS_BOTH), ['after1', 'after2', 'after3']);
            array_walk($readers, fn (Process $reader) => $reader->start());
            $outputs = array_map(fn (Process $reader) => pceOutput($reader), $readers);

            dcsExpectOrder($world, ['warm decided', 'writer committed', 'writer done', 'after* decided']);
            dcsExpectDatabaseStore($warm, ...$outputs);
            expect($warm['key'])->toEndWith(':'.$e0)
                ->and($warm['has']['tickets.view'])->toBeTrue()
                ->and(dcsEntryExists($world, $e0))->toBeTrue('the granting entry of the old epoch is still in the table, and unreachable');
            foreach ($outputs as $output) {
                expect($output['key'])->toEndWith(':'.$eAfter)
                    ->and($output['has'])->toBe(['tickets.view' => false, 'tickets.create' => true])
                    ->and($output['evidence']['writeOutcome'])->toBeIn(['written', 'unchanged', null], 'written, unchanged (KeyWriteFailed) or a hit on another reader\'s entry');
            }
            expect(collect($outputs)->pluck('evidence.writeOutcome')->contains('written'))->toBeTrue('the entry was stored by somebody')
                ->and(dcsEntryRows($world, $eAfter))->toBe(1, 'and there is exactly one row for it, whoever stored it and however the event was reported');
        });
    })->with('database cache layouts');

    it('DB-J: a reader interrupted between the library\'s permissions query and its roles query can only fill the obsolete epoch', function (array $layout) {
        dcsWorld($layout['connection'], function ($world) {
            [, $user] = dcsSupportWorld($world);
            $e0 = dcsEpoch();

            $old = dcsReader($world, 'old', $user->id, DCS_BOTH, ['pause' => 'after-permissions-query']);
            $old->start();
            dcsAwaitParked($old, $world, 'old', 'after-permissions-query');
            dcsWriterRun($world);
            $eAfter = dcsEpoch();
            dcsRelease($world, 'old', 'after-permissions-query');
            $oldOut = pceOutput($old);
            $fresh = dcsFresh($world, 'fresh', $user->id, DCS_BOTH);

            dcsExpectOrder($world, ['old parked@after-permissions-query', 'writer committed', 'writer done', 'old resumed@after-permissions-query', 'old cache-write:*', 'fresh decided']);
            expect($oldOut['evidence']['writtenKey'])->toEndWith(':'.$e0, 'a mixed read is stored under the obsolete epoch')
                ->and($fresh['key'])->toEndWith(':'.$eAfter)
                ->and($fresh['has']['tickets.view'])->toBeFalse();
        });
    })->with('release 0.9 profile');
});

describe('a transaction that does not commit', function () {
    it('DB-C1: a rolled-back revocation leaves the committed grants and epoch as they were; its own cache write is rolled back with it on the default connection', function (array $layout) {
        dcsWorld($layout['connection'], function ($world) use ($layout) {
            [, $user] = dcsSupportWorld($world);
            $e0 = dcsEpoch();
            $warm = dcsFresh($world, 'warm', $user->id, DCS_BOTH); // E0's entry is committed: a reader during the open transaction hits it

            // The library alone (no application hook): the epoch is advanced inside the transaction and nowhere else, and the
            // writer's own reload stores the UNCOMMITTED state under that new epoch.
            $writer = dcsWriterOpen($world, ['path' => 'library', 'reload' => true]);
            $epochWhileOpen = dcsEpoch();
            $during = dcsFresh($world, 'during', $user->id, DCS_BOTH);
            $result = dcsWriterEnd($world, $writer, 'rollback');
            $uncommittedEpoch = $result['epochInTransaction'];
            $entryVisibleAfterRollback = dcsEntryExists($world, $uncommittedEpoch);
            $after = dcsFresh($world, 'after', $user->id, DCS_BOTH);

            dcsExpectOrder($world, ['warm decided', 'writer revoked-uncommitted', 'during decided', 'test decide:rollback', 'writer rolled-back', 'writer done', 'after decided']);
            dcsExpectDatabaseStore($warm, $during, $after);
            $sharesTransaction = $layout['connection'] === null;
            expect($result['epochInTransaction'])->not->toBe($e0, 'the transaction did move the epoch')
                ->and($result['cacheEntryInTransaction'])->toBeTrue('and its reload did write the uncommitted mapping to the cache table')
                ->and($epochWhileOpen)->toBe($e0, 'which nobody else sees')
                ->and($during['key'])->toEndWith(':'.$e0)
                ->and($during['has'])->toBe(['tickets.view' => true, 'tickets.create' => true], 'a reader during the open transaction is still granted: the revocation has not committed')
                ->and($during['evidence']['writeOutcome'])->toBeNull('and it hit the committed entry instead of writing')
                ->and(dcsEpoch())->toBe($e0, 'the rollback restored the epoch exactly')
                ->and(pceGrantExists('support', 'tickets.view'))->toBeTrue()
                ->and($entryVisibleAfterRollback)->toBe(! $sharesTransaction, $sharesTransaction
                    ? 'on the default connection the cache write rolled back with the transaction (unlike the file and Redis stores)'
                    : 'on a separate connection the cache write committed on its own, and is unreachable')
                ->and($after['key'])->toEndWith(':'.$e0)
                ->and($after['has'])->toBe(['tickets.view' => true, 'tickets.create' => true], 'the previously committed authorization state stands');
        });
    })->with('database cache layouts');

    it('DB-C2: a rollback through the application\'s hooks also leaves the committed grants authoritative, on a fresh epoch', function (array $layout) {
        dcsWorld($layout['connection'], function ($world) {
            [, $user] = dcsSupportWorld($world);
            $e0 = dcsEpoch();
            dcsFresh($world, 'warm', $user->id, DCS_BOTH);

            $result = dcsWriterRun($world, ['reload' => true], 'rollback');
            $eAfter = dcsEpoch();
            $after = dcsFresh($world, 'after', $user->id, DCS_BOTH);

            dcsExpectOrder($world, ['writer revoked-uncommitted', 'test decide:rollback', 'writer rolled-back', 'writer done', 'after decided']);
            expect(pceGrantExists('support', 'tickets.view'))->toBeTrue()
                ->and($result['epochInTransaction'])->not->toBe($e0)
                ->and($eAfter)->not->toBe($e0, 'the post-rollback hook advanced the epoch once more: a harmless extra reload')
                ->and($eAfter)->not->toBe($result['epochInTransaction'])
                ->and($after['key'])->toEndWith(':'.$eAfter)
                ->and($after['has'])->toBe(['tickets.view' => true, 'tickets.create' => true]);
        });
    })->with('release 0.9 profile');

    it('DB-E1: the writer\'s own cache write commits with the transaction on the database store, under an epoch the post-commit hook then retires', function (array $layout) {
        dcsWorld($layout['connection'], function ($world) {
            [, $user] = dcsSupportWorld($world);

            $writer = dcsWriterOpen($world, ['reload' => true]);
            $result = dcsWriterEnd($world, $writer, 'commit');
            $e1 = $result['epochInTransaction'];
            $eAfter = dcsEpoch();
            $fresh = dcsFresh($world, 'fresh', $user->id, DCS_BOTH);

            expect($result['cacheEntryInTransaction'])->toBeTrue()
                ->and(dcsEntryExists($world, $e1))->toBeTrue('the cache write committed together with the revocation')
                ->and($eAfter)->not->toBe($e1, 'the post-commit hook moved the epoch past the writer\'s own entry')
                ->and($fresh['key'])->toEndWith(':'.$eAfter)
                ->and($fresh['has'])->toBe(['tickets.view' => false, 'tickets.create' => true]);
        });
    })->with('database cache layouts');
});

describe('consecutive revocations', function () {
    it('DB-D: stale readers that finish out of order never resurrect an earlier state', function (array $layout, string $order) {
        dcsWorld($layout['connection'], function ($world) use ($order) {
            [, $user] = dcsSupportWorld($world);

            $e0 = dcsEpoch();
            $first = dcsReader($world, 'r1', $user->id, DCS_BOTH, ['pause' => 'cache-write']);
            $first->start();
            dcsAwaitParked($first, $world, 'r1', 'cache-write');
            dcsWriterRun($world, ['name' => 'w1', 'keep' => ['tickets.create']]); // revokes tickets.view
            $e1 = dcsEpoch();

            $second = dcsReader($world, 'r2', $user->id, DCS_BOTH, ['pause' => 'cache-write']);
            $second->start();
            dcsAwaitParked($second, $world, 'r2', 'cache-write');
            dcsWriterRun($world, ['name' => 'w2', 'keep' => []]); // revokes tickets.create
            $e2 = dcsEpoch();

            $readers = ['r1' => $first, 'r2' => $second];
            foreach ($order === 'second reader first' ? ['r2', 'r1'] : ['r1', 'r2'] as $name) {
                dcsRelease($world, $name, 'cache-write');
                $out[$name] = pceOutput($readers[$name]);
            }
            $fresh = dcsFresh($world, 'fresh', $user->id, DCS_BOTH);

            dcsExpectOrder($world, [
                'r1 parked@cache-write', 'w1 committed', 'w1 done', 'r2 parked@cache-write', 'w2 committed', 'w2 done',
                'test release *@cache-write', '* resumed@cache-write', '* cache-write:*', 'fresh decided',
            ]);
            dcsExpectDatabaseStore($out['r1'], $out['r2'], $fresh);
            expect(count(array_unique([$e0, $e1, $e2])))->toBe(3, 'each commit produced a new epoch')
                ->and($out['r1']['has'])->toBe(['tickets.view' => true, 'tickets.create' => true])
                ->and($out['r2']['has'])->toBe(['tickets.view' => false, 'tickets.create' => true])
                ->and($out['r1']['evidence']['writtenKey'])->toEndWith(':'.$e0)
                ->and($out['r2']['evidence']['writtenKey'])->toEndWith(':'.$e1)
                ->and(dcsEntryExists($world, $e0) && dcsEntryExists($world, $e1))->toBeTrue('both stale mappings are in the table')
                ->and($fresh['key'])->toEndWith(':'.$e2)
                ->and($fresh['has'])->toBe(['tickets.view' => false, 'tickets.create' => false]);
        });
    })->with('database cache layouts')->with(['first reader first', 'second reader first']);
});

describe('a cache miss while a revocation is in flight', function () {
    it('DB-F1: a reader that misses during the writer\'s open transaction and stores AFTER the commit fills only the obsolete epoch', function (array $layout) {
        dcsWorld($layout['connection'], function ($world) {
            [, $user] = dcsSupportWorld($world);
            $e0 = dcsEpoch();

            $writer = dcsWriterOpen($world); // revoked, uncommitted
            dcsGoCold($world, $e0);
            $old = dcsReader($world, 'old', $user->id, DCS_BOTH, ['pause' => 'cache-write']);
            $old->start();
            dcsAwaitParked($old, $world, 'old', 'cache-write'); // cold cache: it missed, read epoch E0 and the committed OLD rows
            $result = dcsWriterEnd($world, $writer, 'commit');
            $eAfter = dcsEpoch();
            dcsRelease($world, 'old', 'cache-write');
            $oldOut = pceOutput($old);
            $fresh = dcsFresh($world, 'fresh', $user->id, DCS_BOTH);

            dcsExpectOrder($world, [
                'writer revoked-uncommitted', 'old parked@cache-write', 'test decide:commit', 'writer committed', 'writer done',
                'test release old@cache-write', 'old resumed@cache-write', 'old cache-write:*', 'fresh decided',
            ]);
            expect($oldOut['key'])->toEndWith(':'.$e0, 'it read the committed epoch, not the writer\'s uncommitted one')
                ->and($oldOut['key'])->not->toEndWith(':'.$result['epochInTransaction'])
                ->and($oldOut['has']['tickets.view'])->toBeTrue()
                ->and($fresh['key'])->toEndWith(':'.$eAfter)
                ->and($fresh['has'])->toBe(['tickets.view' => false, 'tickets.create' => true], 'no incorrect grant after the commit');
        });
    })->with('database cache layouts');

    it('DB-F2: a reader that misses and stores BEFORE the commit, while the writer\'s transaction is open, fills only the obsolete epoch', function (array $layout) {
        dcsWorld($layout['connection'], function ($world) {
            [, $user] = dcsSupportWorld($world);
            $e0 = dcsEpoch();

            // The shape of the built-in role synchronization: raw SQL, then an explicit epoch advance in the same transaction.
            // It loads no mapping, so it holds no row of the key this reader stores. (A writer that DID load the mapping would
            // hold that row, and a reader storing the same entry would wait for its commit: see DB-L3.) The reader's write
            // therefore needs no lock the writer holds, and its lock wait is bounded, so waiting for it here, while the
            // writer's transaction is open, cannot become a deadlock: a wrong assumption fails within 5 s, never passes.
            $writer = dcsWriterOpen($world, ['path' => 'raw']);
            $old = dcsReader($world, 'old', $user->id, DCS_BOTH, ['pause' => 'cache-write', 'lockWaitTimeout' => 5]);
            $old->start();
            dcsAwaitParked($old, $world, 'old', 'cache-write');
            dcsRelease($world, 'old', 'cache-write');
            $oldOut = pceOutput($old);
            $entryStoredBeforeCommit = dcsEntryExists($world, $e0);
            $result = dcsWriterEnd($world, $writer, 'commit');
            $eAfter = dcsEpoch();
            $fresh = dcsFresh($world, 'fresh', $user->id, DCS_BOTH);

            dcsExpectOrder($world, [
                'writer revoked-uncommitted', 'old parked@cache-write', 'test release old@cache-write', 'old resumed@cache-write',
                'old cache-write:*', 'old decided', 'test decide:commit', 'writer committed', 'writer done', 'fresh decided',
            ]);
            expect($oldOut['key'])->toEndWith(':'.$e0)
                ->and($oldOut['has']['tickets.view'])->toBeTrue('it reads the state that is committed while the revocation is not')
                ->and($entryStoredBeforeCommit)->toBeTrue('and its entry was stored while the writer\'s transaction was still open')
                ->and($result['epochInTransaction'])->not->toBe($e0)
                ->and($fresh['key'])->toEndWith(':'.$eAfter)
                ->and($fresh['has'])->toBe(['tickets.view' => false, 'tickets.create' => true], 'no incorrect grant after the commit');
        });
    })->with('database cache layouts');

    it('DB-F3: an entry stored during a transaction that then rolls back is the committed state, and is correct to serve', function (array $layout) {
        dcsWorld($layout['connection'], function ($world) {
            [, $user] = dcsSupportWorld($world);
            $e0 = dcsEpoch();

            $writer = dcsWriterOpen($world, ['path' => 'raw']);
            $old = dcsReader($world, 'old', $user->id, DCS_BOTH, ['pause' => 'cache-write', 'lockWaitTimeout' => 5]);
            $old->start();
            dcsAwaitParked($old, $world, 'old', 'cache-write');
            dcsRelease($world, 'old', 'cache-write');
            pceOutput($old);
            dcsWriterEnd($world, $writer, 'rollback');
            $after = dcsFresh($world, 'after', $user->id, DCS_BOTH);

            dcsExpectOrder($world, ['writer revoked-uncommitted', 'old cache-write:*', 'test decide:rollback', 'writer rolled-back', 'writer done', 'after decided']);
            expect(pceGrantExists('support', 'tickets.view'))->toBeTrue('the revocation never committed')
                ->and(dcsEpoch())->toBe($e0, 'the epoch rolled back with it')
                ->and($after['key'])->toEndWith(':'.$e0)
                ->and($after['has'])->toBe(['tickets.view' => true, 'tickets.create' => true], 'so granting is correct')
                ->and($after['evidence']['writeOutcome'])->toBeNull('and the entry stored during the transaction was served');
        });
    })->with('database cache layouts');
});

describe('persistent processes and job boundaries', function () {
    it('DB-G1: a persistent queue worker decides every job under the state committed when that job begins, across an open and then a committed revocation', function (array $layout) {
        dcsWorld($layout['connection'], function ($world) {
            [, $user] = dcsSupportWorld($world);
            $e0 = dcsEpoch();

            $worker = dcsReader($world, 'worker', $user->id, DCS_BOTH, ['mode' => 'worker', 'jobs' => 3]);
            $worker->start();
            dcsAwaitParked($worker, $world, 'worker', 'job-1'); // job 1 missed and stored E0's entry

            $writer = dcsWriterOpen($world); // a revocation is in flight, uncommitted
            dcsRelease($world, 'worker', 'job-1');
            dcsAwaitParked($worker, $world, 'worker', 'job-2'); // job 2 hit E0's entry: it needs no lock, so waiting for it here is safe
            dcsWriterEnd($world, $writer, 'commit');
            $eAfter = dcsEpoch();
            dcsRelease($world, 'worker', 'job-2');
            $jobs = pceOutput($worker)['jobs'];

            dcsExpectOrder($world, [
                'worker job-1 decided', 'writer revoked-uncommitted', 'worker job-2 decided', 'test decide:commit', 'writer committed', 'writer done', 'worker job-3 decided',
            ]);
            expect($jobs[1]['key'])->toEndWith(':'.$e0)
                ->and($jobs[1]['has'])->toBe(['tickets.view' => true, 'tickets.create' => true])
                ->and($jobs[2]['key'])->toEndWith(':'.$e0)
                ->and($jobs[2]['has']['tickets.view'])->toBeTrue('job 2 began before the commit')
                ->and($jobs[3]['key'])->toEndWith(':'.$eAfter)
                ->and($jobs[3]['has'])->toBe(['tickets.view' => false, 'tickets.create' => true], 'job 3 began after it');
        });
    })->with('release 0.9 profile');

    it('DB-G2: CONTROL: without the job-boundary reset the same persistent process would keep granting', function (array $layout) {
        dcsWorld($layout['connection'], function ($world) {
            [, $user] = dcsSupportWorld($world);

            $worker = dcsReader($world, 'worker', $user->id, DCS_BOTH, ['mode' => 'worker', 'jobs' => 2, 'skipJobEvent' => true]);
            $worker->start();
            dcsAwaitParked($worker, $world, 'worker', 'job-1');
            dcsWriterRun($world);
            dcsRelease($world, 'worker', 'job-1');
            $jobs = pceOutput($worker)['jobs'];

            dcsExpectOrder($world, ['worker job-1 decided', 'writer committed', 'writer done', 'worker job-2 decided']);
            expect($jobs[2]['has']['tickets.view'])->toBeTrue('CONTROL: the registrar is a singleton; it is the JobProcessing reset that protects a worker')
                ->and($jobs[2]['key'])->toBe($jobs[1]['key']);
        });
    })->with('release 0.9 profile');

    it('DB-G3: a reader inside its own REPEATABLE READ transaction keeps deciding under its snapshot and fills only the obsolete epoch', function (array $layout) {
        dcsWorld($layout['connection'], function ($world) {
            [, $user] = dcsSupportWorld($world);
            $e0 = dcsEpoch();

            $long = dcsReader($world, 'long', $user->id, DCS_BOTH, ['pause' => 'cache-write', 'transaction' => true]);
            $long->start();
            dcsAwaitParked($long, $world, 'long', 'cache-write'); // snapshot taken, E0 and the OLD rows read inside it
            dcsWriterRun($world);
            $eAfter = dcsEpoch();
            dcsRelease($world, 'long', 'cache-write');
            $longOut = pceOutput($long);
            $fresh = dcsFresh($world, 'fresh', $user->id, DCS_BOTH);

            dcsExpectOrder($world, ['long snapshot', 'long parked@cache-write', 'writer committed', 'writer done', 'long resumed@cache-write', 'long cache-write:*', 'long decided', 'fresh decided']);
            expect($eAfter)->not->toBe($e0)
                ->and($longOut['epochSeen'])->toBe($e0, 'inside its snapshot the committed change does not exist yet: epoch and rows agree')
                ->and($longOut['key'])->toEndWith(':'.$e0)
                ->and($longOut['has']['tickets.view'])->toBeTrue('the decision under way finishes under its snapshot')
                ->and(dcsEntryExists($world, $e0))->toBeTrue('its entry is committed with its transaction, under the obsolete epoch')
                ->and($fresh['key'])->toEndWith(':'.$eAfter)
                ->and($fresh['has']['tickets.view'])->toBeFalse();
        });
    })->with('release 0.9 profile');
});

describe('the database store\'s own write semantics', function () {
    it('DB-E2: an upsert of an identical entry reports KeyWriteFailed although the entry is stored; the table, not the event, is the evidence', function (array $layout) {
        dcsWorld($layout['connection'], function ($world) {
            [, $user] = dcsSupportWorld($world);
            $e0 = dcsEpoch();
            $second = time(); // a fixed clock per reader: same second means the same expiration, so the same row

            $readers = [];
            foreach (['r1' => $second, 'r2' => $second, 'r3' => $second + 1] as $name => $clock) {
                $readers[$name] = dcsReader($world, $name, $user->id, DCS_BOTH, ['pause' => 'cache-write', 'freezeTime' => $clock]);
                $readers[$name]->start();
            }
            foreach ($readers as $name => $reader) {
                dcsAwaitParked($reader, $world, $name, 'cache-write'); // all three missed: nobody has stored the entry yet
            }
            $out = [];
            foreach ($readers as $name => $reader) {
                dcsRelease($world, $name, 'cache-write'); // one at a time, so the order of the writes is known
                $out[$name] = pceOutput($reader);
            }
            $hit = dcsFresh($world, 'hit', $user->id, DCS_BOTH);

            dcsExpectOrder($world, [
                'r1 cache-write:written', 'r2 cache-write:unchanged', 'r3 cache-write:written', 'hit decided',
            ]);
            expect($out['r1']['evidence']['writeOutcome'])->toBe('written', 'the first store changed a row')
                ->and($out['r2']['evidence']['writeOutcome'])->toBe('unchanged', 'an identical entry in the same second changed no row: KeyWriteFailed')
                ->and($out['r3']['evidence']['writeOutcome'])->toBe('written', 'a different expiration changed the row again')
                ->and(dcsEntryRows($world, $e0))->toBe(1)
                ->and(collect($out)->every(fn (array $reader) => $reader['has'] === ['tickets.view' => true, 'tickets.create' => true]))->toBeTrue('every reader decided correctly whatever the event reported')
                ->and($hit['evidence']['writeOutcome'])->toBeNull('and the entry all three stored is served as a hit')
                ->and($hit['key'])->toEndWith(':'.$e0);
        });
    })->with('release 0.9 profile');
});

describe('locking (latency and availability of the cache write; not an authorization property)', function () {
    it('DB-L1: a writer\'s open transaction never blocks the epoch read and never shows its uncommitted epoch', function (array $layout) {
        dcsWorld($layout['connection'], function ($world) {
            dcsSupportWorld($world);
            $e0 = dcsEpoch();
            $writer = dcsWriterOpen($world, ['reload' => true]);

            DB::statement('set session innodb_lock_wait_timeout = 1'); // this connection is the probe; a wait would fail it in 1 s
            try {
                $epoch = dcsEpoch();
            } finally {
                DB::statement('set session innodb_lock_wait_timeout = 50');
            }
            $result = dcsWriterEnd($world, $writer, 'commit');

            expect($epoch)->toBe($e0, 'a consistent read of the epoch neither waits for the writer nor sees its uncommitted epoch')
                ->and($result['epochInTransaction'])->not->toBe($e0);
        });
    })->with('release 0.9 profile');

    it('DB-L3: a reader that stores the very entry a LOADING writer\'s open transaction has inserted waits for that transaction, and succeeds after it', function (array $layout) {
        dcsWorld($layout['connection'], function ($world) {
            dcsSupportWorld($world);
            $e0 = dcsEpoch();
            // syncRolePermissions loads the permission mapping and, on a miss, stores it under the current epoch, inside its
            // transaction. That uncommitted row is the one a concurrent reader's own miss would store.
            $writer = dcsWriterOpen($world);

            DB::statement('set session innodb_lock_wait_timeout = 1');
            $error = null;
            try {
                dcsReaderWrite($world, $e0);
            } catch (QueryException $exception) {
                $error = $exception->errorInfo[1] ?? null;
            } finally {
                DB::statement('set session innodb_lock_wait_timeout = 50');
            }
            dcsWriterEnd($world, $writer, 'commit');
            dcsReaderWrite($world, $e0);

            expect($error)->toBe(1205, 'the reader\'s upsert waited for the writer\'s uncommitted row of the same key (this is why DB-F2 uses a writer that loads nothing)')
                ->and(dcsEntryExists($world, $e0))->toBeTrue('and the same write succeeds once the writer has committed');
        });
    })->with('release 0.9 profile');
});
