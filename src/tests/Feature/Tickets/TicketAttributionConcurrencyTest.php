<?php

/*
 * Release 1.0 (R1-2A): Ticket creation against a membership removal on a REAL second database connection (MariaDB, InnoDB, committed
 * data, no mocks). The rule "an actor may file for organization O only while a member of O" spans rows and connections: a transaction
 * that checked membership with an ordinary read could still INSERT after another connection removed the member (REPEATABLE READ shows
 * its snapshot), leaving a ticket attributed to an organization the requester had already left. ServiceRequestAccess therefore takes the
 * organization row and then the membership row with SHARED LOCKING reads (the order OrganizationMembership uses to remove one), which
 * read the latest committed rows and hold them until the Ticket commits. These tests run the real service and, at an exact moment of its
 * own queries, run the CONFLICTING operation on the second connection (same technique as OrganizationMembershipConcurrencyTest:
 * deterministic, no timing), then assert what happened.
 *
 *   T1. removal commits AFTER creation's snapshot began, BEFORE its locks     | refused: the locked read sees the removal; nothing written
 *   T2. removal attempted WHILE creation holds its locks                      | removal waits (lock wait), the ticket commits attributed
 *   T3. removal IN FLIGHT (uncommitted) when creation starts                  | creation waits (lock wait), writes nothing; after commit: refused
 *   T4. two creations by a multi-membership user, one holding its locks       | both proceed (shared locks), each keeps its own organization
 *   T5. the decision is refused outside a transaction
 *   T6. a deadlock victim starts over from nothing (no file, row or mail of the lost attempt survives): the exception shapes
 *   T7. two creations for the SAME organization, one holding its shared locks, proceed side by side
 *   T8. a REAL InnoDB deadlock inside the reference INSERT's savepoint, against a peer process: genuine 1213 is retried and succeeds
 *   T9. a lock wait TIMEOUT (1205) in the same place is NOT retried
 *
 * Neither order deadlocks (1213 never appears): both sides lock the organization row before the membership row. Everything is committed
 * (DB::commit()) and removed again by id in a finally block.
 */

use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use App\Services\OrganizationMembership;
use App\Services\ServiceRequestAccess;
use App\Services\TicketService;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedUsers;

require_once __DIR__.'/TicketTestHelpers.php';

beforeEach(function () {
    config(['database.connections.tk_race_b' => config('database.connections.'.config('database.default'))]);
});

afterEach(function () {
    DB::purge('tk_race_b');
});

