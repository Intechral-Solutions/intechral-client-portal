<?php

namespace App\Services;

use App\Exceptions\BuiltInRoleException;
use App\Exceptions\LastAccountAdministratorException;
use App\Models\User;
use App\Shared\Permissions\BuiltInRoles;
use Closure;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The application's role and permission mutations, each run inside the last-account-administrator guard
 * (Release 0.9, H3): any of them can remove `users.view` or `users.manage` from the last active account
 * administrator. The admin controllers call these, so there is one guarded path per mutation. Activity logging
 * stays with the callers.
 *
 * Role changes also clear the shared permission cache once they are COMMITTED (R0.9-1C review finding M-1). The
 * cache holds which roles carry each permission. The permission library clears it inside the guarded transaction,
 * so a concurrent request could reload the old, still-committed mapping before the commit and keep it for the
 * cache lifetime. A user's own role assignments are not in that cache.
 *
 * Built-in roles (R0.9-1D, decision H4) are owned by the application: the methods that change a role refuse one with
 * a BuiltInRoleException. Their grants change only through {@see BuiltInRoleSynchronizer}, which runs its reviewed
 * plan through {@see guardedGrantChange()}. Assigning a user to a built-in role is ordinary user administration.
 */
final class AccessGrants
{
    public function __construct(private readonly AccountAdministrators $administrators) {}

    /**
     * @param  list<string>  $roles  role names
     *
     * @throws LastAccountAdministratorException
     */
    public function syncUserRoles(User $user, array $roles): void
    {
        $this->administrators->guard(fn () => $user->syncRoles($roles));
    }

    /**
     * @param  list<string>  $permissions  permission names
     *
     * @throws BuiltInRoleException
     * @throws LastAccountAdministratorException
     */
    public function syncRolePermissions(Role $role, array $permissions): void
    {
        $this->assertCustom($role);

        $this->administrators->guard(fn () => $role->syncPermissions($permissions));

        $this->forgetCachedPermissionsAfterCommit();
    }

    /**
     * Delete a role that nobody holds, and return how many users hold it (0 when it was deleted). Deleting a role
     * detaches it from its holders, so the "nobody holds it" check is made under the guard's lock, where no
     * concurrent role assignment can slip in between the check and the delete.
     *
     * @param  (callable(): void)|null  $beforeDelete  runs in the same transaction, just before the delete
     *
     * @throws BuiltInRoleException
     */
    public function deleteUnassignedRole(Role $role, ?callable $beforeDelete = null): int
    {
        $this->assertCustom($role);

        $holders = $this->administrators->guard(function () use ($role, $beforeDelete) {
            $holders = $role->users()->count();

            if ($holders === 0) {
                if ($beforeDelete !== null) {
                    $beforeDelete();
                }

                $role->delete();
            }

            return $holders;
        });

        if ($holders === 0) {
            $this->forgetCachedPermissionsAfterCommit();
        }

        return $holders;
    }

    /**
     * Refuse a built-in role. Callers that want the refusal before any other validation call it first.
     *
     * @throws BuiltInRoleException
     */
    public function assertCustom(Role $role): void
    {
        if (BuiltInRoles::isBuiltIn($role->name)) {
            throw new BuiltInRoleException($role->name);
        }
    }

    /**
     * Run a role or permission mutation that is not one of the methods above (the built-in role synchronization)
     * inside the last-account-administrator guard, and clear the shared permission cache once it is committed or
     * rolled back (finding M-1). The clear is registered at the start of the guarded transaction too, so a refusal
     * or failure inside it, which rolls back, also clears whatever the work reloaded from its own uncommitted rows.
     *
     * @template T
     *
     * @param  Closure(): T  $mutation
     * @return T
     *
     * @throws LastAccountAdministratorException
     */
    public function guardedGrantChange(Closure $mutation): mixed
    {
        $result = $this->administrators->guard(function () use ($mutation) {
            $this->forgetCachedPermissionsAfterCommit();

            return $mutation();
        });

        // Again, now that guard() has returned: when it was nested, an outer rollback does not run callbacks that a
        // committed inner transaction registered, but it does run the ones registered on the outer transaction.
        $this->forgetCachedPermissionsAfterCommit();

        return $result;
    }

    /**
     * Immediately when guard() was the outermost transaction (it has committed), otherwise when the caller's
     * transaction commits. If the caller's transaction rolls back instead, the cache is cleared then too, because
     * code inside it may have reloaded the cache from its own uncommitted rows. A refused change registers nothing:
     * nothing reloads the cache inside the guard, so it still matches what is committed.
     */
    private function forgetCachedPermissionsAfterCommit(): void
    {
        $forget = fn () => app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::afterCommit($forget);
        DB::afterRollBack($forget);
    }
}
