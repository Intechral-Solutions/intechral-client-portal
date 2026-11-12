<?php

/*
 * Child process for OrganizationInvitationConcurrencyTest (R1-3A). Bootstraps the application against the testing database as an
 * independent process with its own database connection, then runs ONE invitation-related operation through the same service the
 * application uses, and prints its outcome as JSON. Two such processes are two real MariaDB connections.
 *
 * It can HOLD: after the n-th query whose SQL matches a pattern has executed (so the lock that query took is held), the worker writes
 * `<dir>/<tag>.held`, then waits until `<dir>/<tag>.release` exists (a file, not stdin: the parent may be blocked in a database call of its
 * own) before it continues. The test uses that to freeze one transaction at an exact point and start the conflicting one, which makes
 * every interleaving deterministic instead of timing-dependent.
 *
 * Every worker records the statements of its own transaction that matter for ordering (locking reads and writes), and which of them
 * touched the permission cache tables, so the test can verify lock order and "no cache statement inside the acceptance transaction" from the
 * real statement stream, not from reading the code.
 *
 * Usage: php organization_invitation_worker.php '<operation-json>'
 *
 *   operation: { op, hold?: {pattern, nth?, dir, tag}, ... }
 *   ops: issue {organization, email, actor} | revoke {organization, invitation, actor} | accept {token, name, password}
 *        suspend {target, actor} | reactivate {target, actor} | sync_user_roles {target, roles, actor}
 *        sync_role_permissions {role, permissions, actor} | sync_builtin_roles {fingerprint}
 *        add_member {organization, user, role, actor} | approve_member {organization, user, actor} | create_ticket {user, organization}
 */

use App\Exceptions\LastAccountAdministratorException;
use App\Exceptions\OrganizationInvitationException;
use App\Exceptions\OrganizationMembershipException;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccessGrants;
use App\Services\AccountLifecycleService;
use App\Services\BuiltInRoleSynchronizer;
use App\Services\InvitationAcceptance;
use App\Services\OrganizationInvitations;
use App\Services\OrganizationMembership;
use App\Services\TicketService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$operation = json_decode($argv[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$hold = $operation['hold'] ?? null;

// A short lock wait, so a genuine hang fails the test within seconds (a lock wait timeout is an error outcome, never a pass); long enough that a blocked worker outlives the parent's observation.
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');

$statements = [];
$heldAt = null;
$heldCount = 0;
$transactionLevelAtStart = DB::transactionLevel();

DB::listen(function (QueryExecuted $query) use (&$statements, &$heldAt, &$heldCount, $hold) {
    $sql = $query->sql;
    $table = str_contains($sql, 'cache`') || str_contains($sql, '`cache') ? 'cache' : null;

    if (preg_match('/\b(for update|lock in share mode)\b|^\s*(insert|update|delete)\b/i', $sql) === 1 || $table !== null) {
        // For a statement on the cache tables, also the cache KEYS it names (the short permission-cache strings among its bindings, never the
        // stored value): they say whether it touched the shared copy key, the epoch row, or a key under this transaction's own private epoch.
        $keys = $table !== null ? array_values(array_filter($query->bindings, fn ($b) => is_string($b) && strlen($b) < 160 && str_contains($b, 'permission'))) : [];
        $statements[] = ['sql' => preg_replace('/\s+/', ' ', $sql), 'cache' => $table !== null, 'keys' => $keys, 'transactional' => DB::transactionLevel() > 0, 'at' => microtime(true)];
    }

    if ($hold !== null && $heldAt === null && preg_match($hold['pattern'], $sql) === 1 && ++$heldCount >= ($hold['nth'] ?? 1)) {
        $heldAt = microtime(true);
        file_put_contents($hold['dir'].'/'.$hold['tag'].'.held', (string) $heldAt);

        $deadline = microtime(true) + 40;
        while (! is_file($hold['dir'].'/'.$hold['tag'].'.release') && microtime(true) < $deadline) {
            usleep(5_000);
        }
    }
});

// The worker's own announcement, BEFORE it sends a named statement: "I am about to ask for this lock". The test uses it to know the second
// transaction has really reached its conflicting statement before it judges whether it is blocked.
$signal = $operation['signal'] ?? null;
$signalled = false;
DB::beforeExecuting(function (string $query) use ($signal, &$signalled) {
    if ($signal !== null && ! $signalled && preg_match($signal['pattern'], $query) === 1) {
        $signalled = true;
        file_put_contents($signal['dir'].'/'.$signal['tag'].'.requesting', (string) microtime(true));
    }
});

// How many times the outermost transaction began, and how many were rolled back. A transaction that Laravel RETRIES after a deadlock or a
// lock wait timeout begins again, so an operation that reports `ok` after two beginnings was a deadlock victim that retried silently:
// the test must see that, not just the final outcome.
$begins = 0;
$rollbacks = 0;
Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$begins) {
    if ($event->connection->transactionLevel() === 1) {
        $begins++;
    }
});
Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event) use (&$rollbacks) {
    if ($event->connection->transactionLevel() === 0) {
        $rollbacks++;
    }
});

