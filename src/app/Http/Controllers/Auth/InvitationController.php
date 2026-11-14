<?php

namespace App\Http\Controllers\Auth;

use App\Auth\Sso\SsoProviders;
use App\Exceptions\OrganizationInvitationException;
use App\Http\Controllers\Auth\Concerns\WithholdsQueryDetails;
use App\Http\Controllers\Controller;
use App\Services\InvitationAcceptance;
use App\Services\OrganizationInvitations;
use App\Shared\Pilot\CustomerPilotGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recipient registration through an organization invitation (Release 1.0, R1-3B; docs/product/release-1.0-r1-3-organization-invitations.md,
 * section 7). The token resolves ONLY through the hashed `organization_invitations` table; the legacy plaintext-token table is not read by
 * anything. The organization, the address and the membership role come from the verified invitation, never from the request, which carries
 * a name and a password and nothing else that matters.
 *
 * `GET` only reads and never consumes (mail scanners and link previews must not burn an invitation); only `POST` accepts. Every dead link
 * (unknown, malformed, expired, revoked, superseded, accepted, "the address already has an account", "the issuer is no longer authorized",
 * a retired legacy token) is the same answer: the same invalid page.
 */
class InvitationController extends Controller
{
    use WithholdsQueryDetails;

    public function __construct(private readonly OrganizationInvitations $invitations) {}

    /** Show the registration page, or the one invalid page. Never writes. */
    public function show(string $token): Response
    {
        $invitation = $this->withoutQueryDetails(fn () => $this->invitations->findOpen($token));

        if ($invitation === null) {
            return Inertia::render('auth/invitation-invalid');
        }

        // Explicit props: no id, no hash, nothing the recipient does not already know from the mail.
        return Inertia::render('auth/invitation-register', [
            'invitation' => [
                'email' => $invitation->email,
                'organizationName' => (string) $invitation->organization_name,
                'expiresAt' => $invitation->expires_at->toIso8601String(),
            ],
            'token' => $token,
            // SSO is deferred for the first pilot (R0.9-CS1): the page offers the password form only.
            'providersAvailable' => ! CustomerPilotGate::ssoDeferred() && SsoProviders::anyConfigured(),
        ]);
    }

    /** Accept by password: atomically the account, the `user` role and an ordinary member's membership. */
    public function register(Request $request, string $token): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'password' => [
                'required',
                'string',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols()->uncompromised(),
                'confirmed',
            ],
        ]);

        if ($validator->fails()) {
            // Named, not `back()`: the page sends `Referrer-Policy: no-referrer`, so the browser sends no Referer, and the token-bearing URL
            // is deliberately not kept as the session's previous URL (ProtectInvitationCredential).
            throw (new ValidationException($validator))->redirectTo(route('invitation.show', $token));
        }

        $validated = $validator->validated();

        // The slow step (bcrypt) runs here, before any lock is taken; the core only ever receives the finished hash.
        $acceptance = InvitationAcceptance::withPassword($validated['name'], Hash::make($validated['password']));

        try {
            $user = $this->withoutQueryDetails(fn () => $this->invitations->accept($token, $acceptance));
        } catch (OrganizationInvitationException) {
            // Whatever the cause, the answer is the page the same link would now show: the invalid one. Not reported: it is not a fault.
            return redirect()->route('invitation.show', $token);
        }

        Auth::login($user);

        return redirect()->route('dashboard');
    }
}
