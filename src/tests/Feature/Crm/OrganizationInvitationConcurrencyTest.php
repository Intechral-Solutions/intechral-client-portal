<?php

/*
 * Release 1.0 (R1-3A): the invitation races, on REAL MariaDB with two independent PHP processes, two real connections. Design record,
 * sections 6.2.3 and 9. A single connection cannot show lock order or who blocks whom, so nothing here is inferred from reading code or from
 * the ordinary tests.
 *
 * HOW A RACE IS MADE DETERMINISTIC. Worker FIRST runs one operation and FREEZES after a named statement has executed (the lock that statement
 * took is held; the transaction is open). The test waits for that, then starts worker SECOND. SECOND announces, just before it sends its
 * conflicting statement, that it is about to; the test gives it time, then checks it is STILL RUNNING (blocked on the lock, with a 15 second
 * lock-wait ceiling so a genuine hang becomes an error rather than a pass), then releases FIRST. FIRST finishes and commits; SECOND then
 * proceeds on the committed state. Every pair runs in BOTH orders: the operation that was frozen is swapped.
 *
 * WHAT IS ASSERTED. (1) the second worker really waited (it finished only after the release); (2) NEITHER worker ended in a deadlock or a lock
 * wait timeout (any such error fails the test); (3) the final committed state is exactly the one the design predicts for that order; (4) for
 * acceptance, the statements the worker actually ran: the issuer row is share-locked FIRST, then the organization, then the invitation, and no
 * statement touched the permission cache tables inside the transaction.
 *
 * THE MUTATION PROBES. Three of these scenarios are the named tests for the first-statement shared lock on the user row (acceptance, issue,
 * revoke): each freezes the first transaction AFTER it has locked the invitation and then suspends the user whose row it must be holding. With
 * the lock, the suspension waits at the user row. Without it, the suspension takes the user row exclusively, then waits for the invitation row,
 * and the first transaction's foreign-key check on that user row closes a cycle: InnoDB aborts one of them and this test fails on assertion (2).
 * (Run by hand during R1-3A, see the handoff.)
 *
 * Like the other concurrency tests this ends RefreshDatabase's transaction so worker processes can see its rows, builds a committed
 * synchronized installation, and removes everything it created in a finally block. It refuses to run if the testing database already holds
 * committed roles or permissions.
 */

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\AccountLifecycleService;
use App\Services\BuiltInRoleSynchronizer;
use App\Services\OrganizationAuthority;
use App\Services\OrganizationInvitations;
use App\Support\Permissions\PermissionCacheEpoch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Support/committed_installation.php';

// The statements each side freezes after (as FIRST) or announces before (as SECOND). Regexes over the SQL text the worker saw.
const OIC_USER_SHARE = '/from `users` where `id` = \? limit 1 lock in share mode/';
const OIC_USER_EXCL = '/from `users` where `users`\.`id` = \? limit 1 for update/';
const OIC_ORG_EXCL = '/from `organizations` where `id` = \? limit 1 for update/';
const OIC_INV_BY_ID = '/from `organization_invitations` where `id` = \? limit 1 for update/';
const OIC_INV_BY_ID_ORG = '/from `organization_invitations` where `id` = \? and `organization_id` = \? limit 1 for update/';
const OIC_INV_SLOT = '/from `organization_invitations` where `organization_id` = \? and `live_email` = \? limit 1 for update/';
const OIC_INV_SWEEP = '/from `organization_invitations` where `id` in \(.*\) order by `id` asc for update/';
const OIC_USER_INSERT = '/^insert into `users`/';
const OIC_INV_UPDATE = '/^update `organization_invitations` set `revoked_at`/';
const OIC_ORG_SHARE = '/from `organizations` where `id` = \? limit 1 lock in share mode/';
const OIC_MEMBER_INSERT = '/^insert into `organization_members`/';
const OIC_MEMBER_UPDATE = '/^update `organization_members` set `admin_approved_at`/';
const OIC_TICKET_INSERT = '/^insert into `tickets`/';
const OIC_ROLE_INSERT = '/^insert into `model_has_roles`/';
const OIC_ROLE_DELETE = '/^delete from `model_has_roles`/';
// The permission cache (database store): a cache PUT (not the epoch's upsert), and the epoch's own upsert / ignore-insert.
// Both are upserts into `cache`; the update clause tells them apart (the copy refreshes its expiration first, the epoch its value first).
const OIC_CACHE_PUT = '/^insert into `cache` .* on duplicate key update `expiration` = /';
const OIC_EPOCH_ADVANCE = '/^insert into `cache` .* on duplicate key update `value` = /';
const OIC_EPOCH_CREATE = '/^insert ignore into `cache`/';

/** @return array<string, string> */
function oicWorkerEnv(array $extra = []): array
{
    $connection = config('database.connections.'.config('database.default'));

    // array_merge: a caller's override (CACHE_STORE=database) must WIN over the default. (`defaults + $extra` kept the default and silently
    // ignored the override, so an earlier version of the database-cache-store checks never ran against the database store.)
    return array_merge([
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => $connection['host'],
        'DB_PORT' => $connection['port'],
        'DB_DATABASE' => $connection['database'],
        'DB_USERNAME' => $connection['username'],
        'DB_PASSWORD' => $connection['password'],
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        // Suspension signs the user out everywhere, which refuses any other session driver.
        'SESSION_DRIVER' => 'database',
        // Real jobs rows, so a worker's mail is a row and never an SMTP attempt.
        'QUEUE_CONNECTION' => 'database',
        'MAIL_MAILER' => 'array',
        'BCRYPT_ROUNDS' => '4',
    ], $extra);
}

function oicStart(array $operation, array $env = []): Process
{
    $process = new Process([PHP_BINARY, base_path('tests/Support/organization_invitation_worker.php'), json_encode($operation)], base_path(), oicWorkerEnv($env));
    $process->setTimeout(90);
    $process->start();

    return $process;
}

/** @return array<string, mixed> */
function oicResult(Process $process): array
{
    $process->wait();
    $decoded = json_decode(trim($process->getOutput()), true);

    return is_array($decoded) ? $decoded : ['outcome' => 'worker failed: '.$process->getErrorOutput().$process->getOutput(), 'statements' => []];
}

/**
 * Laravel RETRIES a transaction that was a deadlock victim, so a correct final outcome can hide one. Every operation here is exactly one
 * top-level transaction: more than one beginning means it was aborted and re-run, and a rolled-back transaction behind an `ok` outcome means
 * the same. A deadlock or a lock wait timeout in the outcome is a failure whatever else happened.
 *
 * @param  array<string, mixed>  $result
 */
function oicAssertNoVictim(string $which, array $result): void
{
    expect($result['transactions'] ?? 0)->toBeLessThanOrEqual(1, "the {$which} worker's transaction began ".($result['transactions'] ?? '?').' times: it was a deadlock or lock-wait victim and was silently retried');

    if (($result['outcome'] ?? '') === 'ok') {
        expect($result['rollbacks'] ?? 0)->toBe(0, "the {$which} worker reported ok but rolled a transaction back");
    }

    expect($result['outcome'])->not->toContain('1213', "the {$which} worker was a deadlock victim")
        ->not->toContain('1205', "the {$which} worker hit a lock wait timeout")
        ->not->toContain('Deadlock', "the {$which} worker was a deadlock victim")
        ->not->toContain('error:', "the {$which} worker failed unexpectedly: ".($result['detail'] ?? ''))
        ->not->toContain('worker failed');
}

/**
 * Run two operations as two real connections: $first frozen after $freezeAfter, $second announcing before $blockAt. Returns both results and
 * whether the second was observed blocked.
 *
 * @param  array<string, mixed>  $first
 * @param  array<string, mixed>  $second
 * @return array{first: array<string, mixed>, second: array<string, mixed>, blocked: bool, releasedAt: float}
 */
function oicInterleave(array $first, string $freezeAfter, array $second, string $blockAt, bool $expectBlocked = true, array $env = []): array
{
    $dir = sys_get_temp_dir().'/oinv-'.bin2hex(random_bytes(6));
    mkdir($dir);

    try {
        $a = oicStart($first + ['hold' => ['pattern' => $freezeAfter, 'dir' => $dir, 'tag' => 'first']], $env);

        $deadline = microtime(true) + 40;
        while (! is_file($dir.'/first.held') && microtime(true) < $deadline && $a->isRunning()) {
            usleep(10_000);
        }
        if (! is_file($dir.'/first.held')) {
            $a->stop(2);
            throw new RuntimeException('the first worker never reached its freeze point: '.$a->getOutput().$a->getErrorOutput());
        }

        $b = oicStart($second + ['signal' => ['pattern' => $blockAt, 'dir' => $dir, 'tag' => 'second']], $env);

        // It announces the statement it is about to send. If it is blocked on an EARLIER statement it never announces, so also stop waiting once
        // it has been running for longer than a worker needs to reach any of its statements: that is itself the evidence it is blocked.
        $startedAt = microtime(true);
        while (! is_file($dir.'/second.requesting') && $b->isRunning() && microtime(true) - $startedAt < 6.0) {
            usleep(10_000);
        }

        // Give the server time to either grant or hold the statement.
        usleep(1_200_000);
        $blocked = $b->isRunning();
        $announced = is_file($dir.'/second.requesting');

        $releasedAt = microtime(true);
        touch($dir.'/first.release');

        $firstResult = oicResult($a);
        $secondResult = oicResult($b);
    } finally {
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }

    if ($expectBlocked) {
        expect($blocked)->toBeTrue('the second transaction was not waiting on the first at its conflicting statement');
        expect($secondResult['finishedAt'])->toBeGreaterThan($releasedAt, 'the second finished before the first was released');
    }

    foreach (['first' => $firstResult, 'second' => $secondResult] as $which => $result) {
        oicAssertNoVictim($which, $result);
    }

    return ['first' => $firstResult, 'second' => $secondResult, 'blocked' => $blocked, 'announced' => $announced, 'releasedAt' => $releasedAt];
}

