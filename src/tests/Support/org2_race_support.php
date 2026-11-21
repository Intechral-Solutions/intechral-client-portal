<?php

/*
 * Release 1.0 (ORG-2, CP6): the harness for the two-connection certification of the Organization Directory and the Project association writers
 * (OrganizationDirectoryConcurrencyTest, ProjectOrganizationConcurrencyTest). The METHOD is R1-3A's and ORG-1's (tests/Support/portal_state_race_support.php, which this
 * file builds on and leaves exactly as it was reviewed): two independent PHP processes are two real MariaDB connections; worker FIRST freezes after a named statement
 * (its locks held, its transaction open); the test starts worker SECOND, which announces just before its conflicting statement; the test establishes that it is
 * waiting (or, for a pair that must NOT block, that it finished while FIRST was still frozen), releases FIRST, and reads both COMMITTED outcomes.
 *
 * WHAT THIS ADDS to the ORG-1 harness, because CP6 is a certification and a pass must say more than "nothing failed":
 *
 *  - SESSIONS. Every worker reports the MariaDB connection id it ran on; a pair asserts the two are different from each other and from the test's own.
 *  - ENGINE COUNTERS. `Innodb_deadlocks`, `Innodb_row_lock_waits` and `Innodb_row_lock_current_waits` are read from the server (the app user has no PROCESS
 *    privilege, so INNODB_TRX / INNODB_LOCK_WAITS are not available; the status counters are, and need none). A pair asserts the deadlock counter did not move, that the
 *    waiter was counted as waiting while it was blocked, and that a pair which must not block did not wait.
 *  - ATTEMPTS. A worker reports how many times its outermost transaction began and how many were rolled back. An INTENTIONAL application restart (ProjectService's
 *    bounded restart) COMMITS an empty transaction and begins another: attempts 2, rollbacks 0. A deadlock victim that Laravel silently retries shows a ROLLBACK and a
 *    moved deadlock counter. The two are never confused, and a restart is never counted as proof of deadlock freedom.
 *  - THE PHYSICAL ORDER. {@see o2rHeldLocks()} asks the engine, from a third connection with a one-second lock wait, which rows a blocked worker already holds.
 *    "ORDER BY id" is a request to the optimizer, not a statement about the order InnoDB takes record locks in; this is the evidence.
 *
 * Loaded with require_once; the file name does not end in "Test" so Pest does not run it. Function names carry the `o2r` prefix (Pest loads every test file into one process).
 */

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Support\Facades\DB;
use Pest\TestSuite;
use Symfony\Component\Process\Process;

require_once __DIR__.'/portal_state_race_support.php';

// The statements to freeze after / announce before. Regexes over the SQL text the worker saw.
const O2R_USERS_SHARE_SET = '/from `users` where `id` in \(.*\) order by `id` asc lock in share mode/';
const O2R_ORG_SHARE = '/from `organizations` where `id` = \? limit 1 lock in share mode/';
const O2R_ORGS_SHARE_ASC = '/from `organizations` where `id` in \(.*\) order by `id` asc lock in share mode/';
const O2R_PROJECT_EXCL = '/from `projects` where `id` = \? limit 1 for update/';
const O2R_LINKS_SHARE = '/from `project_organization` where `project_id` = \? lock in share mode/';
const O2R_MEMBERSHIP_SHARE = '/from `organization_members` where `user_id` = \? and `organization_id` in \(.*\) order by `organization_id` asc lock in share mode/';
const O2R_PEOPLE_EXCL = '/from `crm_contacts` where `organization_id` = \? order by `id` asc for update/';
const O2R_PERSON_EXCL = '/from `crm_contacts` where `id` = \? limit 1 for update/';
const O2R_OPERATOR_READ = '/^select exists\(select \* from `users` where `users`\.`id` = \? and \(exists/';
const O2R_AUTHORITY_READ = '/from `users` where `users`\.`id` in \(\?\) and `users`\.`disabled_at` is null/';
const O2R_SLUG_PROBE = '/from `organizations` where `slug` = \?/';
const O2R_ORG_INSERT = '/^insert into `organizations`/';

