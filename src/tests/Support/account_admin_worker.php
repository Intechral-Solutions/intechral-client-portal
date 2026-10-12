<?php

/*
 * Child process for LastAccountAdministratorConcurrencyTest. Bootstraps the application against the testing
 * database, waits for a shared start time so the workers really overlap, then runs ONE account-administration
 * mutation through the same service the admin controllers call, and prints its outcome as JSON.
 *
 * After the guard's first statement (the lock on the `users.manage` permission row) it records the time and HOLDS
 * for <holdMs>, so a second worker started at the same instant is still running when the first has the lock: with
 * the lock, the second waits and then sees the first's committed result; without it, both would read the same
 * "before" state. `requestedAt` (just before the mutation) and `lockedAt` let the test prove the second asked for
 * the lock while the first held it, and got it only afterwards.
 *
 * Operations: suspend, sync_user_roles, sync_role_permissions (R0.9-1C), sync_builtin_roles, bootstrap_operator (R0.9-1D).
 *
 * Usage: php account_admin_worker.php <startAtMicrotime> <holdMs> <operation-json>
 */

use App\Exceptions\LastAccountAdministratorException;
use App\Exceptions\OperatorBootstrapRefused;
use App\Exceptions\RoleSyncRefused;
use App\Models\User;
use App\Services\AccessGrants;
use App\Services\AccountLifecycleService;
use App\Services\BuiltInRoleSynchronizer;
use App\Services\OperatorBootstrap;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$startAt = (float) ($argv[1] ?? 0);
$holdMs = (int) ($argv[2] ?? 0);
$operation = json_decode($argv[3] ?? '{}', true, flags: JSON_THROW_ON_ERROR);

$lockedAt = null;
DB::listen(function (QueryExecuted $query) use (&$lockedAt, $holdMs) {
    // The guard's first statement reads the `users.manage` permission row (FOR UPDATE).
    if ($lockedAt === null && str_contains($query->sql, '`permissions`') && in_array('users.manage', $query->bindings, true)) {
        $lockedAt = microtime(true);
        usleep($holdMs * 1000);
    }
});

while (microtime(true) < $startAt) {
    usleep(100);
}

$requestedAt = microtime(true);

try {
    match ($operation['op']) {
        'suspend' => $app->make(AccountLifecycleService::class)
            ->suspend(User::findOrFail($operation['target']), User::findOrFail($operation['actor']), 'Concurrency test'),
        'sync_user_roles' => $app->make(AccessGrants::class)
            ->syncUserRoles(User::findOrFail($operation['target']), $operation['roles']),
        'sync_role_permissions' => $app->make(AccessGrants::class)
            ->syncRolePermissions(Role::findOrFail($operation['role']), $operation['permissions']),
        // R0.9-1D: the built-in role synchronization applying a plan reviewed earlier, and the first-operator bootstrap.
        'sync_builtin_roles' => $app->make(BuiltInRoleSynchronizer::class)
            ->apply($operation['fingerprint'], $operation['allowRevocations'] ?? false),
        'bootstrap_operator' => $app->make(OperatorBootstrap::class)->provision([
            'name' => 'Race Operator',
            'email' => $operation['email'],
            'password' => $operation['password'],
            'password_confirmation' => $operation['password'],
        ]),
    };
    $outcome = 'ok';
} catch (LastAccountAdministratorException|OperatorBootstrapRefused) {
    $outcome = 'refused';
} catch (RoleSyncRefused $e) {
    $outcome = str_contains($e->getMessage(), 'stale') ? 'stale' : 'error: '.$e->getMessage();
} catch (Throwable $e) {
    $outcome = 'error: '.get_class($e).': '.$e->getMessage();
}

echo json_encode(['outcome' => $outcome, 'requestedAt' => $requestedAt, 'lockedAt' => $lockedAt, 'finishedAt' => microtime(true)]);