/**
 * A committed synchronized installation, a few accounts and one organization. Everything is removed by the returned closure.
 *
 * @return array<string, mixed>
 */
function oicFixture(): array
{
    $cleanup = committedSynchronizedInstallation();

    $make = function (string $name): User {
        $user = User::factory()->create(['email' => $name.'@oinv.test', 'name' => ucfirst($name), 'password' => 'x']);

        return $user;
    };

    $admin = $make('admin');
    $admin->assignRole('operator'); // holds users.view and users.manage: the account administrator and the actor of suspensions
    $issuer = $make('issuer');
    $issuer->givePermissionTo(['portal.operator', 'crm.manage']);
    $other = $make('other');
    $other->givePermissionTo(['portal.operator', 'crm.manage']);

    $role = Role::create(['name' => 'oinv-directory', 'guard_name' => 'web']);
    $role->givePermissionTo(['portal.operator', 'crm.manage']);
    $r1 = $make('r1');
    $r1->assignRole($role);
    $r2 = $make('r2');
    $r2->assignRole($role);

    $org = Organization::factory()->create(['name' => 'Race Org', 'owner_id' => $admin->id]);
    $org2 = Organization::factory()->create(['name' => 'Race Org Two', 'owner_id' => $admin->id]);

    // A customer (may raise Tickets) and an organization administrator candidate, both members of the organization: the targets of the
    // suspension races against membership and Ticket work.
    $member = $make('member');
    $member->assignRole('user');
    $madmin = $make('madmin');
    $madmin->assignRole('user');
    DB::table('organization_members')->insert([
        ['organization_id' => $org->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()],
        ['organization_id' => $org->id, 'user_id' => $madmin->id, 'role' => 'admin', 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Created LAST, so it holds the highest user id in model_has_roles: the row after which an acceptance inserts the new account's role.
    $top = $make('top');
    $top->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return compact('cleanup', 'admin', 'issuer', 'other', 'role', 'r1', 'r2', 'member', 'madmin', 'top', 'org', 'org2') + ['orgIds' => [$org->id, $org2->id]];
}

function oicTeardown(array $f): void
{
    DB::table('organization_invitations')->delete();
    DB::table('ticket_status_histories')->delete();
    DB::table('ticket_replies')->delete();
    DB::table('tickets')->delete();
    DB::table('organization_members')->whereIn('organization_id', $f['orgIds'])->delete();
    DB::table('activity_log')->delete();
    DB::table('jobs')->delete();
    DB::table('sessions')->delete();
    DB::table('organizations')->whereIn('id', $f['orgIds'])->delete();

    $ids = DB::table('users')->where('email', 'like', '%@oinv.test')->pluck('id')->all();
    DB::table('model_has_roles')->whereIn('model_id', $ids)->delete();
    DB::table('model_has_permissions')->whereIn('model_id', $ids)->delete();
    $f['cleanup']($ids);
}

/** Write an open invitation (committed) and return [id, token]. @return array{0: int, 1: string} */
function oicInvite(Organization $org, User $issuer, string $email, array $state = []): array
{
    $token = Str::random(64);
    $row = OrganizationInvitation::factory()->withToken($token)->forAddress($email)->state(['organization_id' => $org->id, 'invited_by' => $issuer->id] + $state)->create();

    return [$row->id, $token];
}

function oicRow(int $id): ?object
{
    return DB::table('organization_invitations')->find($id);
}

function oicMembers(string $email): int
{
    return DB::table('organization_members')->join('users', 'users.id', '=', 'organization_members.user_id')->where('users.email', $email)->count();
}

function oicUser(string $email): ?object
{
    return DB::table('users')->where('email', $email)->first();
}

/**
 * The locking statements a worker ran inside its transaction, in order, as 'S users' / 'X organizations' / 'X organization_invitations', with
 * every write marked 'W <table>' in its place, so a lock taken AFTER a write shows up as one.
 *
 * @return list<string>
 */
function oicLocks(array $result): array
{
    return collect($result['statements'])->filter(fn ($s) => $s['transactional'])->map(function ($s) {
        $table = preg_match('/(?:from|into|update)\s+`(\w+)`/i', $s['sql'], $m) === 1 ? $m[1] : '?';

        return match (true) {
            str_contains($s['sql'], 'lock in share mode') => 'S '.$table,
            str_contains($s['sql'], 'for update') => 'X '.$table,
            preg_match('/^\s*(insert|update|delete)\b/i', $s['sql']) === 1 => 'W '.$table,
            default => null,
        };
    })->filter()->values()->all();
}

// ── The matrix ───────────────────────────────────────────────────────────────

/**
 * Each scenario: the two operations, the statement each is frozen after when it runs FIRST, the statement each announces before when it runs
 * SECOND, whether the second must be blocked, and the expected committed outcome per order.
 *
 * @return array<string, array<string, mixed>>
 */
function oicScenarios(): array
{
    return [
        // Acceptance vs suspension of the issuer. Frozen after the invitation lock: this is also the mutation test for acceptance's first lock.
        'suspension vs acceptance' => [
            'x' => fn ($f, $c) => ['op' => 'accept', 'token' => $c['token'], 'name' => 'Newcomer', 'password' => 'a-Strong-Passw0rd!'],
            'y' => fn ($f, $c) => ['op' => 'suspend', 'target' => $f['issuer']->id, 'actor' => $f['admin']->id],
            'setup' => fn ($f) => ['invitation' => ($i = oicInvite($f['org'], $f['issuer'], 'invitee@oinv.test'))[0], 'token' => $i[1]],
            'x_freeze' => OIC_INV_BY_ID, 'y_freeze' => OIC_USER_EXCL,
            'x_block' => OIC_USER_SHARE, 'y_block' => OIC_USER_EXCL,
            'expect' => function ($f, $c, $order, $r) {
                $row = oicRow($c['invitation']);
                expect(oicUser('issuer@oinv.test')->disabled_at)->not->toBeNull();
                if ($order === 'xy') {
                    // The acceptance committed first; the suspension then finds an accepted row and leaves it.
                    expect($r['x']['outcome'])->toBe('ok')->and($row->accepted_at)->not->toBeNull()->and($row->revoked_at)->toBeNull()
                        ->and(oicMembers('invitee@oinv.test'))->toBe(1)->and(oicUser('invitee@oinv.test'))->not->toBeNull();
                } else {
                    // The suspension committed first: the invitation is revoked, and the acceptance is refused and writes nothing.
                    expect($r['y']['outcome'])->toBe('ok')->and($r['x']['outcome'])->toBe('refused:unavailable')->and($r['x']['detail'])->toBe('revoked')
                        ->and($row->revoked_reason)->toBe('issuer_suspended')->and($row->accepted_at)->toBeNull()
                        ->and(oicMembers('invitee@oinv.test'))->toBe(0)->and(oicUser('invitee@oinv.test'))->toBeNull();
                }
            },
        ],

        'role change vs acceptance' => [
            'x' => fn ($f, $c) => ['op' => 'accept', 'token' => $c['token'], 'name' => 'Newcomer', 'password' => 'a-Strong-Passw0rd!'],
            'y' => fn ($f, $c) => ['op' => 'sync_user_roles', 'target' => $f['r1']->id, 'roles' => [], 'actor' => $f['admin']->id],
            'setup' => fn ($f) => ['invitation' => ($i = oicInvite($f['org'], $f['r1'], 'invitee@oinv.test'))[0], 'token' => $i[1]],
            'x_freeze' => OIC_INV_BY_ID, 'y_freeze' => OIC_INV_SWEEP,
            'x_block' => OIC_INV_BY_ID, 'y_block' => OIC_INV_SWEEP,
            'expect' => fn ($f, $c, $order, $r) => oicExpectLossVersusAcceptance($f, $c, $order, $r),
        ],

        'permission change vs acceptance' => [
            'x' => fn ($f, $c) => ['op' => 'accept', 'token' => $c['token'], 'name' => 'Newcomer', 'password' => 'a-Strong-Passw0rd!'],
            'y' => fn ($f, $c) => ['op' => 'sync_role_permissions', 'role' => $f['role']->id, 'permissions' => ['tickets.view'], 'actor' => $f['admin']->id],
            'setup' => fn ($f) => ['invitation' => ($i = oicInvite($f['org'], $f['r1'], 'invitee@oinv.test'))[0], 'token' => $i[1]],
            'x_freeze' => OIC_INV_BY_ID, 'y_freeze' => OIC_INV_SWEEP,
            'x_block' => OIC_INV_BY_ID, 'y_block' => OIC_INV_SWEEP,
            'expect' => fn ($f, $c, $order, $r) => oicExpectLossVersusAcceptance($f, $c, $order, $r),
        ],

        'built-in synchronization vs acceptance' => [
            'x' => fn ($f, $c) => ['op' => 'accept', 'token' => $c['token'], 'name' => 'Newcomer', 'password' => 'a-Strong-Passw0rd!'],
            'y' => fn ($f, $c) => ['op' => 'sync_builtin_roles', 'fingerprint' => $c['fingerprint']],
            'setup' => function ($f) {
                // A stray grant on the built-in `user` role that the definitions do not give it: the synchronization will revoke it.
                $stray = User::factory()->create(['email' => 'stray@oinv.test', 'password' => 'x']);
                $stray->assignRole('user');
                Role::findByName('user', 'web')->givePermissionTo(['portal.operator', 'crm.manage']);
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                $plan = app(BuiltInRoleSynchronizer::class)->plan();
                expect($plan->hasRevocations())->toBeTrue();
                [$id, $token] = oicInvite(Organization::query()->withoutGlobalScopes()->where('name', 'Race Org')->firstOrFail(), $stray, 'invitee@oinv.test');

                return ['invitation' => $id, 'token' => $token, 'fingerprint' => $plan->fingerprint()];
            },
            'x_freeze' => OIC_INV_BY_ID, 'y_freeze' => OIC_INV_SWEEP,
            'x_block' => OIC_INV_BY_ID, 'y_block' => OIC_INV_SWEEP,
            'expect' => fn ($f, $c, $order, $r) => oicExpectLossVersusAcceptance($f, $c, $order, $r),
        ],

        // Issue (a re-issue that supersedes) vs suspension of the issuing operator. Frozen after the slot lock: the mutation test for issue.
        'issuance vs suspension of the issuer' => [
            'x' => fn ($f, $c) => ['op' => 'issue', 'organization' => $f['org']->id, 'email' => 'invitee@oinv.test', 'actor' => $f['issuer']->id],
            'y' => fn ($f, $c) => ['op' => 'suspend', 'target' => $f['issuer']->id, 'actor' => $f['admin']->id],
            'setup' => fn ($f) => ['old' => oicInvite($f['org'], $f['issuer'], 'invitee@oinv.test')[0]],
            'x_freeze' => OIC_INV_SLOT, 'y_freeze' => OIC_USER_EXCL,
            'x_block' => OIC_USER_SHARE, 'y_block' => OIC_USER_EXCL,
            'expect' => function ($f, $c, $order, $r) {
                $all = DB::table('organization_invitations')->where('email', 'invitee@oinv.test')->orderBy('id')->get();
                expect(oicUser('issuer@oinv.test')->disabled_at)->not->toBeNull();
                if ($order === 'xy') {
                    // Issued first. The suspension's id list was read from a snapshot taken BEFORE it waited for the issuer's row, so the
                    // invitation the issuance committed in the meantime is not in it. This is the documented commit-race residual (design
                    // 6.2.4), confirmed on the real server: the new invitation is still Pending in the operator list, but it cannot be accepted or
                    // mailed (the issuer no longer holds authority) and the reinstatement sweep revokes it, below.
                    expect($r['x']['outcome'])->toBe('ok')->and($all)->toHaveCount(2)
                        ->and($all[0]->revoked_reason)->toBe('superseded')->and($all[1]->revoked_at)->toBeNull()->and($all[1]->live_email)->toBe('invitee@oinv.test');

                    DB::table('model_has_permissions')->whereIn('model_id', [$f['issuer']->id])->count(); // the issuer still holds both capabilities (a suspended account holds none by the predicate)
                    expect(OrganizationAuthority::isMembershipManager($f['issuer']->id))->toBeFalse();
                    DB::beginTransaction();
                    app(AccountLifecycleService::class)->reactivate(User::findOrFail($f['issuer']->id), $f['admin']);
                    DB::commit();
                    expect(oicRow($all[1]->id)->revoked_reason)->toBe('issuer_reinstated');
                } else {
                    // Suspended first: the issuance is refused at the table check and inserts nothing; the existing invitation was revoked.
                    expect($r['x']['outcome'])->toBe('unauthorized')->and($all)->toHaveCount(1)->and($all[0]->revoked_reason)->toBe('issuer_suspended')
                        ->and(DB::table('organization_invitations')->whereNull('revoked_at')->whereNull('accepted_at')->count())->toBe(0);
                }
            },
        ],

        'issuance vs revocation' => [
            'x' => fn ($f, $c) => ['op' => 'issue', 'organization' => $f['org']->id, 'email' => 'invitee@oinv.test', 'actor' => $f['issuer']->id],
            'y' => fn ($f, $c) => ['op' => 'revoke', 'organization' => $f['org']->id, 'invitation' => $c['old'], 'actor' => $f['other']->id],
            'setup' => fn ($f) => ['old' => oicInvite($f['org'], $f['issuer'], 'invitee@oinv.test')[0]],
            'x_freeze' => OIC_INV_SLOT, 'y_freeze' => OIC_INV_BY_ID_ORG,
            'x_block' => OIC_ORG_EXCL, 'y_block' => OIC_ORG_EXCL,
            'expect' => function ($f, $c, $order, $r) {
                $old = oicRow($c['old']);
                $open = DB::table('organization_invitations')->where('email', 'invitee@oinv.test')->whereNull('revoked_at')->whereNull('accepted_at')->get();
                expect($open)->toHaveCount(1)->and((int) $open[0]->id)->not->toBe($c['old'])->and($open[0]->live_email)->toBe('invitee@oinv.test');
                if ($order === 'xy') {
                    // The re-issue superseded it first; the revoke then finds a terminal row and does nothing (no second audit entry).
                    expect($old->revoked_reason)->toBe('superseded')->and($r['y']['result']['revoked'])->toBeFalse()
                        ->and(DB::table('activity_log')->where('description', OrganizationInvitations::REVOKED)->count())->toBe(0);
                } else {
                    expect($old->revoked_reason)->toBe('operator')->and($r['y']['result']['revoked'])->toBeTrue()
                        ->and(DB::table('activity_log')->where('description', OrganizationInvitations::SUPERSEDED)->count())->toBe(0);
                }
            },
        ],

        'acceptance vs revocation' => [
            'x' => fn ($f, $c) => ['op' => 'accept', 'token' => $c['token'], 'name' => 'Newcomer', 'password' => 'a-Strong-Passw0rd!'],
            'y' => fn ($f, $c) => ['op' => 'revoke', 'organization' => $f['org']->id, 'invitation' => $c['invitation'], 'actor' => $f['other']->id],
            'setup' => fn ($f) => ['invitation' => ($i = oicInvite($f['org'], $f['issuer'], 'invitee@oinv.test'))[0], 'token' => $i[1]],
            'x_freeze' => OIC_INV_BY_ID, 'y_freeze' => OIC_INV_BY_ID_ORG,
            'x_block' => OIC_ORG_EXCL, 'y_block' => OIC_ORG_EXCL,
            'expect' => function ($f, $c, $order, $r) {
                $row = oicRow($c['invitation']);
                if ($order === 'xy') {
                    expect($r['x']['outcome'])->toBe('ok')->and($r['y']['result']['revoked'])->toBeFalse()->and($row->accepted_at)->not->toBeNull()->and($row->revoked_at)->toBeNull()->and(oicMembers('invitee@oinv.test'))->toBe(1);
                } else {
                    expect($r['y']['result']['revoked'])->toBeTrue()->and($r['x']['outcome'])->toBe('refused:unavailable')->and($r['x']['detail'])->toBe('revoked')
                        ->and($row->revoked_reason)->toBe('operator')->and(oicUser('invitee@oinv.test'))->toBeNull()->and(oicMembers('invitee@oinv.test'))->toBe(0);
                }
            },
        ],

        'acceptance vs re-issue' => [
            'x' => fn ($f, $c) => ['op' => 'accept', 'token' => $c['token'], 'name' => 'Newcomer', 'password' => 'a-Strong-Passw0rd!'],
            'y' => fn ($f, $c) => ['op' => 'issue', 'organization' => $f['org']->id, 'email' => 'invitee@oinv.test', 'actor' => $f['other']->id],
            'setup' => fn ($f) => ['invitation' => ($i = oicInvite($f['org'], $f['issuer'], 'invitee@oinv.test'))[0], 'token' => $i[1]],
            'x_freeze' => OIC_INV_BY_ID, 'y_freeze' => OIC_INV_SLOT,
            'x_block' => OIC_ORG_EXCL, 'y_block' => OIC_ORG_EXCL,
            'expect' => function ($f, $c, $order, $r) {
                $old = oicRow($c['invitation']);
                if ($order === 'xy') {
                    // Accepted first; the re-issue then refuses: the address now has an account. Nothing new was inserted.
                    expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('refused:account_exists')
                        ->and($old->accepted_at)->not->toBeNull()->and(DB::table('organization_invitations')->count())->toBe(1);
                } else {
                    // Re-issued first: the old token is dead, the acceptance is refused, and exactly one invitation is open.
                    expect($r['y']['outcome'])->toBe('ok')->and($r['x']['outcome'])->toBe('refused:unavailable')->and($r['x']['detail'])->toBe('revoked')
                        ->and($old->revoked_reason)->toBe('superseded')->and(oicUser('invitee@oinv.test'))->toBeNull()
                        ->and(DB::table('organization_invitations')->whereNull('revoked_at')->count())->toBe(1);
                }
            },
        ],

        // The mutation test for revoke: the revoking operator is suspended while the revoke holds the invitation row.
        'revocation vs suspension of the revoking operator' => [
            'x' => fn ($f, $c) => ['op' => 'revoke', 'organization' => $f['org']->id, 'invitation' => $c['invitation'], 'actor' => $f['other']->id],
            'y' => fn ($f, $c) => ['op' => 'suspend', 'target' => $f['other']->id, 'actor' => $f['admin']->id],
            'setup' => fn ($f) => ['invitation' => oicInvite($f['org'], $f['other'], 'invitee@oinv.test')[0]],
            'x_freeze' => OIC_INV_BY_ID_ORG, 'y_freeze' => OIC_USER_EXCL,
            'x_block' => OIC_USER_SHARE, 'y_block' => OIC_USER_EXCL,
            'expect' => function ($f, $c, $order, $r) {
                $row = oicRow($c['invitation']);
                expect(oicUser('other@oinv.test')->disabled_at)->not->toBeNull();
                if ($order === 'xy') {
                    // The operator's revoke committed (reason operator, slot released) before the suspension's sweep, which then finds a revoked row.
                    expect($r['x']['result']['revoked'])->toBeTrue()->and($row->revoked_reason)->toBe('operator')->and($row->live_email)->toBeNull();
                } else {
                    // The suspension's sweep revoked it and, by design (O4/R1), left the slot held.
                    expect($r['x']['outcome'])->toBe('unauthorized')->and($row->revoked_reason)->toBe('issuer_suspended')->and($row->live_email)->toBe('invitee@oinv.test');
                }
            },
        ],

        'two acceptances of one token' => [
            'x' => fn ($f, $c) => ['op' => 'accept', 'token' => $c['token'], 'name' => 'First', 'password' => 'a-Strong-Passw0rd!'],
            'y' => fn ($f, $c) => ['op' => 'accept', 'token' => $c['token'], 'name' => 'Second', 'password' => 'a-Strong-Passw0rd!'],
            'setup' => fn ($f) => ['invitation' => ($i = oicInvite($f['org'], $f['issuer'], 'invitee@oinv.test'))[0], 'token' => $i[1]],
            'x_freeze' => OIC_ORG_EXCL, 'y_freeze' => OIC_ORG_EXCL,
            'x_block' => OIC_ORG_EXCL, 'y_block' => OIC_ORG_EXCL,
            'orders' => ['xy'],
            'expect' => function ($f, $c, $order, $r) {
                expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('refused:unavailable')->and($r['y']['detail'])->toBe('accepted')
                    ->and(DB::table('users')->where('email', 'invitee@oinv.test')->count())->toBe(1)->and(oicMembers('invitee@oinv.test'))->toBe(1);
            },
        ],

        // Two organizations, one address: different organization rows, so the contention is the users.email unique key at the insert.
        'two acceptances of one address in different organizations' => [
            'x' => fn ($f, $c) => ['op' => 'accept', 'token' => $c['token1'], 'name' => 'One', 'password' => 'a-Strong-Passw0rd!'],
            'y' => fn ($f, $c) => ['op' => 'accept', 'token' => $c['token2'], 'name' => 'Two', 'password' => 'a-Strong-Passw0rd!'],
            'setup' => fn ($f) => ['one' => ($a = oicInvite($f['org'], $f['issuer'], 'invitee@oinv.test'))[0], 'token1' => $a[1], 'two' => ($b = oicInvite($f['org2'], $f['issuer'], 'invitee@oinv.test'))[0], 'token2' => $b[1]],
            'x_freeze' => OIC_USER_INSERT, 'y_freeze' => OIC_USER_INSERT,
            'x_block' => OIC_USER_INSERT, 'y_block' => OIC_USER_INSERT,
            'expect' => function ($f, $c, $order, $r) {
                [$winner, $loser, $won, $lost] = $order === 'xy' ? ['x', 'y', $c['one'], $c['two']] : ['y', 'x', $c['two'], $c['one']];
                // One account; the loser's duplicate on users.email was read as that key and refused as "unavailable"; its invitation stays open.
                expect($r[$winner]['outcome'])->toBe('ok')->and($r[$loser]['outcome'])->toBe('refused:unavailable')->and($r[$loser]['detail'])->toBe('account_exists')
                    ->and(DB::table('users')->where('email', 'invitee@oinv.test')->count())->toBe(1)->and(oicMembers('invitee@oinv.test'))->toBe(1)
                    ->and(oicRow($won)->accepted_at)->not->toBeNull()->and(oicRow($lost)->accepted_at)->toBeNull()->and(oicRow($lost)->live_email)->toBe('invitee@oinv.test');
            },
        ],

        // Reactivation (no permission row) and a guard-class hook revoke DISJOINT invitation rows whose keys interleave; both ascending.
        'reactivation vs a guard-class hook' => [
            'x' => fn ($f, $c) => ['op' => 'reactivate', 'target' => $f['issuer']->id, 'actor' => $f['admin']->id],
            'y' => fn ($f, $c) => ['op' => 'sync_role_permissions', 'role' => $f['role']->id, 'permissions' => ['tickets.view'], 'actor' => $f['admin']->id],
            'setup' => function ($f) {
                DB::table('users')->where('id', $f['issuer']->id)->update(['disabled_at' => now()]);
                // Interleaved primary keys: issuer a1, role-holder b1, issuer a2, role-holder b2.
                $ids = [oicInvite($f['org'], $f['issuer'], 'a1@oinv.test')[0], oicInvite($f['org'], $f['r1'], 'b1@oinv.test')[0], oicInvite($f['org'], $f['issuer'], 'a2@oinv.test')[0], oicInvite($f['org'], $f['r2'], 'b2@oinv.test')[0]];

                return ['ids' => $ids];
            },
            'x_freeze' => OIC_INV_SWEEP, 'y_freeze' => OIC_INV_SWEEP,
            'x_block' => OIC_INV_SWEEP, 'y_block' => OIC_INV_SWEEP,
            'blocks' => false,
            'expect' => function ($f, $c, $order, $r) {
                expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('ok');
                [$a1, $b1, $a2, $b2] = $c['ids'];
                expect(oicRow($a1)->revoked_reason)->toBe('issuer_reinstated')->and(oicRow($a2)->revoked_reason)->toBe('issuer_reinstated')
                    ->and(oicRow($b1)->revoked_reason)->toBe('issuer_authority_lost')->and(oicRow($b2)->revoked_reason)->toBe('issuer_authority_lost');
            },
        ],

        // A guard-class sweep holds the invitation row it locked; a re-issue of THAT address holds the organization row and wants that same row.
        // If the sweep then needs the organization row (see the design record, section 18) the two form a cycle.
        'a sweep vs a re-issue of the address it sweeps' => [
            'x' => fn ($f, $c) => ['op' => 'sync_role_permissions', 'role' => $f['role']->id, 'permissions' => ['tickets.view'], 'actor' => $f['admin']->id],
            'y' => fn ($f, $c) => ['op' => 'issue', 'organization' => $f['org']->id, 'email' => 'b1@oinv.test', 'actor' => $f['other']->id],
            'setup' => fn ($f) => ['ids' => [oicInvite($f['org'], $f['r1'], 'b1@oinv.test')[0]]],
            'x_freeze' => OIC_INV_SWEEP, 'y_freeze' => OIC_INV_SLOT,
            'x_block' => OIC_INV_SWEEP, 'y_block' => OIC_INV_SLOT,
            'expect' => function ($f, $c, $order, $r) {
                expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('ok');
                // Whichever order: the old invitation ends revoked and exactly one is open.
                expect(DB::table('organization_invitations')->where('email', 'b1@oinv.test')->whereNull('revoked_at')->whereNull('accepted_at')->count())->toBe(1);
            },
        ],

        // The residual the design names: a sweep that has WRITTEN (so it holds the secondary-index entries it changed) and an unrelated
        // issuance in the same organization, which looks up and inserts into the same unique (organization, live_email) index.
        'a sweep vs an unrelated issuance in the same organization' => [
            'x' => fn ($f, $c) => ['op' => 'sync_role_permissions', 'role' => $f['role']->id, 'permissions' => ['tickets.view'], 'actor' => $f['admin']->id],
            'y' => fn ($f, $c) => ['op' => 'issue', 'organization' => $f['org']->id, 'email' => 'fresh@oinv.test', 'actor' => $f['issuer']->id],
            'setup' => fn ($f) => ['ids' => [oicInvite($f['org'], $f['r1'], 'b1@oinv.test')[0], oicInvite($f['org'], $f['r2'], 'b2@oinv.test')[0]]],
            'x_freeze' => OIC_INV_UPDATE, 'y_freeze' => OIC_INV_SLOT,
            'x_block' => OIC_INV_UPDATE, 'y_block' => OIC_INV_SLOT,
            'blocks' => null,
            'expect' => function ($f, $c, $order, $r) {
                expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('ok');
                expect(oicRow($c['ids'][0])->revoked_reason)->toBe('issuer_authority_lost')->and(oicRow($c['ids'][1])->revoked_reason)->toBe('issuer_authority_lost')
                    ->and(DB::table('organization_invitations')->where('email', 'fresh@oinv.test')->whereNull('revoked_at')->count())->toBe(1);
            },
        ],

        // ── O4 + R4 (the remediation of the section 18.3 deadlocks): the cases the review added ──────────────────────────────────────────

        // Suspension of an account that is ALSO the subject of membership work in the organization. The hook no longer writes live_email or
        // organization_id, so its update takes no foreign-key lock on the organization row that the membership work holds exclusively.
        'suspension vs membership addition' => [
            'x' => fn ($f, $c) => ['op' => 'add_member', 'organization' => $f['org']->id, 'user' => $f['issuer']->id, 'role' => 'member', 'actor' => $f['other']->id],
            'y' => fn ($f, $c) => ['op' => 'suspend', 'target' => $f['issuer']->id, 'actor' => $f['admin']->id],
            'setup' => fn ($f) => ['invitation' => oicInvite($f['org'], $f['issuer'], 'invitee@oinv.test')[0]],
            'x_freeze' => OIC_ORG_EXCL, 'y_freeze' => OIC_INV_UPDATE,
            'x_block' => OIC_MEMBER_INSERT, 'y_block' => OIC_USER_EXCL,
            'blocks' => ['xy' => false, 'yx' => true],
            'expect' => function ($f, $c, $order, $r) {
                expect(oicUser('issuer@oinv.test')->disabled_at)->not->toBeNull()->and(oicRow($c['invitation'])->revoked_reason)->toBe('issuer_suspended')
                    ->and(oicRow($c['invitation'])->live_email)->toBe('invitee@oinv.test')->and($r['y']['outcome'])->toBe('ok');
                if ($order === 'xy') {
                    // The suspension committed while the addition held only the organization row; the addition then reads the account as suspended.
                    expect($r['x']['outcome'])->toBe('membership:account_suspended')->and(oicMembers('issuer@oinv.test'))->toBe(0);
                } else {
                    // The addition read the account before the suspension committed (the documented race the organization lock does not close).
                    expect($r['x']['outcome'])->toBe('ok')->and(oicMembers('issuer@oinv.test'))->toBe(1);
                }
            },
        ],

        'suspension vs membership addition, after the insert' => [
            'x' => fn ($f, $c) => ['op' => 'add_member', 'organization' => $f['org']->id, 'user' => $f['issuer']->id, 'role' => 'member', 'actor' => $f['other']->id],
            'y' => fn ($f, $c) => ['op' => 'suspend', 'target' => $f['issuer']->id, 'actor' => $f['admin']->id],
            'setup' => fn ($f) => ['invitation' => oicInvite($f['org'], $f['issuer'], 'invitee@oinv.test')[0]],
            'x_freeze' => OIC_MEMBER_INSERT, 'y_freeze' => OIC_INV_UPDATE,
            'x_block' => OIC_MEMBER_INSERT, 'y_block' => OIC_USER_EXCL,
            'orders' => ['xy'],
            'expect' => function ($f, $c, $order, $r) {
                // The addition holds the organization row and the new row's foreign-key share lock on the account; the suspension waits for it.
                expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('ok')->and(oicMembers('issuer@oinv.test'))->toBe(1)
                    ->and(oicUser('issuer@oinv.test')->disabled_at)->not->toBeNull()->and(oicRow($c['invitation'])->revoked_reason)->toBe('issuer_suspended');
            },
        ],

        'suspension vs membership approval' => [
            'x' => fn ($f, $c) => ['op' => 'approve_member', 'organization' => $f['org']->id, 'user' => $f['madmin']->id, 'actor' => $f['other']->id],
            'y' => fn ($f, $c) => ['op' => 'suspend', 'target' => $f['madmin']->id, 'actor' => $f['admin']->id],
            'setup' => fn ($f) => ['invitation' => oicInvite($f['org'], $f['madmin'], 'invitee@oinv.test')[0]],
            'x_freeze' => OIC_ORG_EXCL, 'y_freeze' => OIC_INV_UPDATE,
            'x_block' => OIC_MEMBER_UPDATE, 'y_block' => OIC_USER_EXCL,
            'blocks' => ['xy' => false, 'yx' => false],
            'expect' => function ($f, $c, $order, $r) {
                expect(oicUser('madmin@oinv.test')->disabled_at)->not->toBeNull()->and($r['y']['outcome'])->toBe('ok')
                    ->and(oicRow($c['invitation'])->revoked_reason)->toBe('issuer_suspended');
                if ($order === 'xy') {
                    expect($r['x']['outcome'])->toBe('membership:account_suspended');
                } else {
                    // The approval read the account before the suspension committed.
                    expect($r['x']['outcome'])->toBe('ok');
                }
            },
        ],

        'suspension vs membership approval, after the update' => [
            'x' => fn ($f, $c) => ['op' => 'approve_member', 'organization' => $f['org']->id, 'user' => $f['madmin']->id, 'actor' => $f['other']->id],
            'y' => fn ($f, $c) => ['op' => 'suspend', 'target' => $f['madmin']->id, 'actor' => $f['admin']->id],
            'setup' => fn ($f) => ['invitation' => oicInvite($f['org'], $f['madmin'], 'invitee@oinv.test')[0]],
            'x_freeze' => OIC_MEMBER_UPDATE, 'y_freeze' => OIC_INV_UPDATE,
            'x_block' => OIC_MEMBER_UPDATE, 'y_block' => OIC_USER_EXCL,
            'orders' => ['xy'],
            'blocks' => false,
            'expect' => function ($f, $c, $order, $r) {
                expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('ok')->and(oicUser('madmin@oinv.test')->disabled_at)->not->toBeNull()
                    ->and(oicRow($c['invitation'])->revoked_reason)->toBe('issuer_suspended');
            },
        ],

        'suspension vs Ticket creation' => [
            'x' => fn ($f, $c) => ['op' => 'create_ticket', 'user' => $f['member']->id, 'organization' => $f['org']->id],
            'y' => fn ($f, $c) => ['op' => 'suspend', 'target' => $f['member']->id, 'actor' => $f['admin']->id],
            'setup' => fn ($f) => ['invitation' => oicInvite($f['org'], $f['member'], 'invitee@oinv.test')[0]],
            'x_freeze' => OIC_ORG_SHARE, 'y_freeze' => OIC_INV_UPDATE,
            'x_block' => OIC_TICKET_INSERT, 'y_block' => OIC_USER_EXCL,
            'blocks' => ['xy' => false, 'yx' => true],
            'expect' => function ($f, $c, $order, $r) {
                // A request already past its authorization check completes; the suspension's invitation sweep is not affected by it.
                expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('ok')->and(DB::table('tickets')->count())->toBe(1)
                    ->and(oicUser('member@oinv.test')->disabled_at)->not->toBeNull()->and(oicRow($c['invitation'])->revoked_reason)->toBe('issuer_suspended');
            },
        ],

        'suspension vs Ticket creation, after the insert' => [
            'x' => fn ($f, $c) => ['op' => 'create_ticket', 'user' => $f['member']->id, 'organization' => $f['org']->id],
            'y' => fn ($f, $c) => ['op' => 'suspend', 'target' => $f['member']->id, 'actor' => $f['admin']->id],
            'setup' => fn ($f) => ['invitation' => oicInvite($f['org'], $f['member'], 'invitee@oinv.test')[0]],
            'x_freeze' => OIC_TICKET_INSERT, 'y_freeze' => OIC_INV_UPDATE,
            'x_block' => OIC_TICKET_INSERT, 'y_block' => OIC_USER_EXCL,
            'orders' => ['xy'],
            'expect' => function ($f, $c, $order, $r) {
                expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('ok')->and(DB::table('tickets')->count())->toBe(1)
                    ->and(oicUser('member@oinv.test')->disabled_at)->not->toBeNull()->and(oicRow($c['invitation'])->revoked_reason)->toBe('issuer_suspended');
            },
        ],

        // The third lock mechanism of the review: a role detach takes a next-key lock on the model_has_roles index entry of the edited account.
        // When that account holds the HIGHEST user id, the gap above it is where an acceptance inserts the NEW account's role.
        'role change of the highest-id account vs acceptance, frozen after their role writes' => [
            'x' => fn ($f, $c) => ['op' => 'accept', 'token' => $c['token'], 'name' => 'Newcomer', 'password' => 'a-Strong-Passw0rd!'],
            'y' => fn ($f, $c) => ['op' => 'sync_user_roles', 'target' => $f['top']->id, 'roles' => [], 'actor' => $f['admin']->id],
            'setup' => fn ($f) => ['invitation' => ($i = oicInvite($f['org'], $f['top'], 'invitee@oinv.test'))[0], 'token' => $i[1]],
            'x_freeze' => OIC_ROLE_INSERT, 'y_freeze' => OIC_ROLE_DELETE,
            'x_block' => OIC_INV_BY_ID, 'y_block' => OIC_INV_SWEEP,
            'expect' => fn ($f, $c, $order, $r) => oicExpectLossVersusAcceptance($f, $c, $order, $r),
        ],

        // The sweep has WRITTEN (it holds everything it will hold) when the re-issue of the very address it swept asks for the slot.
        'a sweep vs a re-issue of the address it sweeps, sweep frozen after its write' => [
            'x' => fn ($f, $c) => ['op' => 'sync_role_permissions', 'role' => $f['role']->id, 'permissions' => ['tickets.view'], 'actor' => $f['admin']->id],
            'y' => fn ($f, $c) => ['op' => 'issue', 'organization' => $f['org']->id, 'email' => 'b1@oinv.test', 'actor' => $f['other']->id],
            'setup' => fn ($f) => ['ids' => [oicInvite($f['org'], $f['r1'], 'b1@oinv.test')[0]]],
            'x_freeze' => OIC_INV_UPDATE, 'y_freeze' => OIC_INV_SLOT,
            'x_block' => OIC_INV_SWEEP, 'y_block' => OIC_INV_SLOT,
            'expect' => function ($f, $c, $order, $r) {
                expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('ok');
                $old = oicRow($c['ids'][0]);
                // Whichever order: the old invitation is dead and exactly one is open. When the sweep committed first it left the slot held, and the
                // re-issue released it and took it.
                expect($old->revoked_reason)->toBeIn(['issuer_authority_lost', 'superseded'])
                    ->and(DB::table('organization_invitations')->where('email', 'b1@oinv.test')->whereNull('revoked_at')->whereNull('accepted_at')->count())->toBe(1)
                    ->and(DB::table('organization_invitations')->where('email', 'b1@oinv.test')->whereNotNull('live_email')->count())->toBe(1);
            },
        ],

        // R3: the actor's row is share-locked BEFORE the invitations. The acting account here is suspended in the database (it is only the
        // `revoked_by` the sweep will write), so a reactivation of it is a transaction that holds the account row exclusively and then asks for
        // invitation rows. Without the actor lock the sweep's foreign-key check on that row and the reactivation's invitation locks form a cycle.
        'a sweep whose actor is being reactivated' => [
            'x' => fn ($f, $c) => ['op' => 'sync_role_permissions', 'role' => $f['role']->id, 'permissions' => ['tickets.view'], 'actor' => $f['other']->id],
            'y' => fn ($f, $c) => ['op' => 'reactivate', 'target' => $f['other']->id, 'actor' => $f['admin']->id],
            'setup' => function ($f) {
                DB::table('users')->where('id', $f['other']->id)->update(['disabled_at' => now()]);

                return ['revoked' => oicInvite($f['org'], $f['r1'], 'b1@oinv.test')[0], 'own' => oicInvite($f['org'], $f['other'], 'own@oinv.test')[0]];
            },
            'x_freeze' => OIC_INV_SWEEP, 'y_freeze' => OIC_USER_EXCL,
            'x_block' => OIC_USER_SHARE, 'y_block' => OIC_USER_EXCL,
            'expect' => function ($f, $c, $order, $r) {
                expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('ok');
                $swept = oicRow($c['revoked']);
                expect($swept->revoked_reason)->toBe('issuer_authority_lost')->and((int) $swept->revoked_by)->toBe($f['other']->id)
                    ->and(oicUser('other@oinv.test')->disabled_at)->toBeNull()
                    ->and(oicRow($c['own'])->revoked_reason)->toBe('issuer_reinstated');
            },
        ],
    ];
}

function oicExpectLossVersusAcceptance(array $f, array $c, string $order, array $r): void
{
    $row = oicRow($c['invitation']);

    if ($order === 'xy') {
        // The acceptance linearized before the change: it committed, and the sweep then found an accepted row and left it.
        expect($r['x']['outcome'])->toBe('ok')->and($r['y']['outcome'])->toBe('ok')
            ->and($row->accepted_at)->not->toBeNull()->and($row->revoked_at)->toBeNull()->and(oicMembers('invitee@oinv.test'))->toBe(1);
    } else {
        // The loss and its revocation committed together, so the acceptance saw both: refused, and nothing was written.
        expect($r['y']['outcome'])->toBe('ok')->and($r['x']['outcome'])->toBe('refused:unavailable')->and($r['x']['detail'])->toBe('revoked')
            ->and($row->revoked_reason)->toBe('issuer_authority_lost')->and($row->accepted_at)->toBeNull()
            ->and(oicUser('invitee@oinv.test'))->toBeNull()->and(oicMembers('invitee@oinv.test'))->toBe(0);
    }
}

/** Whether the second operation of this scenario and order must be observed waiting on the first. A spec may say `blocks` for all orders or per order. */
function oicExpectsBlocked(array $spec, string $order): bool
{
    $blocks = array_key_exists('blocks', $spec) ? $spec['blocks'] : true;

    return is_array($blocks) ? ($blocks[$order] ?? true) === true : $blocks === true;
}

foreach (oicScenarios() as $name => $spec) {
    foreach ($spec['orders'] ?? ['xy', 'yx'] as $order) {
        it("{$name} [".($order === 'xy' ? 'first operation frozen, second blocked' : 'second operation frozen, first blocked').']', function () use ($spec, $order) {
            $fixture = oicFixture();

            try {
                $context = $spec['setup']($fixture);
                [$first, $second] = $order === 'xy' ? ['x', 'y'] : ['y', 'x'];

                $ran = oicInterleave(
                    $spec[$first]($fixture, $context), $spec[$first.'_freeze'],
                    $spec[$second]($fixture, $context), $spec[$second.'_block'],
                    oicExpectsBlocked($spec, $order),
                );

                $results = [$first => $ran['first'], $second => $ran['second']];
                // Acceptance's own statement stream: issuer row (S), then organization (X), then invitation (X), before any write.
                foreach ($results as $result) {
                    if (($result['outcome'] ?? '') === 'ok' || str_starts_with($result['outcome'] ?? '', 'refused')) {
                        $locks = oicLocks($result);
                        if (in_array('S users', $locks, true) && in_array('X organization_invitations', $locks, true) && $locks[0] === 'S users') {
                            expect(array_slice($locks, 0, 3))->toBe(['S users', 'X organizations', 'X organization_invitations'], json_encode(['locks' => $locks, 'transactions' => $result['transactions'] ?? null, 'rollbacks' => $result['rollbacks'] ?? null, 'outcome' => $result['outcome'], 'detail' => $result['detail']]));
                        }
                    }
                }

                $spec['expect']($fixture, $context, $order, $results);
            } finally {
                oicTeardown($fixture);
            }
        });
    }
}

// ── Acceptance statement stream ──────────────────────────────────────────────

it('acceptance, in its own process with the database cache store, locks issuer then organization then invitation and runs no cache statement inside its transaction', function () {
    $fixture = oicFixture();

    try {
        [$id, $token] = oicInvite($fixture['org'], $fixture['issuer'], 'invitee@oinv.test');
        DB::table('cache')->delete();

        $process = oicStart(['op' => 'accept', 'token' => $token, 'name' => 'Newcomer', 'password' => 'a-Strong-Passw0rd!'], ['CACHE_STORE' => 'database']);
        $result = oicResult($process);

        expect($result['outcome'])->toBe('ok');

        $inTransaction = collect($result['statements'])->filter(fn ($s) => $s['transactional']);
        expect($inTransaction->where('cache', true)->count())->toBe(0, 'a statement touched the cache tables inside the acceptance transaction')
            ->and(array_slice(oicLocks($result), 0, 3))->toBe(['S users', 'X organizations', 'X organization_invitations'])
            // The first write of the transaction comes after the three locks.
            ->and($inTransaction->search(fn ($s) => preg_match('/^(insert|update|delete)/i', $s['sql']) === 1))->toBeGreaterThan(2)
            ->and(oicMembers('invitee@oinv.test'))->toBe(1);

        // CONTROL: the database cache store really is in play in such a process. An issuance in the same configuration asks the permission
        // cache for something (the Gate, outside its transaction) and so DOES touch the cache tables, but never inside its transaction.
        $issued = oicResult(oicStart(['op' => 'issue', 'organization' => $fixture['org']->id, 'email' => 'second@oinv.test', 'actor' => $fixture['issuer']->id], ['CACHE_STORE' => 'database']));
        // Not "any statement on the cache table" (the epoch read alone would satisfy that): the permission COPY was read from the database store.
        $copyReads = collect($issued['statements'])->filter(fn ($s) => $s['cache'] && str_starts_with($s['sql'], 'select * from `cache` where `key` in'));
        expect($issued['outcome'])->toBe('ok')
            ->and($copyReads->count())->toBeGreaterThan(0, 'control: the permission copy was not read from the database cache store')
            ->and(collect($issued['statements'])->where('cache', true)->where('transactional', true)->count())->toBe(0);
    } finally {
        DB::table('cache')->delete();
        oicTeardown($fixture);
    }
});

// ── Three transactions: a guard-class hook (H), a cold-cache Ticket (T) and a re-issue (I) ───────────────────────────────────────────────

/**
 * A scripted run of several workers (real processes, real connections). Each step is ['do' => start|release|observe|finish, 'name' => worker],
 * a `start` with an `until` of held (frozen at its `hold` statement), blocked (announced its `signal` statement and is still running after
 * a pause: waiting on a lock), finished, or started. A worker's operation may carry `hold` and `signal` patterns; the runner adds the
 * directory and tag. Everything still running at the end, or after a failure, is stopped.
 *
 * @param  array<string, array<string, mixed>>  $workers
 * @param  list<array<string, mixed>>  $steps
 * @return array{results: array<string, array<string, mixed>>, blocked: array<string, bool>, held: array<string, bool>, running: array<string, bool>}
 */
function oicScript(array $workers, array $steps, array $env = []): array
{
    $dir = sys_get_temp_dir().'/oinv-'.bin2hex(random_bytes(6));
    mkdir($dir);

    /** @var array<string, Process> $processes */
    $processes = [];
    $blocked = [];
    $held = [];
    $running = [];
    $results = [];

    try {
        foreach ($steps as $step) {
            $name = $step['name'];

            switch ($step['do']) {
                case 'start':
                    $operation = $workers[$name];
                    foreach (['hold', 'signal'] as $key) {
                        if (isset($operation[$key])) {
                            $operation[$key] += ['dir' => $dir, 'tag' => $name];
                        }
                    }
                    $processes[$name] = oicStart($operation, $env);

                    match ($step['until'] ?? 'started') {
                        'held' => (function () use ($dir, $name, $processes) {
                            $deadline = microtime(true) + 40;
                            while (! is_file("{$dir}/{$name}.held") && microtime(true) < $deadline && $processes[$name]->isRunning()) {
                                usleep(10_000);
                            }
                            if (! is_file("{$dir}/{$name}.held")) {
                                throw new RuntimeException("worker {$name} never reached its freeze point: ".$processes[$name]->getOutput().$processes[$name]->getErrorOutput());
                            }
                        })(),
                        'blocked' => (function () use ($dir, $name, $processes, &$blocked, &$held) {
                            $startedAt = microtime(true);
                            while (! is_file("{$dir}/{$name}.requesting") && $processes[$name]->isRunning() && microtime(true) - $startedAt < 6.0) {
                                usleep(10_000);
                            }
                            usleep(1_200_000);
                            $blocked[$name] = $processes[$name]->isRunning();
                            $held[$name] = is_file("{$dir}/{$name}.held");
                        })(),
                        'finished' => (function () use ($name, $processes) {
                            $deadline = microtime(true) + 30;
                            while ($processes[$name]->isRunning() && microtime(true) < $deadline) {
                                usleep(20_000);
                            }
                            if ($processes[$name]->isRunning()) {
                                throw new RuntimeException("worker {$name} did not finish: it is waiting on a lock another worker holds");
                            }
                        })(),
                        default => usleep(300_000),
                    };
                    break;

                case 'release':
                    touch("{$dir}/{$name}.release");
                    break;

                case 'observe':
                    usleep(1_500_000);
                    $running[$name] = $processes[$name]->isRunning();
                    break;

                case 'finish':
                    $results[$name] = oicResult($processes[$name]);
                    break;
            }
        }

        foreach ($processes as $name => $process) {
            $results[$name] ??= oicResult($process);
        }
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(2);
            }
        }
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }

    return compact('results', 'blocked', 'held', 'running');
}

/** The cache key of the permission epoch row, and of the unsuffixed (legacy) permission cache keys. @return array{epoch: string, base: string, flexible: string} */
function oicCacheKeys(): array
{
    $epoch = app(PermissionCacheEpoch::class)->key();

    $prefix = substr($epoch, 0, -strlen('spatie.permission.epoch'));

    // `flexible`: the companion key the framework's cache `forget` also deletes for the unsuffixed legacy key.
    return ['epoch' => $epoch, 'base' => $prefix.config('permission.cache.key'), 'flexible' => $prefix.'illuminate:cache:flexible:created:'.config('permission.cache.key')];
}

/** Make the database cache cold: every cached copy gone, and the epoch row either kept or deleted. */
function oicColdCache(bool $keepEpoch): void
{
    $keys = oicCacheKeys();

    if ($keepEpoch) {
        app(PermissionCacheEpoch::class)->current();
        DB::table('cache')->where('key', '!=', $keys['epoch'])->delete();
        expect(DB::table('cache')->where('key', $keys['epoch'])->exists())->toBeTrue();
    } else {
        DB::table('cache')->delete();
    }
}

/**
 * The H operation: either a role's permission change, or the built-in synchronization that CREATES a missing permission (the Permission model
 * then reads the permission mapping through the cache inside the guarded transaction).
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>} the operation and the extra context
 */
function oicHookOperation(array $f, string $kind): array
{
    if ($kind === 'role permissions') {
        return [['op' => 'sync_role_permissions', 'role' => $f['role']->id, 'permissions' => ['tickets.view'], 'actor' => $f['admin']->id], []];
    }

    // The built-in synchronization with ONE permission missing: its plan CREATES it.
    Permission::findByName('tickets.assign', 'web')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $plan = app(BuiltInRoleSynchronizer::class)->plan();
    expect(collect($plan->actions)->pluck('action')->contains('create_permission'))->toBeTrue();

    return [['op' => 'sync_builtin_roles', 'fingerprint' => $plan->fingerprint()], ['created' => 'tickets.assign']];
}

foreach (['role permissions', 'built-in synchronization creating a permission'] as $kind) {
    foreach (['present', 'missing'] as $epoch) {
        it("H→T→I: {$kind}, cold database cache, epoch row {$epoch}: no deadlock, no lock wait, no retry", function () use ($kind, $epoch) {
            $f = oicFixture();

            try {
                [$h, $extra] = oicHookOperation($f, str_starts_with($kind, 'built-in') ? 'built-in' : 'role permissions');
                [$old] = oicInvite($f['org'], $f['r1'], 'b1@oinv.test');
                oicColdCache($epoch === 'present');

                $workers = [
                    // T: a Ticket for a member, whose authorization check runs INSIDE its transaction and, on a cold cache, writes the cache row.
                    // Frozen after that write, before it asks for any organization lock.
                    'T' => ['op' => 'create_ticket', 'user' => $f['member']->id, 'organization' => $f['org']->id, 'hold' => ['pattern' => OIC_CACHE_PUT]],
                    // H: frozen after it has locked the invitations (they are locked before the mutation touches anything).
                    'H' => $h + ['hold' => ['pattern' => OIC_INV_SWEEP], 'signal' => ['pattern' => OIC_EPOCH_ADVANCE]],
                    // I: a re-issue for the address H's invitation holds, from another operator.
                    'I' => ['op' => 'issue', 'organization' => $f['org']->id, 'email' => 'b1@oinv.test', 'actor' => $f['other']->id, 'signal' => ['pattern' => OIC_INV_SLOT]],
                ];

                $env = ['CACHE_STORE' => 'database'];

                if ($epoch === 'present') {
                    $run = oicScript($workers, [
                        ['do' => 'start', 'name' => 'T', 'until' => 'held'],
                        ['do' => 'start', 'name' => 'H', 'until' => 'held'],
                        ['do' => 'start', 'name' => 'I', 'until' => 'blocked'],
                        ['do' => 'release', 'name' => 'H'],
                        ['do' => 'observe', 'name' => 'H'],
                        ['do' => 'release', 'name' => 'T'],
                    ], $env);

                    expect($run['blocked']['I'])->toBeTrue('the re-issue was not waiting on the hook that holds the invitation')
                        // The decisive observation: with T frozen holding the cache row it wrote, H (past its invitation locks, in its mutation)
                        // finished. Its loads of the permission mapping used its own private epoch key, so it never waited for T.
                        ->and($run['running']['H'])->toBeFalse('the hook was still waiting while the Ticket held its cache row: it needed a lock the Ticket holds');
                } else {
                    $run = oicScript($workers, [
                        ['do' => 'start', 'name' => 'T', 'until' => 'held'],
                        ['do' => 'start', 'name' => 'H', 'until' => 'blocked'],
                        ['do' => 'start', 'name' => 'I'],
                        ['do' => 'observe', 'name' => 'I'],
                        ['do' => 'release', 'name' => 'T'],
                        ['do' => 'release', 'name' => 'H'],
                    ], $env);

                    // The epoch row did not exist: T created it (uncommitted) and holds it for its whole transaction. The hook's advance waits for it
                    // BEFORE the hook holds any invitation, so it holds nothing anyone else needs; the re-issue's own permission check (outside its
                    // transaction) waits for the same row. Both resume when T commits.
                    expect($run['blocked']['H'])->toBeTrue('the hook was not waiting for the Ticket that created the epoch row')
                        ->and($run['held']['H'])->toBeFalse('the hook reached its invitation locks before the epoch row was available')
                        ->and($run['running']['I'])->toBeTrue('the re-issue was not waiting for the epoch row');
                }

                foreach ($run['results'] as $name => $result) {
                    oicAssertNoVictim($name, $result);
                    expect($result['outcome'])->toBe('ok', "worker {$name}: ".($result['detail'] ?? ''));
                }

                expect(DB::table('tickets')->count())->toBe(1)
                    ->and(DB::table('organization_invitations')->where('email', 'b1@oinv.test')->whereNull('revoked_at')->whereNull('accepted_at')->count())->toBe(1)
                    ->and(oicRow($old)->revoked_at)->not->toBeNull();

                if ($extra !== []) {
                    expect(Permission::where('name', $extra['created'])->exists())->toBeTrue();
                }
            } finally {
                DB::table('cache')->delete();
                oicTeardown($f);
            }
        });
    }
}

// The other order: the hook is frozen FIRST, holding everything it takes before its mutation (users.manage, the actor, the epoch row, the gap
// locks of the library's legacy-key deletes, every open invitation), and the cold-cache Ticket and the re-issue arrive behind it.
foreach (['role permissions', 'built-in synchronization creating a permission'] as $kind) {
    foreach (['present', 'missing'] as $epoch) {
        it("T and I arriving behind a frozen hook: {$kind}, cold database cache, epoch row {$epoch}: they wait, then finish; no deadlock, no lock wait, no retry", function () use ($kind, $epoch) {
            $f = oicFixture();

            try {
                [$h] = oicHookOperation($f, str_starts_with($kind, 'built-in') ? 'built-in' : 'role permissions');
                [$old] = oicInvite($f['org'], $f['r1'], 'b1@oinv.test');
                oicColdCache($epoch === 'present');

                $run = oicScript([
                    'H' => $h + ['hold' => ['pattern' => OIC_INV_SWEEP]],
                    'T' => ['op' => 'create_ticket', 'user' => $f['member']->id, 'organization' => $f['org']->id, 'signal' => ['pattern' => OIC_CACHE_PUT]],
                    'I' => ['op' => 'issue', 'organization' => $f['org']->id, 'email' => 'b1@oinv.test', 'actor' => $f['other']->id, 'signal' => ['pattern' => OIC_INV_SLOT]],
                ], [
                    ['do' => 'start', 'name' => 'H', 'until' => 'held'],
                    ['do' => 'start', 'name' => 'T'],
                    ['do' => 'start', 'name' => 'I'],
                    ['do' => 'observe', 'name' => 'T'],
                    ['do' => 'observe', 'name' => 'I'],
                    ['do' => 'release', 'name' => 'H'],
                ], ['CACHE_STORE' => 'database']);

                // Whether T and I wait is what the lock structure dictates, not something to force: I certainly waits (it needs the invitation row H
                // holds, or the epoch row H created). What is asserted is that nothing deadlocked, timed out or retried, and the end state.
                expect($run['running']['I'])->toBeTrue('the re-issue did not wait for the hook that holds the invitation or the epoch row');

                // PRE-EXISTING DEFECT (not part of R1-3A; recorded in docs/product/release-1.0-defect-permission-cache-epoch-first-use.md): with the epoch
                // row ABSENT, a transaction whose snapshot began before another transaction created the row cannot read it after its own
                // insert-or-ignore, and PermissionCacheEpoch::current() throws. Here T is that transaction and the hook is the one that created the
                // row. It is a RuntimeException in T alone (no lock-order problem: nothing deadlocked, timed out or retried), and it is the ONLY
                // tolerated deviation from `ok`, for T, with the epoch row missing.
                $known = fn (string $name, array $result) => $epoch === 'missing' && $name === 'T'
                    && ($result['outcome'] ?? '') === 'error:RuntimeException' && ($result['detail'] ?? '') === 'The permission cache epoch could not be created.';

                foreach ($run['results'] as $name => $result) {
                    if ($known($name, $result)) {
                        expect($result['transactions'])->toBeLessThanOrEqual(1);

                        continue;
                    }
                    oicAssertNoVictim($name, $result);
                    expect($result['outcome'])->toBe('ok', "worker {$name}: ".($result['detail'] ?? ''));
                }

                expect(DB::table('tickets')->count())->toBe($known('T', $run['results']['T']) ? 0 : 1)
                    ->and(DB::table('organization_invitations')->where('email', 'b1@oinv.test')->whereNull('revoked_at')->whereNull('accepted_at')->count())->toBe(1)
                    ->and(oicRow($old)->revoked_at)->not->toBeNull();
            } finally {
                DB::table('cache')->delete();
                oicTeardown($f);
            }
        });
    }
}

// A user's role assignment never reads the permission mapping: no cache statement at all inside its transaction, whatever the cache state.
it('a user role change on a cold database cache touches no cache table inside its transaction, and locks the actor and the invitations before its first role write', function () {
    $f = oicFixture();

    try {
        oicInvite($f['org'], $f['top'], 'b1@oinv.test');
        oicColdCache(true);

        $result = oicResult(oicStart(['op' => 'sync_user_roles', 'target' => $f['top']->id, 'roles' => [], 'actor' => $f['admin']->id], ['CACHE_STORE' => 'database']));
        oicAssertNoVictim('H', $result);
        expect($result['outcome'])->toBe('ok', (string) ($result['detail'] ?? ''));

        $inTransaction = collect($result['statements'])->filter(fn ($s) => $s['transactional'])->values();
        $roleWrite = $inTransaction->search(fn ($s) => preg_match(OIC_ROLE_DELETE, $s['sql']) === 1);
        $locks = $inTransaction->map(fn ($s) => $s['sql'])->values();
        $actor = $locks->search(fn ($q) => preg_match(OIC_USER_SHARE, $q) === 1);
        $invitations = $locks->search(fn ($q) => preg_match(OIC_INV_SWEEP, $q) === 1);

        expect($inTransaction->where('cache', true)->count())->toBe(0, 'a user role change touched the cache tables inside its transaction')
            ->and($roleWrite)->not->toBeFalse()->and($actor)->not->toBeFalse()->and($invitations)->not->toBeFalse()
            ->and($actor)->toBeLessThan($invitations)->and($invitations)->toBeLessThan($roleWrite);
    } finally {
        DB::table('cache')->delete();
        oicTeardown($f);
    }
});

// ── The permission-cache statement stream of a guard-class hook, from the real statements ────────────────────────────────────────────────

foreach (['role permissions', 'built-in synchronization creating a permission'] as $kind) {
    foreach (['present', 'missing'] as $epoch) {
        it("a guard-class hook ({$kind}) on a cold database cache: the epoch is advanced before the invitations are locked and every later cache statement is on a private key, epoch row {$epoch}", function () use ($kind, $epoch) {
            $f = oicFixture();

            try {
                [$h] = oicHookOperation($f, str_starts_with($kind, 'built-in') ? 'built-in' : 'role permissions');
                oicInvite($f['org'], $f['r1'], 'b1@oinv.test');
                oicColdCache($epoch === 'present');
                $keys = oicCacheKeys();
                $before = DB::table('cache')->where('key', $keys['epoch'])->value('value');

                $result = oicResult(oicStart($h, ['CACHE_STORE' => 'database']));
                oicAssertNoVictim('H', $result);
                expect($result['outcome'])->toBe('ok', (string) ($result['detail'] ?? ''));

                $inTransaction = collect($result['statements'])->filter(fn ($s) => $s['transactional'])->values();
                if (getenv('OIC_DUMP')) {
                    fwrite(STDERR, "\n--- {$kind} / {$epoch}\n".$inTransaction->map(fn ($s) => substr($s['sql'], 0, 110).($s['cache'] ? '  '.json_encode($s['keys']) : ''))->implode("\n")."\n");
                }
                $firstCache = $inTransaction->search(fn ($s) => $s['cache']);
                $firstInvitationLock = $inTransaction->search(fn ($s) => preg_match(OIC_INV_SWEEP, $s['sql']) === 1);
                $firstMutation = $inTransaction->search(fn ($s) => preg_match('/^(insert|delete|update) (into |from )?`(role_has_permissions|permissions)`/', $s['sql']) === 1);

                // R4: the very first cache statement of the transaction is the epoch advance, and it precedes the invitation locks.
                expect($firstCache)->not->toBeFalse()->and($firstInvitationLock)->not->toBeFalse()->and($firstMutation)->not->toBeFalse()
                    ->and(preg_match(OIC_EPOCH_ADVANCE, $inTransaction[$firstCache]['sql']))->toBe(1)
                    ->and($inTransaction[$firstCache]['keys'])->toBe([$keys['epoch']])
                    ->and($firstCache)->toBeLessThan($firstInvitationLock)->and($firstInvitationLock)->toBeLessThan($firstMutation);

                // Every cache statement inside the transaction touches only: the epoch row (this transaction's own upserts and reads), the unsuffixed
                // legacy key (the library's delete), or a key suffixed with an epoch OTHER than the one active when the run began: this
                // transaction's private one, which no other transaction can name before this one commits.
                $cacheStatements = $inTransaction->where('cache', true);
                foreach ($cacheStatements as $statement) {
                    foreach ($statement['keys'] as $key) {
                        $private = str_starts_with($key, $keys['base'].':') && ($before === null || ! str_ends_with($key, ':'.$before));
                        expect(in_array($key, [$keys['epoch'], $keys['base'], $keys['flexible']], true) || $private)->toBeTrue("a cache statement touched a shared key: {$statement['sql']} [{$key}]");
                    }
                }

                // The copy this transaction loaded and stored is under the private key: a read, then a store, both on it.
                expect($cacheStatements->filter(fn ($s) => str_starts_with($s['sql'], 'select * from `cache`'))->pluck('keys')->flatten()->unique()->count())->toBe(1);

            } finally {
                DB::table('cache')->delete();
                oicTeardown($f);
            }
        });
    }
}
