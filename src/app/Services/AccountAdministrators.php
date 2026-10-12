<?php

namespace App\Services;

use App\Exceptions\LastAccountAdministratorException;
use App\Models\User;
use App\Shared\Permissions\PermissionCatalogue;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Release 0.9 (H3): the portal must always keep at least one active account administrator.
 *
 * An account administrator is an ACTIVE user who holds both `users.view` (the /admin/users route group) and
 * `users.manage` (role changes, suspension), each either directly or through any role. That is exactly who the
 * route gates admit: there is no Gate::before override and wildcard permissions are off. `users.admin` is checked
 * nowhere and is not part of it. The set is read with SQL against the permission tables, never the permission cache,
 * so it reflects the latest committed state.
 *
 * Every application path that can shrink the set runs inside guard(). See docs/architecture/account-lifecycle.md.
 */
final class AccountAdministrators
{
    /**
     * Run a mutation that could remove an account administrator, and refuse it (rolling it back) if there was at
     * least one active administrator before and there is none after.
     *
     * The first statement of the transaction is an exclusive lock on the `users.manage` permission row, which every
     * guarded mutation takes, so they run one at a time and each one's reads see every guarded mutation committed
     * before it. Call it at the top level of a request, not inside a transaction that has already read these tables.
     *
     * @template T
     *
     * @param  Closure(): T  $mutation
     * @return T
     *
     * @throws LastAccountAdministratorException
     */
    public function guard(Closure $mutation): mixed
    {
        return DB::transaction(function () use ($mutation) {
            $this->lock();

            $before = $this->ids();
            $result = $mutation();

            if ($before->isNotEmpty() && $this->ids()->isEmpty()) {
                throw new LastAccountAdministratorException;
            }

            return $result;
        });
    }

    /**
     * Ids of the active account administrators, ascending.
     *
     * @return Collection<int, int>
     */
    public function ids(): Collection
    {
        return DB::table('users')
            ->whereNull('users.disabled_at')
            ->where($this->holds(PermissionCatalogue::USERS_VIEW))
            ->where($this->holds(PermissionCatalogue::USERS_MANAGE))
            ->orderBy('users.id')
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id);
    }

    private function lock(): void
    {
        DB::table(config('permission.table_names.permissions'))
            ->where('name', PermissionCatalogue::USERS_MANAGE)
            ->where('guard_name', $this->guardName())
            ->lockForUpdate()
            ->value('id');
    }

    /** The user holds the permission directly or through one of their roles. */
    private function holds(string $permission): Closure
    {
        $tables = config('permission.table_names');
        $modelKey = config('permission.column_names.model_morph_key');
        $morphClass = (new User)->getMorphClass();
        $guard = $this->guardName();

        return fn (Builder $query) => $query
            ->whereExists(fn (Builder $direct) => $direct->selectRaw('1')
                ->from($tables['model_has_permissions'].' as mhp')
                ->join($tables['permissions'].' as p', 'p.id', '=', 'mhp.permission_id')
                ->whereColumn("mhp.{$modelKey}", 'users.id')
                ->where('mhp.model_type', $morphClass)
                ->where('p.name', $permission)
                ->where('p.guard_name', $guard))
            ->orWhereExists(fn (Builder $viaRole) => $viaRole->selectRaw('1')
                ->from($tables['model_has_roles'].' as mhr')
                ->join($tables['role_has_permissions'].' as rhp', 'rhp.role_id', '=', 'mhr.role_id')
                ->join($tables['permissions'].' as p', 'p.id', '=', 'rhp.permission_id')
                ->whereColumn("mhr.{$modelKey}", 'users.id')
                ->where('mhr.model_type', $morphClass)
                ->where('p.name', $permission)
                ->where('p.guard_name', $guard));
    }

    private function guardName(): string
    {
        return (string) config('auth.defaults.guard');
    }
}
