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

        $availableUsers = User::whereDoesntHave('organizations', fn ($q) => $q->where('organizations.id', $organization->id)
        )->orderBy('name')->get(['id', 'name', 'email']);

        return view('organizations.show', compact('organization', 'availableUsers'));
    }
}
