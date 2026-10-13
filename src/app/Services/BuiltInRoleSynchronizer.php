<?php

namespace App\Services;

use App\Exceptions\LastAccountAdministratorException;
use App\Exceptions\RoleSyncRefused;
use App\Models\User;
use App\Shared\Permissions\BuiltInRoles;
use App\Shared\Permissions\PermissionCatalogue;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Release 0.9 (R0.9-1D, decision H4): the deterministic, previewable, idempotent synchronization of the built-in roles
 * with the code that owns them ({@see BuiltInRoles}). It replaces `RoleSeeder` as the production mechanism.
 *
 * `plan()` compares the code with the stored state and writes nothing (it reads the tables directly, never through
 * the permission cache, which it would otherwise populate). `apply()` runs only an explicitly authorized plan:
 *
 *  - the caller names the reviewed plan by its fingerprint, and the plan is rebuilt under the last-administrator lock
 *    and refused if the stored state has since moved (a stale approval cannot apply a different plan);
 *  - revocations, the only destructive step, also need an explicit approval;
 *  - the work runs inside the account-administrator guard, so it can never leave the portal without an active
 *    administrator, and the shared permission cache is cleared after the commit (or rollback) as for every other
 *    role mutation (R0.9-1C finding M-1);
 *  - afterwards the stored state must equal the plan's prediction (nothing left to do, administrators as predicted),
 *    or the whole step rolls back.
 *
 * It creates missing permissions and built-in roles and changes the grants of the built-in roles. It never deletes a
 * role or a permission, never touches a custom role's grants or any user's role or direct-permission assignments, and
 * is never invoked by a request, a migration or boot. See docs/architecture/role-governance.md.
 */
final class BuiltInRoleSynchronizer
{
    /**
     * @param  array<string, list<string>>|null  $definitions  the role definitions; defaults to the code's own, and is
     *                                                         overridden only by tests
     */
    public function __construct(
        private readonly AccountAdministrators $administrators,
        private readonly AccessGrants $grants,
        private readonly ?array $definitions = null,
    ) {}

    /**
     * What synchronizing would do now. Reads only; writes nothing.
     *
     * @throws RoleSyncRefused when the definitions are malformed or the stored state is ambiguous
     */
    public function plan(): RoleSyncPlan
    {
        $definitions = $this->definitions ?? BuiltInRoles::definitions();
        foreach ($definitions as &$permissions) {
            sort($permissions);
        }
        unset($permissions);

        if (($problems = BuiltInRoles::problems($definitions)) !== []) {
            throw RoleSyncRefused::malformedDefinitions($problems);
        }

        $tables = config('permission.table_names');
        $guard = BuiltInRoles::GUARD;
        $storedPermissions = DB::table($tables['permissions'])->where('guard_name', $guard)->pluck('id', 'name')->all();
        $builtInRows = DB::table($tables['roles'])->whereIn('name', array_keys($definitions))->get(['id', 'name', 'guard_name']);

        foreach ($builtInRows as $row) {
            if ($row->guard_name !== $guard || ! array_key_exists($row->name, $definitions)) {
                throw RoleSyncRefused::ambiguous("a role named \"{$row->name}\" exists for guard \"{$row->guard_name}\", not as the built-in \"{$row->name}\" for guard \"{$guard}\".");
            }
        }

        $granted = $this->storedGrants($builtInRows->pluck('id')->all());
        $wantedPermissions = array_values(array_unique(array_merge(...array_values($definitions))));
        sort($wantedPermissions);

        $actions = [];
        foreach ($wantedPermissions as $permission) {
            if (! array_key_exists($permission, $storedPermissions)) {
                $actions[] = ['action' => RoleSyncPlan::CREATE_PERMISSION, 'permission' => $permission];
            }
        }

        $existing = $builtInRows->pluck('id', 'name')->all();
        ksort($definitions);
        foreach (array_keys($definitions) as $role) {
            if (! array_key_exists($role, $existing)) {
                $actions[] = ['action' => RoleSyncPlan::CREATE_ROLE, 'role' => $role];
            }
        }
        foreach ($definitions as $role => $wanted) {
            $current = array_key_exists($role, $existing) ? ($granted[$existing[$role]] ?? []) : [];
            foreach (array_diff($wanted, $current) as $permission) {
                $actions[] = ['action' => RoleSyncPlan::GRANT, 'role' => $role, 'permission' => $permission];
            }
        }
        foreach ($definitions as $role => $wanted) {
            $current = array_key_exists($role, $existing) ? ($granted[$existing[$role]] ?? []) : [];
            sort($current);
            foreach (array_diff($current, $wanted) as $permission) {
                $actions[] = ['action' => RoleSyncPlan::REVOKE, 'role' => $role, 'permission' => $permission];
            }
        }

        $stale = array_values(array_diff(array_keys($storedPermissions), $wantedPermissions));
        sort($stale);

        return new RoleSyncPlan(
            $actions,
            $stale,
            $this->administratorIds($actions, false),
            $this->administratorIds($actions, true),
        );
    }