/** The engine's own counters. @return array{deadlocks: int, lockWaits: int, currentWaits: int} */
function o2rCounters(): array
{
    $rows = collect(DB::select("show global status where Variable_name in ('Innodb_deadlocks', 'Innodb_row_lock_waits', 'Innodb_row_lock_current_waits')"))
        ->mapWithKeys(fn ($row) => [$row->Variable_name => (int) $row->Value]);

    return [
        'deadlocks' => $rows['Innodb_deadlocks'],
        'lockWaits' => $rows['Innodb_row_lock_waits'],
        'currentWaits' => $rows['Innodb_row_lock_current_waits'],
    ];
}

/**
 * Assert one worker's outcome is not hiding a deadlock or a timeout behind a framework retry. `$attempts` is how many times its outermost transaction may begin:
 * 1 normally, more only for an INTENTIONAL application restart (which commits and begins again, so it adds an attempt and NO rollback).
 *
 * @param  array<string, mixed>  $result
 */
function o2rAssertClean(string $which, array $result, int $attempts = 1): void
{
    $outcome = (string) ($result['outcome'] ?? '');

    expect($result['transactions'] ?? 0)->toBe($attempts, "the {$which} worker's transaction began ".($result['transactions'] ?? '?')." times, expected {$attempts}: it was retried (a deadlock or lock-wait victim, or an unintended restart)");

    // A controlled refusal is an exception inside the transaction, so it rolls back (once); an outcome that succeeded rolled nothing back.
    expect($result['rollbacks'] ?? 0)->toBe($outcome === 'ok' ? 0 : 1, "the {$which} worker ({$outcome}) rolled back an unexpected number of transactions");

    expect($outcome)->not->toContain('1213', "the {$which} worker was a deadlock victim")
        ->not->toContain('1205', "the {$which} worker hit a lock wait timeout")
        ->not->toContain('1452', "the {$which} worker hit a foreign key error that a controlled refusal should have preceded")
        ->not->toContain('1451', "the {$which} worker hit a foreign key error that a controlled refusal should have preceded")
        ->not->toContain('Deadlock', "the {$which} worker was a deadlock victim")
        ->not->toContain('error:', "the {$which} worker failed unexpectedly: ".($result['detail'] ?? ''))
        ->not->toContain('worker failed');
}

/**
 * Run two operations as two real connections. FIRST freezes after `$freezeAfter` (locks held, transaction open); SECOND is started and announces before `$blockAt`.
 *
 * Options: `blocked` (default true): SECOND must be waiting at its conflicting statement; false: SECOND must FINISH while FIRST is still frozen (a pair that must
 * not serialize). `firstAttempts`, `secondAttempts`: the attempts each outermost transaction may begin (see {@see o2rAssertClean()}). `afterFreeze`: a callable run
 * once FIRST is frozen and before SECOND starts (a committed change of the test's own). `whileBlocked`: a callable run while SECOND is observed waiting, before FIRST
 * is released (the physical-order probe). `allowDeadlock`: skip the deadlock-counter assertion (a mutation probe that expects one).
 *
 * @param  array<string, mixed>  $first
 * @param  array<string, mixed>  $second
 * @param  array<string, mixed>  $o
 * @return array{first: array<string, mixed>, second: array<string, mixed>, blocked: bool, sessions: array{0: int, 1: int}, counters: array<string, int>, probe: mixed}
 */
