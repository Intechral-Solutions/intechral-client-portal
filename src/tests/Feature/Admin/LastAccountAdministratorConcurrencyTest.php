<?php

use App\Models\User;
use App\Services\AccountAdministrators;
use App\Services\AccountLifecycleService;
use App\Services\BuiltInRoleSynchronizer;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Support/committed_installation.php';

/*
 * Release 0.9 (H3, R0.9-1C): the last-active-administrator protection holds under concurrent changes, on real MariaDB.
 *
 * Two account administrators, A and B, each try at the same instant to remove the OTHER: by suspension, by a role
 * change, or by stripping the permissions of the role the other holds them through. Every guarded mutation first locks
 * the `users.manage` permission row, so they run one at a time and the second sees the first's committed result:
 * exactly one succeeds, the other is refused, and one active administrator always remains. Each worker holds the lock
 * for HOLD_MS, and the test checks that the second acquired it only after the first released it, so the workers
 * genuinely contended rather than happening to run one after the other.
 *
 * Like the other concurrency tests it ends RefreshDatabase's transaction so worker processes can see its rows. It
 * commits only what it needs (the two user-administration permissions, two uniquely named roles, two users) and
 * deletes all of it, and the activity rows its suspensions wrote, in a finally block. It refuses to run if those
 * permissions are already committed in the testing database, so it can never delete rows it did not create.
 */

const ACCOUNT_RACE_HOLD_MS = 600;

/**
 * @param  list<array<string, mixed>>  $operations  one per worker
 * @return list<array{outcome: string, lockedAt: float|null}>
 */
function runAccountAdminWorkers(array $operations): array
{
    $connection = config('database.connections.'.config('database.default'));
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => $connection['host'],
        'DB_PORT' => $connection['port'],
        'DB_DATABASE' => $connection['database'],
        'DB_USERNAME' => $connection['username'],
        'DB_PASSWORD' => $connection['password'],
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        // Suspension signs the user out everywhere, which refuses any other session driver (R0.9-1C finding L-2).
        'SESSION_DRIVER' => 'database',
        'QUEUE_CONNECTION' => 'sync',
    ];
    $startAt = microtime(true) + 2.5;

    $processes = array_map(function (array $operation) use ($env, $startAt) {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/account_admin_worker.php'), (string) $startAt, (string) ACCOUNT_RACE_HOLD_MS, json_encode($operation)],
            base_path(),
            $env,
        );
        $process->setTimeout(120);
        $process->start();

        return $process;
    }, $operations);

    return array_map(function (Process $process) {
        $process->wait();
        $decoded = json_decode(trim($process->getOutput()), true);

        return is_array($decoded) ? $decoded : ['outcome' => 'worker failed: '.$process->getErrorOutput().$process->getOutput(), 'lockedAt' => null];
    }, $processes);
}

/**
 * Two committed account administrators, A through role A and B through role B, and the cleanup that removes every row
 * this creates. Returns [A, B, roleA, roleB, cleanup].
 */
function committedAdministratorPair(): array
{
    DB::commit(); // end the RefreshDatabase transaction so other processes see our rows

    if (Permission::whereIn('name', ['users.view', 'users.manage'])->exists()) {
        throw new RuntimeException('The testing database already has committed user-administration permissions; refusing to run.');
    }

    $suffix = uniqid();
    $permissions = [
        Permission::create(['name' => 'users.view', 'guard_name' => 'web']),
        Permission::create(['name' => 'users.manage', 'guard_name' => 'web']),
    ];
    $roleA = Role::create(['name' => "race-admin-a-{$suffix}", 'guard_name' => 'web'])->givePermissionTo($permissions);
    $roleB = Role::create(['name' => "race-admin-b-{$suffix}", 'guard_name' => 'web'])->givePermissionTo($permissions);
    $a = User::factory()->create(['name' => 'Race Admin A']);
    $b = User::factory()->create(['name' => 'Race Admin B']);
    $a->assignRole($roleA);
    $b->assignRole($roleB);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $cleanup = function () use ($a, $b, $roleA, $roleB, $permissions) {
        $ids = [$a->id, $b->id];
        DB::table('activity_log')
            ->where(fn ($q) => $q->where('causer_type', User::class)->whereIn('causer_id', $ids))
            ->orWhere(fn ($q) => $q->where('subject_type', User::class)->whereIn('subject_id', $ids))
            ->delete();
        User::whereKey($ids)->get()->each->delete(); // Eloquent: their role rows go with them
        $roleA->delete();
        $roleB->delete();
        foreach ($permissions as $permission) {
            $permission->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        expect(DB::table('users')->whereIn('id', $ids)->exists())->toBeFalse()
            ->and(DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $ids)->exists())->toBeFalse()
            ->and(DB::table('roles')->whereIn('id', [$roleA->id, $roleB->id])->exists())->toBeFalse()
            ->and(DB::table('permissions')->whereIn('name', ['users.view', 'users.manage'])->exists())->toBeFalse();
    };

    return [$a, $b, $roleA, $roleB, $cleanup];
}

