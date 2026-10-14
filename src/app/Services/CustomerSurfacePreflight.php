<?php

namespace App\Services;

use App\Models\User;
use App\Shared\Permissions\BuiltInRoles;
use App\Shared\Permissions\PermissionCatalogue;
use App\Shared\Pilot\CustomerPilotGate;
use App\Shared\Pilot\CustomerSurfaceMode;
use Illuminate\Support\Facades\DB;

/**
 * Read-only readiness report for the customer-surface boundary (R0.9-CS1; `php artisan customer-surface:preflight`).
 *
 * It answers, from the committed database state and never the permission cache: which mode the application resolved
 * to, whether `portal.operator` exists and sits on the built-in `operator` role and not on `user`, whether anyone holds
 * it, and which active accounts and custom roles hold operational capabilities WITHOUT it (each is a customer to the
 * boundary until an authorized operator decides otherwise). It writes nothing, grants nothing and is not a role
 * provisioning system: eligibility is granted by `roles:sync` for the built-in role and by the Roles screens for a
 * custom one.
 *
 * `failures` mean the boundary would misbehave if the mode were pilot or closed today; `warnings` are decisions to take.
 */
final class CustomerSurfacePreflight
{
    /**
     * @return array{mode: string, failures: list<string>, warnings: list<string>, unresolvedUsers: list<array{id: int, email: string, roles: list<string>, capabilities: list<string>}>, unresolvedRoles: list<array{name: string, members: int, capabilities: list<string>}>, operators: int}
     */
    public function report(?string $expectedMode = null): array
    {
        $failures = [];
        $warnings = [];
        $mode = CustomerPilotGate::mode();
        $raw = config('portal.customer_surface_mode');

        if ($expectedMode !== null && $mode->value !== $expectedMode) {
            $failures[] = "The application resolved the customer surface mode \"{$mode->value}\", not the expected \"{$expectedMode}\".";
        }

        if (is_string($raw) && strtolower(trim($raw)) === CustomerSurfaceMode::Legacy->value && $mode !== CustomerSurfaceMode::Legacy) {
            $failures[] = 'PORTAL_CUSTOMER_SURFACE_MODE=legacy is set in an environment that does not honour it ('.app()->environment().'); it resolves to "closed". Remove it.';
        }

        $permission = DB::table('permissions')->where('name', PermissionCatalogue::PORTAL_OPERATOR)->where('guard_name', BuiltInRoles::GUARD)->first();
        $operatorRole = DB::table('roles')->where('name', BuiltInRoles::OPERATOR)->where('guard_name', BuiltInRoles::GUARD)->first();
        $userRole = DB::table('roles')->where('name', BuiltInRoles::USER)->where('guard_name', BuiltInRoles::GUARD)->first();

        if ($permission === null) {
            $failures[] = 'The portal.operator permission does not exist. Run `php artisan roles:sync` (preview), review it, then apply it.';
        } else {
            $onRole = fn (?object $role) => $role !== null
                && DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $permission->id)->exists();

            if (! $onRole($operatorRole)) {
                $failures[] = 'The built-in operator role does not hold portal.operator, so operators are customers to the boundary. Apply `roles:sync`.';
            }

            if ($onRole($userRole)) {
                $failures[] = 'The built-in user role holds portal.operator, which would exempt every customer from the boundary. Revoke it through `roles:sync --allow-revocations`.';
            }
        }

        $holders = $permission === null ? [] : $this->holders([PermissionCatalogue::PORTAL_OPERATOR]);
        $operators = count($holders);

        if ($permission !== null && $operators === 0) {
            $failures[] = 'No active account holds portal.operator: no one would be exempt from the customer restrictions.';
        }

        [$unresolvedUsers, $unresolvedRoles] = $this->unresolved($holders);

        if ($unresolvedUsers !== []) {
            $warnings[] = count($unresolvedUsers).' active account(s) hold operational capabilities without portal.operator and are customers to the boundary until eligibility is granted deliberately.';
        }