    /**
     * Apply the plan the reviewer saw, if the stored state still needs exactly that plan.
     *
     * @param  string  $fingerprint  {@see RoleSyncPlan::fingerprint()} of the reviewed plan
     * @param  bool  $allowRevocations  the explicit approval a plan with revocations needs
     * @param  bool  $audit  write the activity-log entry; only the disposable local/testing seeder turns it off
     * @return RoleSyncPlan the plan that was applied (empty when there was nothing to do)
     *
     * @throws RoleSyncRefused
     * @throws LastAccountAdministratorException
     */
    public function apply(string $fingerprint, bool $allowRevocations = false, bool $audit = true): RoleSyncPlan
    {
        return $this->grants->guardedGrantChange(function () use ($fingerprint, $allowRevocations, $audit) {
            $plan = $this->plan();

            if (! hash_equals($plan->fingerprint(), $fingerprint)) {
                throw RoleSyncRefused::stalePlan($fingerprint);
            }

            if ($plan->hasRevocations() && ! $allowRevocations) {
                throw RoleSyncRefused::revocationsNotApproved(count($plan->revocations()));
            }

            if ($plan->isEmpty()) {
                return $plan;
            }

            $this->execute($plan);

            $after = $this->plan();
            if (! $after->isEmpty()) {
                throw RoleSyncRefused::unexpectedOutcome('the stored state still differs from the definitions');
            }
            if ($this->administrators->ids()->all() !== $plan->administratorsAfter) {
                throw RoleSyncRefused::unexpectedOutcome('the active administrators differ from the plan');
            }

            if ($audit) {
                activity()
                    ->withProperties([
                        'fingerprint' => $plan->fingerprint(),
                        'actions' => array_map($plan->describe(...), $plan->actions),
                        'revocations' => count($plan->revocations()),
                        'administrators_before' => $plan->administratorsBefore,
                        'administrators_after' => $plan->administratorsAfter,
                    ])
                    ->log('synchronized built-in roles');
            }

            return $plan;
        });
    }

