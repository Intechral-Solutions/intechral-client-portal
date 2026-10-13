<?php

namespace App\Services;

use App\Exceptions\OperatorBootstrapRefused;
use App\Models\User;
use App\Shared\Permissions\BuiltInRoles;
use App\Shared\Permissions\PermissionCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Spatie\Permission\Models\Role;

/**
 * Release 0.9 (R0.9-1D, decision H4): the first account administrator of a new installation.
 *
 * The only way in is the `operator:bootstrap` Artisan command, so the authorization is shell access to the server: no
 * HTTP route, no public or self-service path, and an email address is only the identity of the account it creates.
 * It creates a NEW account through the application's own registration rules ({@see CreatesNewUsers}: the same name,
 * email and password requirements as everywhere; the user then signs in and may enable two-factor authentication as
 * any user can) and gives it the built-in `operator` role. It never elevates an existing account.
 *
 * It runs inside the account-administrator guard, whose first statement is the lock every grant mutation takes, and
 * decides "is this a new installation" under that lock. So two concurrent attempts cannot both create an
 * administrator: the second waits, sees the first, and is refused. After the first administrator exists the command
 * refuses for good. It is initial setup only, not a recovery path (see docs/architecture/role-governance.md).
 */
final class OperatorBootstrap
{
    public function __construct(
        private readonly AccountAdministrators $administrators,
        private readonly CreatesNewUsers $registration,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, password_confirmation: string}  $input
     *
     * @throws OperatorBootstrapRefused
     * @throws ValidationException when the name, email or password does not meet the application's requirements
     */
    public function provision(array $input): User
    {
        return $this->administrators->guard(function () use ($input) {
            if ($this->administrators->anyExist()) {
                throw OperatorBootstrapRefused::alreadyEstablished();
            }

            $role = Role::query()->where('name', BuiltInRoles::OPERATOR)->where('guard_name', BuiltInRoles::GUARD)->first();
            if ($role === null || ! $this->carriesAccountAdministration($role)) {
                throw OperatorBootstrapRefused::rolesNotSynchronized();
            }

            if (User::query()->where('email', $input['email'] ?? '')->exists()) {
                throw OperatorBootstrapRefused::emailInUse();
            }

            $user = $this->registration->create($input);
            $user->assignRole($role);

            if (! $this->administrators->ids()->contains($user->getKey())) {
                throw OperatorBootstrapRefused::notAnAdministrator();
            }

            activity()
                ->performedOn($user)
                ->withProperties(['email' => $user->email, 'source' => 'operator:bootstrap'])
                ->log('bootstrapped first operator');

            return $user;
        });
    }

    private function carriesAccountAdministration(Role $role): bool
    {
        return DB::table(config('permission.table_names.role_has_permissions').' as rhp')
            ->join(config('permission.table_names.permissions').' as p', 'p.id', '=', 'rhp.permission_id')
            ->where('rhp.role_id', $role->getKey())
            ->where('p.guard_name', BuiltInRoles::GUARD)
            ->whereIn('p.name', [PermissionCatalogue::USERS_VIEW, PermissionCatalogue::USERS_MANAGE])
            ->count() === 2;
    }
}