function o2rPair(array $first, string $freezeAfter, array $second, string $blockAt, array $o = []): array
{
    $expectBlocked = $o['blocked'] ?? true;
    $dir = sys_get_temp_dir().'/o2r-'.bin2hex(random_bytes(6));
    mkdir($dir);
    $before = o2rCounters();
    $probe = null;
    $currentWaits = 0;

    try {
        $a = psrStart($first + ['hold' => ['pattern' => $freezeAfter, 'dir' => $dir, 'tag' => 'first']]);

        $deadline = microtime(true) + 40;
        while (! is_file($dir.'/first.held') && microtime(true) < $deadline && $a->isRunning()) {
            usleep(10_000);
        }
        if (! is_file($dir.'/first.held')) {
            $a->stop(2);
            throw new RuntimeException('the first worker never reached its freeze point: '.$a->getOutput().$a->getErrorOutput());
        }

        if (isset($o['afterFreeze'])) {
            $o['afterFreeze']();
        }

        $b = psrStart($second + ['signal' => ['pattern' => $blockAt, 'dir' => $dir, 'tag' => 'second']]);

        $startedAt = microtime(true);
        while (! is_file($dir.'/second.requesting') && $b->isRunning() && microtime(true) - $startedAt < 6.0) {
            usleep(10_000);
        }

        if ($expectBlocked) {
            usleep(1_200_000);
            $blocked = $b->isRunning();
            $currentWaits = o2rCounters()['currentWaits'];

            if (isset($o['whileBlocked']) && $blocked) {
                $probe = $o['whileBlocked']();
            }
        } else {
            // It must finish on its own while FIRST still holds everything it holds.
            $limit = microtime(true) + 25;
            while ($b->isRunning() && microtime(true) < $limit) {
                usleep(20_000);
            }
            $blocked = $b->isRunning();
        }

        $releasedAt = microtime(true);
        touch($dir.'/first.release');

        $firstResult = psrResult($a);
        $secondResult = psrResult($b);
    } finally {
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }

    $after = o2rCounters();
    $counters = ['deadlocks' => $after['deadlocks'] - $before['deadlocks'], 'lockWaits' => $after['lockWaits'] - $before['lockWaits'], 'currentWaits' => $currentWaits];

    if ($expectBlocked) {
        expect($blocked)->toBeTrue('the second transaction was not waiting on the first at its conflicting statement');
        expect($secondResult['finishedAt'])->toBeGreaterThan($releasedAt, 'the second finished before the first was released');
        expect($currentWaits)->toBeGreaterThanOrEqual(1, 'the server did not count a row lock wait while the second transaction was blocked');
        expect($counters['lockWaits'])->toBeGreaterThanOrEqual(1, 'the server counted no row lock wait for the pair');
    } else {
        expect($blocked)->toBeFalse('the second transaction was waiting on the first, but this pair must not serialize');
        expect($secondResult['finishedAt'])->toBeLessThan($releasedAt, 'the second did not finish before the first was released');
    }

    expect($firstResult['connectionId'] ?? 0)->toBeGreaterThan(0)
        ->and($secondResult['connectionId'] ?? 0)->toBeGreaterThan(0)
        ->and($firstResult['connectionId'])->not->toBe($secondResult['connectionId'], 'two workers shared one connection')
        ->and($firstResult['connectionId'])->not->toBe((int) DB::selectOne('select connection_id() as id')->id)
        ->and($secondResult['connectionId'])->not->toBe((int) DB::selectOne('select connection_id() as id')->id);

    if (! ($o['allowDeadlock'] ?? false)) {
        expect($counters['deadlocks'])->toBe(0, 'the server recorded a deadlock during the pair');
    }

    o2rAssertClean('first', $firstResult, $o['firstAttempts'] ?? 1);
    o2rAssertClean('second', $secondResult, $o['secondAttempts'] ?? 1);

    o2rRecordEvidence($expectBlocked, $firstResult, $secondResult, $counters);

    return ['first' => $firstResult, 'second' => $secondResult, 'blocked' => $blocked, 'sessions' => [$firstResult['connectionId'], $secondResult['connectionId']], 'counters' => $counters, 'probe' => $probe];
}