    private function execute(RoleSyncPlan $plan): void
    {
        $tables = config('permission.table_names');
        $pivot = config('permission.column_names.role_pivot_key') ?: 'role_id';
        $permissionPivot = config('permission.column_names.permission_pivot_key') ?: 'permission_id';
        $guard = BuiltInRoles::GUARD;

        foreach ($plan->actions as $action) {
            if ($action['action'] === RoleSyncPlan::CREATE_PERMISSION) {
                Permission::create(['name' => $action['permission'], 'guard_name' => $guard]);
            } elseif ($action['action'] === RoleSyncPlan::CREATE_ROLE) {
                Role::create(['name' => $action['role'], 'guard_name' => $guard]);
            }
        }

        $roleIds = DB::table($tables['roles'])->where('guard_name', $guard)->pluck('id', 'name')->all();
        $permissionIds = DB::table($tables['permissions'])->where('guard_name', $guard)->pluck('id', 'name')->all();

        foreach ($plan->actions as $action) {
            if ($action['action'] === RoleSyncPlan::GRANT) {
                DB::table($tables['role_has_permissions'])->insert([
                    $pivot => $roleIds[$action['role']],
                    $permissionPivot => $permissionIds[$action['permission']],
                ]);
            } elseif ($action['action'] === RoleSyncPlan::REVOKE) {
                DB::table($tables['role_has_permissions'])
                    ->where($pivot, $roleIds[$action['role']])
                    ->where($permissionPivot, $permissionIds[$action['permission']])
                    ->delete();
            }
        }

        // The grants and revocations above are raw pivot writes the permission library never sees, so nothing has moved
        // the shared permission cache's epoch for them (R0.9-1E). Advance it now, inside this transaction and after the
        // last write: it commits or rolls back with them, and a plan that only revokes is covered like any other. Any
        // load of the cache made between an earlier advance (a created permission or role) and here is stored under
        // an epoch this replaces, so it can never serve the finished state.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Permission names granted to each of the given roles, by role id.
     *
     * @param  list<int>  $roleIds
     * @return array<int, list<string>>
     */
    private function storedGrants(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        $tables = config('permission.table_names');
        $granted = [];

        $rows = DB::table($tables['role_has_permissions'].' as rhp')
            ->join($tables['permissions'].' as p', 'p.id', '=', 'rhp.permission_id')
            ->whereIn('rhp.role_id', $roleIds)
            ->get(['rhp.role_id', 'p.name', 'p.guard_name']);

        foreach ($rows as $row) {
            if ($row->guard_name !== BuiltInRoles::GUARD) {
                throw RoleSyncRefused::ambiguous("a built-in role holds the permission \"{$row->name}\" of guard \"{$row->guard_name}\".");
            }
            $granted[(int) $row->role_id][] = $row->name;
        }

        return $granted;
    }

    /**
     * Ids of the active account administrators (an active user holding users.view and users.manage, directly or
     * through a role), now or once $actions are applied. Mirrors {@see AccountAdministrators::ids()} over in-memory
     * grants so the plan can show the effect on administrators without writing anything.
     *
     * @param  list<array<string, string>>  $actions
     * @return list<int>
     */
    private function administratorIds(array $actions, bool $afterActions): array
    {
        $tables = config('permission.table_names');
        $modelKey = config('permission.column_names.model_morph_key');
        $morphClass = (new User)->getMorphClass();
        $guard = BuiltInRoles::GUARD;

        /** @var array<string, array<string, true>> $roleGrants */
        $roleGrants = [];
        foreach (DB::table($tables['roles'])->where('guard_name', $guard)->pluck('id', 'name') as $name => $id) {
            $roleGrants[$name] = [];
        }
        $rows = DB::table($tables['role_has_permissions'].' as rhp')
            ->join($tables['roles'].' as r', 'r.id', '=', 'rhp.role_id')
            ->join($tables['permissions'].' as p', 'p.id', '=', 'rhp.permission_id')
            ->where('r.guard_name', $guard)->where('p.guard_name', $guard)
            ->get(['r.name as role', 'p.name as permission']);
        foreach ($rows as $row) {
            $roleGrants[$row->role][$row->permission] = true;
        }

        if ($afterActions) {
            foreach ($actions as $action) {
                match ($action['action']) {
                    RoleSyncPlan::CREATE_ROLE => $roleGrants[$action['role']] ??= [],
                    RoleSyncPlan::GRANT => $roleGrants[$action['role']][$action['permission']] = true,
                    RoleSyncPlan::REVOKE => $roleGrants[$action['role']] = array_diff_key($roleGrants[$action['role']], [$action['permission'] => true]),
                    default => null,
                };
            }
        }

        $held = [];
        $viaRoles = DB::table($tables['model_has_roles'].' as mhr')
            ->join($tables['roles'].' as r', 'r.id', '=', 'mhr.role_id')
            ->join('users as u', 'u.id', '=', "mhr.{$modelKey}")
            ->whereNull('u.disabled_at')->where('mhr.model_type', $morphClass)->where('r.guard_name', $guard)
            ->get(['u.id as user', 'r.name as role']);
        foreach ($viaRoles as $row) {
            $held[(int) $row->user] = ($held[(int) $row->user] ?? []) + ($roleGrants[$row->role] ?? []);
        }

        $direct = DB::table($tables['model_has_permissions'].' as mhp')
            ->join($tables['permissions'].' as p', 'p.id', '=', 'mhp.permission_id')
            ->join('users as u', 'u.id', '=', "mhp.{$modelKey}")
            ->whereNull('u.disabled_at')->where('mhp.model_type', $morphClass)->where('p.guard_name', $guard)
            ->get(['u.id as user', 'p.name as permission']);
        foreach ($direct as $row) {
            $held[(int) $row->user][$row->permission] = true;
        }

        $ids = array_keys(array_filter($held, fn (array $permissions) => isset($permissions[PermissionCatalogue::USERS_VIEW], $permissions[PermissionCatalogue::USERS_MANAGE])));
        sort($ids);

        return $ids;
    }
}
