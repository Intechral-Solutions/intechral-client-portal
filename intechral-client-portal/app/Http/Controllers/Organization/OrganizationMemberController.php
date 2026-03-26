<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\CrmService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrganizationMemberController extends Controller
{
    public function __construct(private CrmService $service) {}

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role'    => 'required|in:admin,member',
        ]);

        $user = User::findOrFail($data['user_id']);
        $this->service->addMember($organization, $user, $data['role']);

        return redirect()->route('organizations.show', $organization)
            ->with('success', "{$user->name} added to organization.");
    }

    public function updateRole(Request $request, Organization $organization, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => 'required|in:admin,member',
        ]);

        abort_unless($organization->hasMember($user), 404);

        $this->service->updateMemberRole($organization, $user, $data['role']);

        return redirect()->route('organizations.show', $organization)
            ->with('success', 'Member role updated.');
    }

    public function destroy(Organization $organization, User $user): RedirectResponse
    {
        abort_unless($organization->hasMember($user), 404);

        $this->service->removeMember($organization, $user);

        return redirect()->route('organizations.show', $organization)
            ->with('success', "{$user->name} removed from organization.");
    }
}
