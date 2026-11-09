<?php

namespace App\Shared\Scopes;

use App\Shared\Pilot\CustomerPilotGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

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
            OrganizationScope::membershipOrganizationIds($user->getKey()),
        );
    }
}
