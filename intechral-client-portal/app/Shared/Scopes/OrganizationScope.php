<?php

namespace App\Shared\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Global Eloquent scope that filters records by the authenticated user's
 * organization. Platform operators (role: 'operator') bypass this scope
 * and can see all records across all organizations.
 *
 * Apply this scope to any model that should be organization-scoped:
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

        // Operators see everything — bypass the scope
        if ($user->hasRole('operator')) {
            return;
        }

        // Organization members see only their org's data
        if ($user->organization_id) {
            $builder->where($model->getTable().'.organization_id', $user->organization_id);
        }
    }
}
