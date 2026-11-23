<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\OrganizationListFacts;
use App\Support\Organizations\PortalState;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * System → Organization access (Release 1.0, DIR-1; corrected in ORG-3a; moved to `organizations.access` by ORG-3b): the filtered list of Organizations whose portal access
 * has EVER been enabled, each row linking to the Portal access tab of the workspace ({@see OrganizationWorkspaceController::portal()}). It is never an editor.
 *
 * The route group's `can:crm.manage` is only the first gate: a customer who holds a stray `crm.manage` passes it, and the rows carry administration facts (the enabling operator,
 * member counts, managing relationships). So the action asks `manageDirectory` first, an active account holding `portal.operator` AND `crm.manage`, exactly as the Directory pages do.
 */
class OrganizationAccessController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('manageDirectory', Organization::class);

        // ORG-3a: only Organizations whose portal access has EVER been enabled (Enabled or Disabled). A Directory-only Organization has nothing to administer here and is
        // not "Enabled": it lives in the Directory, and enabling portal access is what brings it into this list. The state shown is the row's own (PortalState), never a
        // default for a row the read did not classify.
        $organizations = PortalState::whereEverEnabled(Organization::query())
            ->with(['portalEnabledBy'])
            ->withCount('members')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25);

        // Release 1.0 (DIR-1): "Managed by" / "Manages N" ONLY for an actor who may manage relationships. The relationship facts are not read at all for anyone else
        // (null), so there is nothing to hide in the view (DIR-0 invariant S7).
        $relationships = OrganizationListFacts::relationships($request->user(), $organizations->getCollection()->pluck('id')->all());

        return view('organizations.access', compact('organizations', 'relationships'));
    }
}