/**
 * When O2R_EVIDENCE names a file, append one JSON line per pair: the test, the two sessions, what each ended as, the attempts, the rollbacks, the lock waits the server
 * counted and the deadlock counter's movement. It is how the CP6 handback quotes the evidence; nothing reads it back and nothing is written when the variable is unset.
 *
 * @param  array<string, mixed>  $first
 * @param  array<string, mixed>  $second
 * @param  array<string, int>  $counters
 */
function o2rRecordEvidence(bool $blocked, array $first, array $second, array $counters): void
{
    $file = getenv('O2R_EVIDENCE');

    if (! is_string($file) || $file === '') {
        return;
    }

    $test = class_exists(TestSuite::class) && isset(TestSuite::getInstance()->test) ? TestSuite::getInstance()->test->name() : '?';

    file_put_contents($file, json_encode([
        'test' => $test,
        'second_waited' => $blocked,
        'sessions' => [$first['connectionId'], $second['connectionId']],
        'first' => [$first['outcome'], 'attempts' => $first['transactions'], 'rollbacks' => $first['rollbacks']],
        'second' => [$second['outcome'], 'attempts' => $second['transactions'], 'rollbacks' => $second['rollbacks']],
        'lock_waits' => $counters['lockWaits'],
        'current_waits_observed' => $counters['currentWaits'],
        'deadlocks' => $counters['deadlocks'],
    ])."\n", FILE_APPEND);
}

/**
 * Which of the given row ids (of `$table`, default `organizations`) can a THIRD connection NOT lock exclusively within one second: the rows another transaction already
 * holds. This is how a test establishes, from the engine, the physical order a multi-row `ORDER BY id ... lock in share mode` took its locks in: while the statement is
 * blocked on row N, the rows before N (in lock order) are held and the rows after N are not.
 *
 * @param  list<int>  $ids
 * @return list<int> the ids that were held
 */
function o2rHeldLocks(array $ids, string $table = 'organizations'): array
{
    config(['database.connections.o2r_probe' => config('database.connections.'.config('database.default'))]);
    $probe = DB::connection('o2r_probe');
    $held = [];

    try {
        $probe->statement('set session innodb_lock_wait_timeout = 1');

        foreach ($ids as $id) {
            $probe->beginTransaction();

            try {
                $probe->table($table)->where('id', $id)->lockForUpdate()->value('id');
            } catch (Throwable $e) {
                $held[] = $id;   // 1205: another transaction holds it
            } finally {
                $probe->rollBack();
            }
        }
    } finally {
        DB::purge('o2r_probe');
    }

    return $held;
}

/**
 * Run ONE worker that freezes at EVERY statement matching `$pattern` (its k-th match writes `first.held.k` and waits for `first.release.k`), calling `$atEach(k)` while it
 * is frozen at the k-th and then releasing it. For the bounded restart loop: the test makes its own committed change at each attempt, so the worker restarts (or not) by
 * design and not by luck. Returns the worker's result with `freezes`, the number of times it was frozen, and the deadlock counter's movement.
 *
 * @param  array<string, mixed>  $operation
 * @param  callable(int): void  $atEach
 * @return array<string, mixed>
 */
function o2rFreezeEvery(array $operation, string $pattern, callable $atEach): array
{
    $dir = sys_get_temp_dir().'/o2r-'.bin2hex(random_bytes(6));
    mkdir($dir);
    $before = o2rCounters();
    $freezes = 0;

    try {
        $worker = psrStart($operation + ['hold' => ['pattern' => $pattern, 'dir' => $dir, 'tag' => 'first', 'every' => true]]);

        for ($k = 1; $k <= 20; $k++) {
            $limit = microtime(true) + 40;
            while (! is_file($dir.'/first.held.'.$k) && $worker->isRunning() && microtime(true) < $limit) {
                usleep(10_000);
            }

            if (! is_file($dir.'/first.held.'.$k)) {
                break;   // the worker finished: no k-th statement
            }

            $freezes = $k;
            $atEach($k);
            touch($dir.'/first.release.'.$k);
        }

        $result = psrResult($worker);
    } finally {
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }

    $result['freezes'] = $freezes;
    $result['deadlocks'] = o2rCounters()['deadlocks'] - $before['deadlocks'];

    return $result;
}