/** Run $callback with the second connection as the default one, so the code under test uses it. */
function tkRaceOnSecond(callable $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('tk_race_b');

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

/**
 * Committed rows: an operator able to manage memberships, a requester holding tickets.create, and an organization the requester belongs to.
 *
 * @return array{actor: User, requester: User, org: Organization, users: list<int>, permissions: list<int>}
 */
function tkRaceFixture(): array
{
    DB::commit();
    Notification::fake();
    Storage::fake('local');

    $permissions = collect(['portal.operator', 'crm.manage', 'tickets.create'])->map(fn ($name) => Permission::create(['name' => $name, 'guard_name' => 'web']));
    $actor = User::factory()->create();
    $actor->givePermissionTo(['portal.operator', 'crm.manage']);
    $requester = User::factory()->create();
    $requester->givePermissionTo('tickets.create');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $org = Organization::factory()->create(['name' => 'Ticket Race', 'owner_id' => $actor->id]);
    tkRaceJoin($org, $requester);

    DB::connection('tk_race_b')->statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

    return ['actor' => $actor, 'requester' => $requester, 'org' => $org, 'users' => [$actor->id, $requester->id], 'permissions' => $permissions->pluck('id')->all()];
}

function tkRaceJoin(Organization $org, User $user): void
{
    DB::table('organization_members')->insert(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
}

/** @param  list<Organization>  $extraOrganizations */
function tkRaceCleanup(array $f, array $extraOrganizations = [], array $extraUsers = []): void
{
    $organizations = array_merge([$f['org']->id], array_map(fn ($o) => $o->id, $extraOrganizations));
    $users = array_merge($f['users'], $extraUsers);

    DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
    DB::table('tickets')->whereIn('organization_id', $organizations)->delete();
    DB::table('activity_log')->whereIn('causer_id', $users)->delete();
    DB::table('organization_members')->whereIn('organization_id', $organizations)->delete();
    DB::table('organizations')->whereIn('id', $organizations)->delete();
    DB::table('model_has_permissions')->whereIn('model_id', $users)->where('model_type', User::class)->delete();
    CommittedUsers::delete($users);
    Permission::whereIn('id', $f['permissions'])->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function tkRaceCode(Throwable $e): ?int
{
    return $e instanceof QueryException ? (int) ($e->errorInfo[1] ?? 0) : null;
}

/**
 * Run $operation on the default connection; the first time a query matching $when finishes on it, run $conflict once and record what it did.
 *
 * @param  callable(string): bool  $when
 */
function tkRaceDuring(callable $operation, callable $when, callable $conflict): mixed
{
    $outcome = null;
    $fired = false;

    DB::listen(function ($query) use (&$outcome, &$fired, $when, $conflict) {
        if ($fired || $query->connectionName === 'tk_race_b' || ! $when($query->sql)) {
            return;
        }

        $fired = true;

        try {
            $outcome = $conflict() ?? 'succeeded';
        } catch (Throwable $e) {
            $outcome = $e;
        }
    });

    try {
        $operation();
    } finally {
        // A listener cannot be removed; neutralise it for the rest of the test.
        $fired = true;
    }

    return $outcome;
}

function tkRaceForm(array $extra = []): array
{
    return ['title' => 'Race', 'description' => 'Race', 'category' => 'General', 'priority' => 'low', ...$extra];
}

it('T1. a removal that commits after the creation transaction began is seen: the locked read, not the snapshot, decides', function () {
    $f = tkRaceFixture();
    $removals = 0;

    try {
        $thrown = null;
        $outcome = tkRaceDuring(
            function () use ($f, &$thrown) {
                try {
                    app(TicketService::class)->create($f['requester'], tkRaceForm(), [ticketUpload('a.pdf')]);
                } catch (Throwable $e) {
                    $thrown = $e;
                }
            },
            // The creation transaction's first read: its snapshot starts here and it holds no lock yet.
            fn (string $sql) => str_contains($sql, 'from `users`') && str_contains($sql, 'disabled_at'),
            function () use ($f, &$removals) {
                tkRaceOnSecond(fn () => app(OrganizationMembership::class)->remove($f['org'], $f['requester'], $f['actor']));
                $removals++;
            }
        );

        expect($outcome)->toBe('succeeded', 'the removal ran to completion while creation held no lock')
            ->and($removals)->toBe(1)
            ->and($thrown)->toBeInstanceOf(ValidationException::class)
            ->and($thrown->errors())->toHaveKey('organization_id')
            ->and(DB::table('tickets')->count())->toBe(0)
            ->and(TicketAttachment::count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
        Notification::assertNothingSent();
        expect(DB::table('organization_members')->where('organization_id', $f['org']->id)->where('user_id', $f['requester']->id)->exists())->toBeFalse();
    } finally {
        tkRaceCleanup($f);
    }
});

it('T2. a removal attempted while creation holds its locks waits; the ticket commits attributed, and a later removal leaves it so', function () {
    $f = tkRaceFixture();

    try {
        $created = null;
        $outcome = tkRaceDuring(
            function () use ($f, &$created) {
                $created = app(TicketService::class)->create($f['requester'], tkRaceForm());
            },
            // Both shared locks are held once the membership row has been read.
            fn (string $sql) => str_contains($sql, 'from `organization_members`') && str_contains($sql, 'lock in share mode'),
            fn () => tkRaceOnSecond(fn () => app(OrganizationMembership::class)->remove($f['org'], $f['requester'], $f['actor']))
        );

        expect($outcome)->toBeInstanceOf(QueryException::class)
            ->and(tkRaceCode($outcome))->toBe(1205, 'the removal waited on the organization row and gave up; it did not slip in, and nothing deadlocked')
            ->and($created)->toBeInstanceOf(Ticket::class)
            ->and($created->fresh()->organization_id)->toBe($f['org']->id)
            ->and($created->fresh()->submitted_by_id)->toBe($f['requester']->id);

        // Now that the ticket has committed the removal goes through, and the ticket keeps its attribution.
        tkRaceOnSecond(fn () => app(OrganizationMembership::class)->remove($f['org'], $f['requester'], $f['actor']));
        expect(DB::table('organization_members')->where('user_id', $f['requester']->id)->exists())->toBeFalse()
            ->and(DB::table('tickets')->where('id', $created->id)->value('organization_id'))->toBe($f['org']->id);
    } finally {
        tkRaceCleanup($f);
    }
});

it('T3. a removal still in flight makes creation wait and write nothing; once it commits, creation is refused', function () {
    $f = tkRaceFixture();
    $second = DB::connection('tk_race_b');

    try {
        // The removal's own first two statements, left uncommitted: the organization row (exclusive) and the membership row.
        $second->beginTransaction();
        $second->table('organizations')->where('id', $f['org']->id)->lockForUpdate()->first();
        $second->table('organization_members')->where('organization_id', $f['org']->id)->where('user_id', $f['requester']->id)->delete();

        $thrown = null;
        try {
            app(TicketService::class)->create($f['requester'], tkRaceForm(), [ticketUpload('a.pdf')]);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf(QueryException::class)->and(tkRaceCode($thrown))->toBe(1205)
            ->and(DB::table('tickets')->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([]);

        $second->commit();

        expect(fn () => app(TicketService::class)->create($f['requester'], tkRaceForm(), [ticketUpload('a.pdf')]))->toThrow(ValidationException::class)
            ->and(DB::table('tickets')->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
        Notification::assertNothingSent();
    } finally {
        if ($second->transactionLevel() > 0) {
            $second->rollBack();
        }
        tkRaceCleanup($f);
    }
});

it('T4. two creations by a multi-membership user run side by side (shared locks) and each keeps the organization it named', function () {
    $f = tkRaceFixture();
    $other = Organization::factory()->create(['name' => 'Ticket Race Two', 'owner_id' => $f['actor']->id]);
    tkRaceJoin($other, $f['requester']);

    try {
        $second = null;
        tkRaceDuring(
            fn () => app(TicketService::class)->create($f['requester'], tkRaceForm(['title' => 'First']), [], $f['org']->id),
            fn (string $sql) => str_contains($sql, 'from `organization_members`') && str_contains($sql, 'lock in share mode'),
            function () use ($f, $other, &$second) {
                $second = tkRaceOnSecond(fn () => app(TicketService::class)->create($f['requester'], tkRaceForm(['title' => 'Second']), [], $other->id));
            }
        );

        expect($second)->toBeInstanceOf(Ticket::class)
            ->and(Ticket::where('title', 'First')->sole()->organization_id)->toBe($f['org']->id)
            ->and(Ticket::where('title', 'Second')->sole()->organization_id)->toBe($other->id);
    } finally {
        tkRaceCleanup($f, [$other]);
    }
});

it('T5. refuses to decide outside a transaction, where a lock would be released at once', function () {
    $f = tkRaceFixture();

    try {
        expect(DB::transactionLevel())->toBe(0)
            ->and(fn () => app(ServiceRequestAccess::class)->attributionFor($f['requester'], null))->toThrow(LogicException::class, 'inside the Ticket creation transaction');
    } finally {
        tkRaceCleanup($f);
    }
});

/** The exception Laravel 13 throws out of a nested transaction for a driver error: a PDOException subclass, code 0, the QueryException as previous. */
function tkWrapped(int $driverCode, string $message): DeadlockException
{
    $pdo = new PDOException("SQLSTATE[HY000]: General error: {$driverCode} {$message}");
    $pdo->errorInfo = [$driverCode === 1213 ? '40001' : 'HY000', $driverCode, $message];

    return new DeadlockException($message, 0, new QueryException('mysql', 'insert into `tickets` ...', [], $pdo));
}

it('T6. a creation lost to a genuine deadlock (1213, wrapped as Laravel wraps it) starts over; a lock wait timeout (1205) does not', function (int $code, string $message, int $expectedAttempts, bool $succeeds) {
    $f = tkRaceFixture();
    $attempts = 0;

    // The lost attempt's ticket row and file exist when the database rolls it back: fail the attachment row exactly once.
    TicketAttachment::creating(function () use (&$attempts, $code, $message) {
        if (++$attempts === 1) {
            throw tkWrapped($code, $message);
        }
    });

    try {
        $result = null;
        $thrown = null;

        try {
            $result = app(TicketService::class)->create($f['requester'], tkRaceForm(), [ticketUpload('a.pdf')]);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect($attempts)->toBe($expectedAttempts);

        if ($succeeds) {
            expect($thrown)->toBeNull()
                ->and(Ticket::count())->toBe(1)
                ->and($result->fresh()->organization_id)->toBe($f['org']->id)
                ->and(TicketAttachment::count())->toBe(1)
                ->and(Storage::disk('local')->allFiles())->toHaveCount(1, 'the lost attempt\'s file was deleted');
            Notification::assertSentToTimes($f['requester'], TicketCreatedNotification::class, 1);
        } else {
            expect($thrown)->toBeInstanceOf(DeadlockException::class)
                ->and(Ticket::count())->toBe(0)
                ->and(TicketAttachment::count())->toBe(0)
                ->and(Storage::disk('local')->allFiles())->toBe([]);
            Notification::assertNothingSent();
        }
    } finally {
        TicketAttachment::flushEventListeners();
        tkRaceCleanup($f);
    }
})->with([
    'deadlock 1213' => [1213, 'Deadlock found when trying to get lock; try restarting transaction', 2, true],
    'lock wait timeout 1205 (same wrapper, same family)' => [1205, 'Lock wait timeout exceeded; try restarting transaction', 1, false],
]);

it('T7. two creations for the SAME organization run side by side: the shared locks are compatible', function () {
    $f = tkRaceFixture();
    $second = User::factory()->create();
    $second->givePermissionTo('tickets.create');
    tkRaceJoin($f['org'], $second);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    try {
        $other = null;
        tkRaceDuring(
            fn () => app(TicketService::class)->create($f['requester'], tkRaceForm(['title' => 'First']), [], $f['org']->id),
            // The first creation now holds its shared locks on the organization and its own membership row, and has not yet inserted.
            fn (string $sql) => str_contains($sql, 'from `organization_members`') && str_contains($sql, 'lock in share mode'),
            function () use ($f, $second, &$other) {
                // Second connection has a one-second lock wait: a creation that had to WAIT for the first would fail with 1205 here.
                $other = tkRaceOnSecond(fn () => app(TicketService::class)->create($second, tkRaceForm(['title' => 'Second']), [], $f['org']->id));
            }
        );

        expect($other)->toBeInstanceOf(Ticket::class)
            ->and(Ticket::where('title', 'First')->sole()->organization_id)->toBe($f['org']->id)
            ->and(Ticket::where('title', 'Second')->sole()->organization_id)->toBe($f['org']->id)
            ->and(Ticket::where('title', 'Second')->sole()->submitted_by_id)->toBe($second->id)
            ->and(Ticket::count())->toBe(2);
    } finally {
        tkRaceCleanup($f, [], [$second->id]);
    }
});

/**
 * Start the peer process (see tests/Support/ticket_deadlock_peer.php) and wait until it holds the requester's users row.
 *
 * @return array{0: Process, 1: string} the process and the signal file whose appearance releases it (a file, because this process is about to
 *                                      block inside the database call under test and could not pump a pipe)
 */
function tkStartPeer(string $mode, User $requester, Organization $organization): array
{
    $connection = config('database.connections.'.config('database.default'));
    $signal = sys_get_temp_dir().'/ticket-deadlock-peer-'.bin2hex(random_bytes(6));
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/ticket_deadlock_peer.php'), $mode, (string) $requester->id, (string) $organization->id, $signal],
        base_path(),
        ['DB_HOST' => $connection['host'], 'DB_PORT' => $connection['port'], 'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password']],
    );
    $process->setTimeout(60);
    $process->start();

    $deadline = microtime(true) + 20;
    while (! str_contains($process->getOutput(), 'READY')) {
        if (! $process->isRunning() || microtime(true) > $deadline) {
            throw new RuntimeException('The deadlock peer did not become ready: '.$process->getOutput().$process->getErrorOutput());
        }
        usleep(20_000);
    }

    return [$process, $signal];
}

function tkInnoDbDeadlocks(): int
{
    return (int) DB::selectOne("show global status like 'Innodb_deadlocks'")->Value;
}

it('T8. a REAL InnoDB deadlock inside the reference INSERT\'s savepoint is retried from nothing and the creation succeeds', function () {
    $f = tkRaceFixture();
    // Detection of a deadlock is immediate and does not depend on this timeout; it only bounds the RETRY's wait for the peer to roll back and
    // release the organization row, which a slow host could stretch past the fixture's one second. (T9 needs the one second: it IS the timeout.)
    DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
    [$peer, $signal] = tkStartPeer('deadlock', $f['requester'], $f['org']);
    $before = tkInnoDbDeadlocks();
    $locks = 0;

    try {
        // Once the creation holds its shared lock on the organization row (first attempt only), let the peer ask for it exclusively. The
        // creation then waits on the users row the peer holds for the INSERT's foreign key: a cycle, and the peer is the heavier side.
        DB::listen(function ($query) use (&$locks, $signal) {
            if ($query->connectionName !== 'tk_race_b' && str_contains($query->sql, 'from `organizations`') && str_contains($query->sql, 'lock in share mode') && ++$locks === 1) {
                file_put_contents($signal, 'GO');
            }
        });

        $ticket = app(TicketService::class)->create($f['requester'], tkRaceForm(), [ticketUpload('a.pdf')]);
        $peer->wait();

        expect(tkInnoDbDeadlocks())->toBe($before + 1, 'the database really detected one deadlock')
            ->and($locks)->toBe(2, 'the creation ran twice: the victim, then the retry that decided attribution again')
            ->and($peer->getOutput())->toContain('ACQUIRED')->not->toContain('ERROR')
            ->and(Ticket::count())->toBe(1)
            ->and($ticket->fresh()->organization_id)->toBe($f['org']->id)
            ->and($ticket->fresh()->submitted_by_id)->toBe($f['requester']->id)
            ->and(TicketAttachment::count())->toBe(1)
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1, 'no file of the lost attempt survives');
        Notification::assertSentToTimes($f['requester'], TicketCreatedNotification::class, 1);
    } finally {
        @unlink($signal);
        $peer->stop(1);
        tkRaceCleanup($f);
    }
});

it('T9. a lock wait TIMEOUT (1205) at the same place is not retried: one attempt, nothing written', function () {
    $f = tkRaceFixture();
    [$peer, $signal] = tkStartPeer('hold', $f['requester'], $f['org']);
    $before = tkInnoDbDeadlocks();
    $locks = 0;

    try {
        DB::listen(function ($query) use (&$locks) {
            if ($query->connectionName !== 'tk_race_b' && str_contains($query->sql, 'from `organizations`') && str_contains($query->sql, 'lock in share mode')) {
                $locks++;
            }
        });

        $thrown = null;
        try {
            app(TicketService::class)->create($f['requester'], tkRaceForm(), [ticketUpload('a.pdf')]);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $chain = [];
        for ($cause = $thrown; $cause !== null; $cause = $cause->getPrevious()) {
            $chain[] = $cause instanceof PDOException ? (int) ($cause->errorInfo[1] ?? 0) : 0;
        }

        expect($thrown)->toBeInstanceOf(DeadlockException::class, 'Laravel wraps a nested timeout in the same class it uses for a deadlock')
            ->and($chain)->toContain(1205)->not->toContain(1213)
            ->and($locks)->toBe(1, 'a timeout is not a deadlock: no second attempt')
            ->and(tkInnoDbDeadlocks())->toBe($before)
            ->and(Ticket::count())->toBe(0)
            ->and(TicketAttachment::count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
        Notification::assertNothingSent();
    } finally {
        file_put_contents($signal, 'RELEASE');
        $peer->stop(1);
        @unlink($signal);
        tkRaceCleanup($f);
    }
});
