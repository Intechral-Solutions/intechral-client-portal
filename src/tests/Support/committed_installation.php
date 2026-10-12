<?php

use App\Models\User;
use App\Services\BuiltInRoleSynchronizer;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A committed new installation after the first synchronization: every permission and both built-in roles, no users.
 * Refuses to run if the testing database already has committed permissions or roles. Returns the cleanup, which removes
 * what this and the workers created (users by id or email, the audit rows they wrote, then every role and permission).
 */
function committedSynchronizedInstallation(): Closure
{
    DB::commit(); // end the RefreshDatabase transaction so other processes see our rows
    DB::connection()->setTransactionManager(new DatabaseTransactionsManager); // transact as production does

    if (Permission::exists() || Role::exists()) {
        throw new RuntimeException('The testing database already has committed roles or permissions; refusing to run.');
    }

    $sync = app(BuiltInRoleSynchronizer::class);
    $sync->apply($sync->plan()->fingerprint(), audit: false);

    return function (array $userIds = [], array $emails = []) {
        DB::table('activity_log')
            ->whereIn('description', ['synchronized built-in roles', 'bootstrapped first operator'])
            ->orWhere(fn ($q) => $q->where('subject_type', User::class)->whereIn('subject_id', $userIds))
            ->delete();
        User::query()->whereKey($userIds)->orWhereIn('email', $emails)->get()->each->delete(); // Eloquent: their role rows go with them
        DB::table('role_has_permissions')->delete();
        DB::table('roles')->delete();
        DB::table('permissions')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        expect(DB::table('permissions')->count() + DB::table('roles')->count() + DB::table('model_has_roles')->count())->toBe(0);
    };
}
