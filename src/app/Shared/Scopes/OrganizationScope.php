<?php

namespace App\Shared\Scopes;

use App\Shared\Pilot\CustomerPilotGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Scope models that have a direct organization_id foreign key to all
 * organizations joined by the authenticated user. Platform operators bypass
 * tenant filtering and retain cross-organization visibility. "Operator" is the
 * explicit `portal.operator` eligibility ({@see CustomerPilotGate::isOperator()}),
 * never the built-in role's name: a custom-role operator has the same scope as the
 * built-in one, and a customer holding other operational capabilities has none.
 *
 * Only apply this scope to models whose tables contain organization_id:
 *
 *   protected static function booted(): void
 *   {
 *       static::addGlobalScope(new OrganizationScope);
 *   }
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! Auth::check()) {
            return;
        }

        $user = Auth::user();

        if (CustomerPilotGate::isOperator($user)) {
            return;
        }

        $builder->whereIn(
            $model->qualifyColumn('organization_id'),
            self::membershipOrganizationIds($user->getKey()),
        );
    }

    public static function membershipOrganizationIds(int $userId): QueryBuilder
    {
        return DB::table('organization_members')
            ->select('organization_id')
            ->where('user_id', $userId);
    }
}