$requestedAt = microtime(true);
$detail = null;

try {
    $result = match ($operation['op']) {
        'issue' => (function () use ($app, $operation) {
            $invitation = $app->make(OrganizationInvitations::class)->issue(
                Organization::withoutGlobalScopes()->findOrFail($operation['organization']),
                $operation['email'],
                User::findOrFail($operation['actor']),
            );

            return ['invitation' => $invitation->id];
        })(),
        'revoke' => ['revoked' => $app->make(OrganizationInvitations::class)->revoke(
            Organization::withoutGlobalScopes()->findOrFail($operation['organization']),
            $operation['invitation'],
            User::findOrFail($operation['actor']),
        )],
        'accept' => (function () use ($app, $operation) {
            // The slow step first, outside the locks, exactly as a caller must.
            $hash = Hash::make($operation['password']);
            $user = $app->make(OrganizationInvitations::class)->accept($operation['token'], InvitationAcceptance::withPassword($operation['name'], $hash));

            return ['user' => $user->id];
        })(),
        'suspend' => ['suspended' => $app->make(AccountLifecycleService::class)->suspend(User::findOrFail($operation['target']), User::findOrFail($operation['actor']), 'Concurrency test')],
        'reactivate' => ['reactivated' => $app->make(AccountLifecycleService::class)->reactivate(User::findOrFail($operation['target']), User::findOrFail($operation['actor']))],
        'sync_user_roles' => $app->make(AccessGrants::class)->syncUserRoles(User::findOrFail($operation['target']), $operation['roles'], User::findOrFail($operation['actor'])),
        'sync_role_permissions' => $app->make(AccessGrants::class)->syncRolePermissions(Role::findOrFail($operation['role']), $operation['permissions'], User::findOrFail($operation['actor'])),
        'sync_builtin_roles' => $app->make(BuiltInRoleSynchronizer::class)->apply($operation['fingerprint'], true, false) !== null,
        'add_member' => ['added' => $app->make(OrganizationMembership::class)->add(
            Organization::withoutGlobalScopes()->findOrFail($operation['organization']),
            User::findOrFail($operation['user']),
            $operation['role'],
            User::findOrFail($operation['actor']),
        ) ?? true],
        'approve_member' => ['approved' => $app->make(OrganizationMembership::class)->approve(
            Organization::withoutGlobalScopes()->findOrFail($operation['organization']),
            User::findOrFail($operation['user']),
            User::findOrFail($operation['actor']),
        )],
        'create_ticket' => ['ticket' => $app->make(TicketService::class)->create(
            User::findOrFail($operation['user']),
            ['title' => 'Race ticket', 'description' => 'Created by the concurrency worker.', 'category' => 'General', 'priority' => 'low'],
            [],
            $operation['organization'] ?? null,
        )->id],
    };
    $outcome = 'ok';
} catch (OrganizationInvitationException $e) {
    $outcome = 'refused:'.$e->reason;
    $detail = $e->detail;
} catch (OrganizationMembershipException $e) {
    $outcome = 'membership:'.$e->reason;
    $detail = $e->getMessage();
} catch (AuthorizationException) {
    $outcome = 'unauthorized';
} catch (LastAccountAdministratorException) {
    $outcome = 'last-administrator';
} catch (QueryException $e) {
    $outcome = 'database:'.(int) ($e->errorInfo[1] ?? 0);
    $detail = $e->getMessage();
} catch (Throwable $e) {
    $outcome = 'error:'.get_class($e);
    $detail = $e->getMessage();
}

echo json_encode([
    'outcome' => $outcome,
    'detail' => $detail,
    'result' => is_array($result ?? null) ? $result : null,
    'requestedAt' => $requestedAt,
    'heldAt' => $heldAt,
    'finishedAt' => microtime(true),
    'transactions' => $begins,
    'rollbacks' => $rollbacks,
    'statements' => $statements,
]);
