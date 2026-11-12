<?php

namespace App\Http\Controllers\Organization;

use App\Exceptions\OrganizationInvitationException;
use App\Http\Controllers\Auth\Concerns\WithholdsQueryDetails;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\OrganizationInvitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Operator-side invitations of an organization (Release 1.0, R1-3C; design record sections 4.4, 6.1, 11 and 20): issue (also Resend) and
 * revoke. This is the first production caller of {@see OrganizationInvitations}, the ONLY writer of `organization_invitations`: nothing
 * here persists anything itself, opens a transaction (the service opens its own and must be entered at the top level) or reads a token.
 *
 * AUTHORITY is `manageMembership` (an ACTIVE account holding portal.operator AND crm.manage), checked here as the first statement and
 * again, from the tables and under lock, by the service. It is the same boundary as {@see OrganizationMemberController}. An approved
 * organization administrator, a partner administrator and a holder of `org.invite` alone have no ability here; customer-side delegation is
 * the later MO-2B package, which would extend the one `authorize()` seam of the service, not this controller.
 *
 * SCOPING. The organization is the route's. The request supplies an address and nothing else: no organization id, no role, no inviter, no
 * expiry. A revocation names an invitation id, which the service looks up WITHIN the organization (another organization's id is a 404,
 * never "forbidden", so the route is not an oracle for ids).
 *
 * CREDENTIAL SAFETY. The recipient's address is operator-supplied data and the insert binds the token's hash. A QueryException's message
 * embeds the SQL with its bindings, so every call into the service runs under {@see WithholdsQueryDetails::withoutQueryDetails()}: the
 * failure is reported with an allowlisted, binding-free diagnostic and answered with a plain 500. The token itself never reaches this
 * class: the service hands it to the queued, encrypted, after-commit notification and returns only the stored row.
 */
class OrganizationInvitationController extends Controller
{
    use WithholdsQueryDetails;

    public function __construct(private OrganizationInvitations $invitations) {}

    /** Issue, or re-issue (Resend): an open predecessor for the address is superseded, so its link dies. */
    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorize('manageMembership', Organization::class);

        $data = $request->validate([
            // The service normalizes (trim, lower-case); this only decides whether the value is an address at all.
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        try {
            $invitation = $this->withoutQueryDetails(fn () => $this->invitations->issue($organization, $data['email'], $request->user()));
        } catch (OrganizationInvitationException $exception) {
            // Account-exists and concurrent-issue are operator-facing refusals with their own wording; nothing was written.
            if ($exception->reason === OrganizationInvitationException::NOT_FOUND) {
                abort(404);
            }

            return redirect()->route('organizations.show', $organization)->withInput()->withErrors(['email' => $exception->getMessage()]);
        }

        return $this->back($organization, sprintf(
            'Invitation sent to %s. It expires in %d days, and any earlier link for this address no longer works.',
            $invitation->email,
            OrganizationInvitations::LIFETIME_DAYS,
        ));
    }

    /** Revoke an open invitation of this organization. Idempotent on one that is no longer open: nothing is written. */
    public function destroy(Request $request, Organization $organization, int $invitation): RedirectResponse
    {
        $this->authorize('manageMembership', Organization::class);

        try {
            $revoked = $this->withoutQueryDetails(fn () => $this->invitations->revoke($organization, $invitation, $request->user()));
        } catch (OrganizationInvitationException) {
            abort(404);
        }

        return $this->back($organization, $revoked
            ? 'Invitation revoked. Its link no longer works.'
            : 'That invitation was no longer pending (it was accepted, revoked or had expired). Nothing was changed.');
    }

    private function back(Organization $organization, string $message): RedirectResponse
    {
        return redirect()->route('organizations.show', $organization)->with('success', $message);
    }
}