        if ($unresolvedRoles !== []) {
            $warnings[] = count($unresolvedRoles).' custom role(s) carry operational capabilities without portal.operator; decide for each whether it is an operator role.';
        }

        return [
            'mode' => $mode->value,
            'failures' => $failures,
            'warnings' => $warnings,
            'unresolvedUsers' => $unresolvedUsers,
            'unresolvedRoles' => $unresolvedRoles,
            'operators' => $operators,
        ];
    }

    /**
     * Active user ids holding any of the permissions, directly or through a role.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    private function holders(array $names): array
    {
        $direct = DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_type', User::class)
            ->whereIn('permissions.name', $names)
            ->pluck('model_has_permissions.model_id');

        $viaRole = DB::table('model_has_roles')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('model_has_roles.model_type', User::class)
            ->whereIn('permissions.name', $names)
            ->pluck('model_has_roles.model_id');

        return User::query()->active()
            ->whereIn('id', $direct->merge($viaRole)->unique()->values())
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Active accounts and custom roles that hold a capability outside the customer defaults, other than
     * `portal.operator` itself, while not being eligible.
     *
     * @param  list<int>  $eligible
     * @return array{0: list<array{id: int, email: string, roles: list<string>, capabilities: list<string>}>, 1: list<array{name: string, members: int, capabilities: list<string>}>}
     */
    private function unresolved(array $eligible): array
    {
        $beyondCustomer = array_values(array_diff(
            PermissionCatalogue::all(),
            PermissionCatalogue::userDefaults(),
            [PermissionCatalogue::PORTAL_OPERATOR],
        ));

        $roleCapabilities = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->whereIn('permissions.name', $beyondCustomer)
            ->get(['role_has_permissions.role_id', 'permissions.name'])
            ->groupBy('role_id')->map(fn ($rows) => $rows->pluck('name')->sort()->values()->all());

        $roles = DB::table('roles')->where('guard_name', BuiltInRoles::GUARD)->pluck('name', 'id');

        $userCapabilities = [];
        $userRoles = [];

        foreach (DB::table('model_has_roles')->where('model_type', User::class)->get(['model_id', 'role_id']) as $row) {
            $userRoles[$row->model_id][] = $roles[$row->role_id] ?? '?';
            foreach ($roleCapabilities[$row->role_id] ?? [] as $name) {
                $userCapabilities[$row->model_id][$name] = true;
            }
        }

        $direct = DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_type', User::class)
            ->whereIn('permissions.name', $beyondCustomer)
            ->get(['model_has_permissions.model_id', 'permissions.name']);

        foreach ($direct as $row) {
            $userCapabilities[$row->model_id][$row->name] = true;
        }

        $users = User::query()->active()->whereIn('id', array_keys($userCapabilities))->whereNotIn('id', $eligible)
            ->orderBy('id')->get(['id', 'email'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'email' => $user->email,
                'roles' => array_values(array_unique($userRoles[$user->id] ?? [])),
                'capabilities' => array_keys($userCapabilities[$user->id]),
            ])->all();

        $eligibleRoleIds = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('permissions.name', PermissionCatalogue::PORTAL_OPERATOR)
            ->pluck('role_has_permissions.role_id')->all();

        $members = DB::table('model_has_roles')->where('model_type', User::class)->selectRaw('role_id, count(*) as members')->groupBy('role_id')->pluck('members', 'role_id');

        $customRoles = [];
        foreach ($roleCapabilities as $roleId => $capabilities) {
            $name = $roles[$roleId] ?? null;

            if ($name === null || BuiltInRoles::isBuiltIn($name) || in_array($roleId, $eligibleRoleIds, true)) {
                continue;
            }

            $customRoles[] = ['name' => $name, 'members' => (int) ($members[$roleId] ?? 0), 'capabilities' => $capabilities];
        }

        usort($customRoles, fn (array $a, array $b) => $a['name'] <=> $b['name']);

        return [$users, $customRoles];
    }
}
