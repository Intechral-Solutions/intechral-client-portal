<?php

namespace App\Shared\Scopes;

use App\Shared\Pilot\CustomerPilotGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Directory records that only operators may see (Release 1.0, ORG-2, owner ruling D7: "People are visible only to authorized operators").
 *
 * It replaces OrganizationThroughCompanyScope, which let a MEMBER of an Organization see the People of that Organization's Company. A Person is a
 * Directory record of a human, never a portal account: nothing about an affiliation makes it visible to the Organization's members, and a Directory-only
 * Organization, which has no members, never gained a Person audience by existing. An operator (`portal.operator`, {@see CustomerPilotGate::isOperator()})
 * sees every row; every other signed-in actor sees none. With no signed-in actor (a console command, a job) the scope does not filter, as the other tenant
 * scopes do: those paths are deliberate and authorize by other means.
 *
 * It is a backstop: the Directory routes require `crm.manage` and the services authorize `manageDirectory` themselves.
 */
class DirectoryOperatorScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! Auth::check()) {
            return;
        }

        if (CustomerPilotGate::isOperator(Auth::user())) {
            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