/**
 * Hold the given Organization rows exclusively on a test-owned connection, to be committed by the returned closure. Used to line two workers up behind one blocker so
 * they are granted their FIRST lock in the same instant: the only deterministic way to reach a mid-statement interleaving of two multi-row lock statements.
 *
 * @param  list<int>  $ids
 * @return Closure(): void the release (a commit)
 */
function o2rBlock(array $ids): Closure
{
    config(['database.connections.o2r_blocker' => config('database.connections.'.config('database.default'))]);
    $blocker = DB::connection('o2r_blocker');
    $blocker->beginTransaction();
    $blocker->table('organizations')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');

    return function () use ($blocker): void {
        $blocker->commit();
        DB::purge('o2r_blocker');
    };
}

/** Wait (bounded) until the server counts at least `$n` row lock waits in progress. */
function o2rWaitForWaits(int $n, float $seconds = 8.0): bool
{
    $limit = microtime(true) + $seconds;

    while (microtime(true) < $limit) {
        if (o2rCounters()['currentWaits'] >= $n) {
            return true;
        }
        usleep(50_000);
    }

    return false;
}

/**
 * What the CP6 tests add to psrFixture(): high-water marks of every table they write, so psrTeardown's accounts and Organizations are removed with everything
 * the workers created around them, and a helper that makes the Directory-only Organizations, the Projects and the Person a race needs.
 *
 * @return array<string, mixed>
 */
function o2rFixture(): array
{
    $f = psrFixture();
    $f['mark'] = [
        'organizations' => (int) DB::table('organizations')->max('id'),
        'projects' => (int) DB::table('projects')->max('id'),
        'crm_contacts' => (int) DB::table('crm_contacts')->max('id'),
    ];

    return $f;
}

/** A committed Directory-only Organization. */
function o2rDirectoryOrg(array $f, string $name, ?User $by = null): Organization
{
    return Organization::factory()->directoryOnly()->create(['name' => $name, 'created_by' => ($by ?? $f['actor'])->id]);
}

/** A committed Project created through the service (its columns and its manager are the real ones), linked to the given Organizations. @param  list<int>  $organizations */
function o2rProject(array $f, array $organizations = [], ?User $creator = null, string $name = 'Race Project'): Project
{
    return app(ProjectService::class)->create($creator ?? $f['actor'], ['name' => $name, 'companies' => $organizations]);
}

function o2rLinks(Project|int $project): array
{
    return DB::table('project_organization')->where('project_id', $project instanceof Project ? $project->id : $project)->orderBy('organization_id')->pluck('organization_id')->map(fn ($id) => (int) $id)->all();
}

function o2rTeardown(array $f): void
{
    DB::table('project_organization')->delete();
    DB::table('projects')->where('id', '>', $f['mark']['projects'])->delete();       // columns, memberships and links cascade
    DB::table('crm_contacts')->where('id', '>', $f['mark']['crm_contacts'])->delete();
    DB::table('activity_log')->delete();

    $created = DB::table('organizations')->where('id', '>', $f['mark']['organizations'])->pluck('id')->all();
    DB::table('organizations')->whereIn('id', $created)->update(['managed_by_organization_id' => null]);
    DB::table('organization_members')->whereIn('organization_id', $created)->delete();
    $f['orgIds'] = array_values(array_unique([...$f['orgIds'], ...$created]));

    psrTeardown($f);
}

/** The lock statements of a worker's transaction in order, as {@see psrLocks()}, restricted to the tables named. @return list<string> */
function o2rLockOrder(array $result, array $tables = ['users', 'organizations', 'projects']): array
{
    return array_values(array_filter(psrLocks($result), fn (string $lock) => in_array(substr($lock, 2), $tables, true) || str_starts_with($lock, 'W ')));
}
