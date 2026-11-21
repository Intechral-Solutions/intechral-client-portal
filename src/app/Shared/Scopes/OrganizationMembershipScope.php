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
 * Scope Organization records through the organization_members pivot.
 */
class OrganizationMembershipScope implements Scope
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
            $model->qualifyColumn($model->getKeyName()),
            self::membershipOrganizationIds($user->getKey()),
        );
    }

    /** The Organizations the user belongs to, as a subquery. (Moved here with ORG-2: it was a helper of OrganizationScope, which had one model left, the retired Company.) */
    public static function membershipOrganizationIds(int $userId): QueryBuilder
    {
        return DB::table('organization_members')
            ->select('organization_id')
            ->where('user_id', $userId);
    }
}
