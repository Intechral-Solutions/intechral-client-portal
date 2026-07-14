<?php

namespace App\Shared\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Scope models without organization_id through their CRM company relation.
 */
class OrganizationThroughCompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! Auth::check()) {
            return;
        }

        $user = Auth::user();

        if ($user->hasRole('operator')) {
            return;
        }

        $builder->whereHas('company', function (Builder $company) use ($user): void {
            $company
                ->withoutGlobalScope(OrganizationScope::class)
                ->whereIn(
                    $company->qualifyColumn('organization_id'),
                    OrganizationScope::membershipOrganizationIds($user->getKey()),
                );
        });
    }
}