/**
 * Exactly one worker won and one was refused; they genuinely contended (the second asked for the lock while the first
 * held it) and were serialized (the second got the lock only after the first released it).
 */
function expectSerializedWinnerAndRefusal(array $results, string $refusal = 'refused'): void
{
    expect(collect($results)->pluck('outcome')->sort()->values()->all())->toBe(collect(['ok', $refusal])->sort()->values()->all(), json_encode($results));

    [$first, $second] = collect($results)->sortBy('lockedAt')->values()->all();
    $hold = ACCOUNT_RACE_HOLD_MS / 1000;
    expect($first['lockedAt'])->toBeFloat()
        ->and($second['lockedAt'])->toBeFloat()
        ->and($first['outcome'])->toBe('ok', 'the first to take the lock wins')
        ->and($second['requestedAt'])->toBeLessThan($first['lockedAt'] + $hold, 'the second asked while the first held the lock')
        ->and($second['lockedAt'])->toBeGreaterThanOrEqual($first['lockedAt'] + $hold * 0.9, 'the second waited for the first');
}

it('lets only one of two administrators suspend the other when both try at once', function () {
    [$a, $b, , , $cleanup] = committedAdministratorPair();

    try {
        $results = runAccountAdminWorkers([
            ['op' => 'suspend', 'actor' => $a->id, 'target' => $b->id],
            ['op' => 'suspend', 'actor' => $b->id, 'target' => $a->id],
        ]);

        expectSerializedWinnerAndRefusal($results);
        expect(app(AccountAdministrators::class)->ids()->count())->toBe(1)
            ->and(User::whereKey([$a->id, $b->id])->whereNotNull('disabled_at')->count())->toBe(1)
            ->and(DB::table('activity_log')->where('description', AccountLifecycleService::SUSPENDED)->whereIn('subject_id', [$a->id, $b->id])->count())->toBe(1);
    } finally {
        $cleanup();
    }
});

