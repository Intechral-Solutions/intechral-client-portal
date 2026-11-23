<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\OrganizationDirectory;
use App\Services\OrganizationListFacts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Directory's Organization list and writes (Release 1.0; ORG-2 as `CompanyController`, `crm.companies.*`; moved to `organizations.*` by ORG-3b). The workspace pages
 * (Overview, People, Portal access) are {@see OrganizationWorkspaceController}; System → Organization access is {@see OrganizationAccessController}. There is no second
 * Organization editor: every write is {@see OrganizationDirectory}, which authorizes it again from the tables under its locks.
 *
 * Every action asks `manageDirectory` first (an active operator holding `portal.operator` and `crm.manage`). The route group's `can:crm.manage` alone would let a
 * customer who holds a stray `crm.manage` read an Organization's internal notes; this does not. The ability is asked AFTER route-model binding, so an Organization a
 * tenant scope hides is a 404 (hidden and unknown alike) and a visible one is a 403 for an actor who may not.
 *
 * Where each write returns: create and update to the Overview (`organizations.show`), delete to the list, and initial enablement to the Portal access tab, where the
 * new state and its controls are. A refusal comes back to the page the form was on (`back()`), carrying the same `company` error key as before.
 */
class OrganizationController extends Controller
{
    public function __construct(private OrganizationDirectory $directory) {}

    public function index(Request $request): View
    {
        $this->authorize('manageDirectory', Organization::class);

        // An array-shaped parameter (`?search[]=x`) is no search, never an "Array to string" error.
        $search = is_string($request->query('search')) ? $request->query('search') : null;

        $organizations = Organization::query()
            ->when($search, fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '\\%_').'%'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        // Release 1.0 (DIR-1): "Managed by" / "Manages N" only for an actor who may manage relationships. The relationship facts are not read for anyone
        // else (null), so nothing is merely hidden in the view (DIR-0 S7). The portal-access state of each row is the row's own (ORG-3a: PortalState, drawn by one badge).
        $relationships = OrganizationListFacts::relationships($request->user(), $organizations->getCollection()->pluck('id')->all());

        return view('organizations.index', compact('organizations', 'search', 'relationships'));
    }

    public function create(): View
    {
        $this->authorize('manageDirectory', Organization::class);

        return view('organizations.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manageDirectory', Organization::class);

        $organization = $this->directory->create($request->user(), $this->validated($request));

        return redirect()->route('organizations.show', $organization)
            ->with('success', 'Organization created.');
    }

    public function edit(Organization $organization): View
    {
        $this->authorize('manageDirectory', Organization::class);

        return view('organizations.edit', compact('organization'));
    }

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorize('manageDirectory', Organization::class);

        $this->directory->update($organization, $request->user(), $this->validated($request));

        return redirect()->route('organizations.show', $organization)
            ->with('success', 'Organization updated.');
    }

    public function destroy(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorize('manageDirectory', Organization::class);

        $this->directory->delete($organization, $request->user());

        return redirect()->route('organizations.index')
            ->with('success', 'Organization deleted.');
    }

    /**
     * Initial enablement of portal access. The ability is `manageMembership` (portal.operator AND crm.manage, an active account), asked here as the first
     * statement and again by the service from the tables under its locks; it is not `manageRelationships`, which governs disable and re-enable (ADR-011 section 2).
     * It returns to the Portal access tab: the state and the controls it unlocks are there.
     */
    public function promote(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorize('manageMembership', Organization::class);

        $enabled = $this->directory->enablePortalAccess($organization, $request->user());

        return redirect()->route('organizations.portal', $enabled)
            ->with('success', "Portal access is enabled for \"{$enabled->name}\".");
    }

    /**
     * The Directory form's fields. Classification is deliberately NOT one (CP6, F-4): its editable control belongs to ORG-3c, so no
     * Directory request can set it; it is changed through OrganizationDirectory::changeClassification and shown read-only.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'website' => 'nullable|url|max:255',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:5000',
        ]);
    }
}
