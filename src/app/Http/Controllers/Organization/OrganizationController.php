<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function index(): View
    {
        $organizations = Organization::with(['owner', 'company'])
            ->withCount('members')
            ->orderBy('name')
            ->paginate(25);

        return view('organizations.index', compact('organizations'));
    }

    public function show(Organization $organization): View
    {
        $organization->load(['owner', 'company', 'members']);

        // Candidates to add: active accounts only (Release 0.9, H3). Existing members, suspended or not, are listed
        // from the relation above.
        $availableUsers = User::active()->whereDoesntHave('organizations', fn ($q) => $q->where('organizations.id', $organization->id)
        )->orderBy('name')->get(['id', 'name', 'email']);

        return view('organizations.show', compact('organization', 'availableUsers'));
    }
}