it('lets only one of two administrators remove the other\'s role when both try at once', function () {
    [$a, $b, , , $cleanup] = committedAdministratorPair();

    try {
        $results = runAccountAdminWorkers([
            ['op' => 'sync_user_roles', 'target' => $b->id, 'roles' => []],
            ['op' => 'sync_user_roles', 'target' => $a->id, 'roles' => []],
        ]);

        expectSerializedWinnerAndRefusal($results);
        expect(app(AccountAdministrators::class)->ids()->count())->toBe(1)
            ->and(DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', [$a->id, $b->id])->count())->toBe(1);
    } finally {
        $cleanup();
    }
});

it('lets only one of a suspension and a demotion through when they race to remove both administrators', function () {
    [$a, $b, , , $cleanup] = committedAdministratorPair();

    try {
        $results = runAccountAdminWorkers([
            ['op' => 'suspend', 'actor' => $a->id, 'target' => $b->id],
            ['op' => 'sync_user_roles', 'target' => $a->id, 'roles' => []],
        ]);

        expectSerializedWinnerAndRefusal($results);
        expect(app(AccountAdministrators::class)->ids()->count())->toBe(1);
    } finally {
        $cleanup();
    }
});

it('lets only one of two role-permission edits through when each would demote one of the last two administrators', function () {
    [$a, $b, $roleA, $roleB, $cleanup] = committedAdministratorPair();

    try {
        $results = runAccountAdminWorkers([
            ['op' => 'sync_role_permissions', 'role' => $roleA->id, 'permissions' => ['users.view']],
            ['op' => 'sync_role_permissions', 'role' => $roleB->id, 'permissions' => ['users.view']],
        ]);

        expectSerializedWinnerAndRefusal($results);
        expect(app(AccountAdministrators::class)->ids()->count())->toBe(1);
    } finally {
        $cleanup();
    }
});

/*
 * R0.9-1D: the built-in role synchronization and the first-operator bootstrap take the same lock as every other grant
 * mutation, so they too run one at a time against the last-administrator guard and against each other.
 */

it('serializes a reviewed synchronization against a demotion that races to remove both administrators', function () {
    $cleanup = committedSynchronizedInstallation();
    $x = User::factory()->create(['name' => 'Race Admin X'])->assignRole('user');
    $y = User::factory()->create(['name' => 'Race Admin Y']);
    $y->assignRole(Role::create(['name' => 'race-admin-y', 'guard_name' => 'web'])->givePermissionTo(['users.view', 'users.manage']));
    Role::findByName('user', 'web')->givePermissionTo(['users.view', 'users.manage']); // drift: X is an administrator through `user`
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    try {
        $plan = app(BuiltInRoleSynchronizer::class)->plan();
        expect($plan->administratorsBefore)->toBe([$x->id, $y->id])
            ->and($plan->administratorsAfter)->toBe([$y->id], 'the reviewed plan removes only X');

        $results = runAccountAdminWorkers([
            ['op' => 'sync_builtin_roles', 'fingerprint' => $plan->fingerprint(), 'allowRevocations' => true],
            ['op' => 'sync_user_roles', 'target' => $y->id, 'roles' => []],
        ]);

        // Whoever takes the lock first wins and the other never sees the state it was planned against: the demotion is
        // refused if the synchronization went first, the synchronization is stale if the demotion did.
        $outcomes = collect($results)->pluck('outcome')->sort()->values()->all();
        expect($outcomes)->toBeIn([['ok', 'refused'], ['ok', 'stale']], json_encode($results));
        [$first, $second] = collect($results)->sortBy('lockedAt')->values()->all();
        $hold = ACCOUNT_RACE_HOLD_MS / 1000;
        expect($first['outcome'])->toBe('ok')
            ->and($second['requestedAt'])->toBeLessThan($first['lockedAt'] + $hold, 'the second asked while the first held the lock')
            ->and($second['lockedAt'])->toBeGreaterThanOrEqual($first['lockedAt'] + $hold * 0.9, 'the second waited for the first')
            ->and(app(AccountAdministrators::class)->ids()->count())->toBe(1, 'one active administrator always remains');
    } finally {
        $cleanup([$x->id, $y->id]);
    }
});

it('lets one of two identical reviewed synchronizations apply and refuses the other as stale', function () {
    $cleanup = committedSynchronizedInstallation();
    Role::findByName('user', 'web')->permissions()->detach(Permission::findByName('tickets.view')->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    try {
        $plan = app(BuiltInRoleSynchronizer::class)->plan();
        $results = runAccountAdminWorkers([
            ['op' => 'sync_builtin_roles', 'fingerprint' => $plan->fingerprint()],
            ['op' => 'sync_builtin_roles', 'fingerprint' => $plan->fingerprint()],
        ]);

        expectSerializedWinnerAndRefusal($results, 'stale');
        expect(app(BuiltInRoleSynchronizer::class)->plan()->isEmpty())->toBeTrue()
            ->and(DB::table('activity_log')->where('description', 'synchronized built-in roles')->count())->toBe(1, 'one committed outcome, one audit entry');
    } finally {
        $cleanup();
    }
});

it('creates exactly one operator when two first-operator bootstraps race', function () {
    $cleanup = committedSynchronizedInstallation();
    $emails = ['first@example.com', 'second@example.com'];

    try {
        $results = runAccountAdminWorkers(array_map(
            fn ($email) => ['op' => 'bootstrap_operator', 'email' => $email, 'password' => 'Race-Bootstrap-Passw0rd!7'],
            $emails,
        ));

        expectSerializedWinnerAndRefusal($results);
        expect(User::whereIn('email', $emails)->count())->toBe(1)
            ->and(User::role('operator')->count())->toBe(1)
            ->and(app(AccountAdministrators::class)->ids()->count())->toBe(1)
            ->and(DB::table('activity_log')->where('description', 'bootstrapped first operator')->count())->toBe(1);
    } finally {
        $cleanup([], $emails);
    }
});
