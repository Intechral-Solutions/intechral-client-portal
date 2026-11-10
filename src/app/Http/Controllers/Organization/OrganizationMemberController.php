<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Operator-side membership of an organization (Release 1.0, MO-2A1). Every action authorizes `manageMembership`
 * (portal.operator AND crm.manage) as its first statement and then calls {@see OrganizationMembership}, the only writer, which
 * authorizes again and takes the organization lock. Before MO-2A1 these routes were reachable, behind the bare `crm.manage`
 * permission, by any customer account holding it who was a member of the organization.
 *
 * The organization still comes from route-model binding, so the tenant scope still decides which organizations this page can
 * open. The scope keys on `portal.operator` (R1-1), so an operator-eligible account, built-in or custom-role, opens every organization;
 * an account that is not eligible reaches only the organizations it is a member of and is answered 404 for the rest.
 */
class OrganizationMemberController extends Controller
{
    public function __construct(private OrganizationMembership $membership) {}

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorize('manageMembership', Organization::class);

        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->whereNull('disabled_at')], // active accounts only (H3)
            'role' => 'required|in:admin,member',
        ]);

        $user = User::findOrFail($data['user_id']);
        $this->membership->add($organization, $user, $data['role'], $request->user());

        return $this->back($organization, $data['role'] === 'admin'
            ? "{$user->name} added as an administrator. They are not approved: an administrator holds no delegated authority until an operator approves them."
            : "{$user->name} added to organization.");
    }

    public function updateRole(Request $request, Organization $organization, User $user): RedirectResponse
    {
        $this->authorize('manageMembership', Organization::class);

        $data = $request->validate([
            'role' => 'required|in:admin,member',
        ]);

        abort_unless($organization->hasMember($user), 404);

        // Say only what happened: an unchanged role is reported as such, and "cleared" only when approval data really was cleared.
        $cleared = false;

        if ($data['role'] === 'admin') {
            $changed = $this->membership->promote($organization, $user, $request->user(), $cleared);
            $message = $changed
                ? 'Member role updated. They are not approved: approve them separately to record delegated authority.'.($cleared ? ' A leftover approval record was cleared.' : '')
                : "{$user->name} is already an administrator. Nothing was changed.";
        } else {
            $changed = $this->membership->demote($organization, $user, $request->user(), $cleared);
            $message = $changed
                ? 'Member role updated.'.($cleared ? ' The approval was cleared.' : '')
                : "{$user->name} is already a member. Nothing was changed.";
        }

        return $this->back($organization, $message);
    }

    public function destroy(Request $request, Organization $organization, User $user): RedirectResponse
    {
        $this->authorize('manageMembership', Organization::class);

        abort_unless($organization->hasMember($user), 404);

        $this->membership->remove($organization, $user, $request->user());

        return $this->back($organization, "{$user->name} removed from organization.");
    }

    public function approve(Request $request, Organization $organization, User $user): RedirectResponse
    {
        $this->authorize('manageMembership', Organization::class);

        abort_unless($organization->hasMember($user), 404);

        $changed = $this->membership->approve($organization, $user, $request->user());

        return $this->back($organization, $changed ? "{$user->name} approved as an administrator." : "{$user->name} is already approved.");
    }

    /** The exceptional path: it may leave the organization without an approved administrator, and says so only if the operator confirmed it. */
    public function revokeApproval(Request $request, Organization $organization, User $user): RedirectResponse
    {
        $this->authorize('manageMembership', Organization::class);

        $data = $request->validate(['confirm_no_administrator' => ['sometimes', 'boolean']]);

        abort_unless($organization->hasMember($user), 404);

        $changed = $this->membership->revokeApproval($organization, $user, $request->user(), (bool) ($data['confirm_no_administrator'] ?? false));

        return $this->back($organization, $changed ? "{$user->name}'s approval was revoked." : "{$user->name} was not approved.");
    }

    private function back(Organization $organization, string $message): RedirectResponse
    {
        return redirect()->route('organizations.show', $organization)->with('success', $message);
    }
}
