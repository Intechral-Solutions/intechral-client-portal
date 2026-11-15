<?php

namespace App\Http\Controllers\Auth;

use App\Auth\Sso\ProviderIdentity;
use App\Auth\Sso\SsoFreshness;
use App\Auth\Sso\SsoProviders;
use App\Exceptions\OrganizationInvitationException;
use App\Http\Controllers\Auth\Concerns\WithholdsQueryDetails;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\InvitationAcceptance;
use App\Services\OrganizationInvitations;
use App\Shared\Pilot\CustomerPilotGate;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Single sign-on: login, linking a provider to the signed-in account, and accepting an ORGANIZATION invitation (Release 1.0, R1-3B).
 *
 * THE INVITATION CONTEXT (docs/product/release-1.0-r1-3-organization-invitations.md, 7.3). Starting the flow from an invitation link stores
 * `{intent: invitation, provider, initiatingUserId: null, invitationHash, startedAt}` in the session. The HASH of the token, never the token:
 * the plaintext does not enter the session, and the hash is treated as a server-side credential too. It is never put in a redirect, a prop,
 * a log line or a request parameter, and the callback never reads a token from its own request: it resolves the invitation by that hash and
 * lets {@see OrganizationInvitations::acceptByHash()} revalidate everything (the invitation, the issuer's authority, the provider address
 * against the invited one) under lock. The context is
 *
 *  - one-time (`pull`: consumed whether or not the callback succeeds),
 *  - bound to its provider, and
 *  - good for {@see INVITATION_CONTEXT_MAX_AGE_SECONDS} only.
 *
 * Because the context holds a hash and not a token, NO failure path can rebuild an invitation link from session state: a provider failure, an
 * old or foreign context, an address mismatch and a dead invitation all end at the login page with a message that says to open the link from
 * the email again. (The one redirect that does name a link is the DEFERRED answer on initiation, built from the token the request itself just
 * carried.) SSO stays deferred for customers in `closed` mode ({@see CustomerPilotGate::ssoDeferred()}); Release 1.0 enables it in `pilot` mode, and only for a provider whose credentials are configured.
 */
class SocialiteController extends Controller
{
    use WithholdsQueryDetails;

    private const CONTEXT_KEY = 'oauth_context';

    /** How long a started invitation sign-in may take at the provider (consent screens, a second factor) before it is refused. */
    public const INVITATION_CONTEXT_MAX_AGE_SECONDS = 600;

    private const INVITATION_FAILED = 'This invitation could not be completed. Open the link from your email again.';

    public function __construct(private readonly OrganizationInvitations $invitations) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        abort_unless(SsoProviders::supported($provider), 404);

        $request->session()->forget(self::CONTEXT_KEY);
        $hasInvitation = $request->query->has('invitation');

        if ($hasInvitation && $request->user()) {
            return redirect()->route('profile.show')
                ->withErrors(['provider' => 'Sign out before accepting an invitation.'])
                ->with('error', 'Sign out before accepting an invitation.');
        }

        if ($hasInvitation) {
            $queried = $request->query('invitation');
            $token = is_string($queried) ? $queried : '';

            // An empty, repeated (array), malformed, unknown or dead token is the ordinary "no longer available" answer, decided before
            // anything is built from it (R0.9-CS1: an empty token used to reach route('invitation.show', '') and 500). A retired legacy
            // token is just another unknown one: findOpen() reads only the hashed organization invitations.
            if ($token === '' || ! $this->withoutQueryDetails(fn () => $this->invitations->findOpen($token))) {
                return redirect()->route('login')
                    ->withErrors(['email' => 'This invitation is no longer available.'])
                    ->with('error', 'This invitation is no longer available.');
            }

            if (CustomerPilotGate::ssoDeferred()) {
                return $this->invitationSsoDeferred($token);
            }

            if (! SsoProviders::configured($provider)) {
                return $this->invitationSsoDeferred($token, ucfirst($provider).' sign-in is not available. Create a password to accept this invitation.');
            }

            $context = [
                'intent' => 'invitation',
                'provider' => $provider,
                'initiatingUserId' => null,
                // The hash, never the token: the plaintext does not enter the session.
                'invitationHash' => OrganizationInvitations::hashToken($token),
                'startedAt' => now()->toIso8601String(),
            ];
        } elseif (! SsoProviders::configured($provider)) {
            return $this->providerUnavailable($request, $provider);
        } elseif ($request->user()) {
            // Linking adds a second, durable way into the account: it needs a RECENT authentication, not just a signed-in session.
            if (! SsoFreshness::isFresh($request)) {
                return $this->reauthenticationRequired($request);
            }

            $context = [
                'intent' => 'link',
                'provider' => $provider,
                'initiatingUserId' => $request->user()->getKey(),
                'invitationHash' => null,
                'startedAt' => now()->toIso8601String(),
            ];
        } else {
            $context = [
                'intent' => 'login',
                'provider' => $provider,
                'initiatingUserId' => null,
                'invitationHash' => null,
                'startedAt' => now()->toIso8601String(),
            ];
        }

        $request->session()->put(self::CONTEXT_KEY, $context);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(SsoProviders::supported($provider), 404);

        $context = $request->session()->pull(self::CONTEXT_KEY);

        if (! $this->validContext($context, $provider)) {
            return $this->contextFailure($request);
        }

        try {
            if (! SsoProviders::configured($provider)) {
                throw new \RuntimeException('provider not configured');
            }

            $socialUser = Socialite::driver($provider)->user();
            $identity = ProviderIdentity::from($provider, $socialUser);

            // Without a stable subject there is no identity to find, link or create: refused whole, for every intent.
            if ($identity->subject === null) {
                throw new \RuntimeException('provider returned no subject');
            }
        } catch (Throwable $exception) {
            report(new \RuntimeException(
                'OAuth provider request failed for '.$provider.' ('.get_debug_type($exception).').'
            ));

            return $this->providerFailure($request, $context, $provider);
        }

        // Release 0.9 (R0.9-CS1): SSO is deferred in `closed` mode. An invitation always creates a customer
        // account, so it is refused while the gate defers it; an existing customer is refused in login().
        if ($context['intent'] === 'invitation' && CustomerPilotGate::ssoDeferred()) {
            // No link to offer: the context holds a hash, and a link is never rebuilt from session state.
            return $this->invitationFailure('Single sign-on is not available yet. Open your invitation email again and create a password to accept the invitation.');
        }

        return match ($context['intent']) {
            'login' => $this->login($request, $provider, $identity),
            'link' => $this->link($request, $provider, $identity, $context['initiatingUserId']),
            'invitation' => $this->registerViaInvitation($request, $provider, $identity, $context['invitationHash']),
        };
    }

    private function login(Request $request, string $provider, ProviderIdentity $identity): RedirectResponse
    {
        $account = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', $identity->subject)
            ->with('user')
            ->first();

        // Release 0.9 (H3): this signs the user in directly, not through the user provider, so it checks suspension
        // itself. A suspended account gets the unlinked-identity answer: nothing discloses its existence or state.
        // Release 0.9 (R0.9-CS1): SSO sign-in is deferred for customers while the gate defers it (closed mode). They get the same answer as an
        // unlinked identity; operators are unaffected.
        if (! $account || $account->user->isSuspended() || (CustomerPilotGate::ssoDeferred() && CustomerPilotGate::restricts($account->user))) {
            return redirect()->route('login')
                ->withErrors(['email' => 'That provider account is not linked to a portal account.'])
                ->with('error', 'That provider account is not linked to a portal account.');
        }

        return $this->signIn($request, $account->user);
    }

    private function link(Request $request, string $provider, ProviderIdentity $identity, int $initiatingUserId): RedirectResponse
    {
        $user = $request->user();

        if (! $user || $user->getKey() !== $initiatingUserId) {
            return redirect()->route($user ? 'profile.show' : 'login')
                ->withErrors(['provider' => 'This provider connection could not be verified.'])
                ->with('error', 'This provider connection could not be verified.');
        }

        $existing = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', $identity->subject)
            ->first();

        if ($existing) {
            if ($existing->user_id !== $user->getKey()) {
                return redirect()->route('profile.show')
                    ->withErrors(['provider' => 'That provider account is already connected elsewhere.'])
                    ->with('error', 'That provider account is already connected elsewhere.');
            }

            return redirect()->route('profile.show')->with('status', ucfirst($provider).' account connected.');
        }

        if ($user->socialAccounts()->where('provider', $provider)->exists()) {
            return redirect()->route('profile.show')
                ->withErrors(['provider' => 'A '.ucfirst($provider).' account is already connected.'])
                ->with('error', 'A '.ucfirst($provider).' account is already connected.');
        }

        try {
            $user->socialAccounts()->create([
                'provider' => $provider,
                'provider_id' => $identity->subject,
            ]);
        } catch (QueryException) {
            report(new \RuntimeException(
                'An OAuth provider-link database operation failed; sensitive query details were suppressed.'
            ));

            return redirect()->route('profile.show')
                ->withErrors(['provider' => 'That provider account could not be connected.'])
                ->with('error', 'That provider account could not be connected.');
        }

        return redirect()->route('profile.show')->with('status', ucfirst($provider).' account connected.');
    }

    /**
     * The provider's verified identity against the invitation, resolved BY HASH. The core compares the provider's address with the invited one
     * (one normalizer) under the issuer, organization and invitation locks, revalidates the invitation and the issuer's authority, and creates
     * the account, the `user` role, the linked identity and the ordinary membership atomically. A refusal writes nothing and leaves the
     * invitation as it was.
     */
    private function registerViaInvitation(Request $request, string $provider, ProviderIdentity $identity, string $invitationHash): RedirectResponse
    {
        // Verified-email enforcement, failing closed: the provider must have asserted, in the claim it is documented to use, that it verified this
        // address (ProviderIdentity). No evidence, an ambiguous value or no address at all stops here, before the invitation is read, and writes
        // nothing: the invitation stays exactly as it was and the password path remains.
        if ($identity->verifiedEmail === null) {
            return $this->invitationFailure(ucfirst($provider).' did not confirm that your email address is verified, so it cannot be used to accept this invitation. Open the link from your email again and create a password instead.');
        }

        $acceptance = InvitationAcceptance::withProvider($provider, [
            'name' => $identity->name,
            'email' => $identity->verifiedEmail,
            'provider_id' => $identity->subject,
        ]);

        try {
            $user = $this->withoutQueryDetails(fn () => $this->invitations->acceptByHash($invitationHash, $acceptance));
        } catch (OrganizationInvitationException $exception) {
            // An address mismatch and a provider account that already belongs to a portal account both leave the invitation open, and both
            // concern only the person who just authenticated as that provider account, so each gets its accurate message. Every dead link is
            // the one uniform answer.
            return $this->invitationFailure(match (true) {
                $exception->reason === OrganizationInvitationException::EMAIL_MISMATCH => 'The provider account email does not match the invitation email.',
                $exception->detail === 'identity_taken' => 'That '.ucfirst($provider).' account is already connected to a portal account. Sign in with it from the login page, or use a different '.ucfirst($provider).' account for this invitation.',
                default => 'This invitation is no longer available.',
            });
        }

        // A new account has no second factor to ask for.
        return $this->signIn($request, $user);
    }

    /**
     * The one place an SSO identity becomes a session. A provider sign-in is the FIRST factor only, exactly as a password is: an account that has
     * confirmed two-factor authentication is sent to Fortify's own challenge (the user is not signed in until the code is accepted, and the
     * challenge regenerates the session itself), never straight in. Otherwise the session identifier is regenerated before the sign-in is
     * established, so an identifier planted before the provider round trip cannot be carried into the authenticated session.
     */
    private function signIn(Request $request, User $user): RedirectResponse
    {
        $request->session()->regenerate(destroy: true);

        // Note that the provider authenticated this user. It becomes the fresh-authentication proof (see SsoFreshness) only when this user is
        // actually signed in, which for a confirmed second factor is after the challenge.
        SsoFreshness::begin($request, (int) $user->getKey());

        if (filled($user->two_factor_secret) && ! (Fortify::confirmsTwoFactorAuthentication() && is_null($user->two_factor_confirmed_at))) {
            // Not remembered: a provider sign-in no longer outlives the session (pre-RC1 hardening). A person who wants a long-lived browser
            // uses the password form, where "remember me" is a visible choice.
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, remember: false);

        return redirect()->intended(route('dashboard'));
    }

    private function providerUnavailable(Request $request, string $provider): RedirectResponse
    {
        $message = ucfirst($provider).' sign-in is not available.';

        return redirect()->route($request->user() ? 'profile.show' : 'login')
            ->withErrors(['provider' => $message])
            ->with('error', $message);
    }

    /** Where every failed invitation sign-in ends: the login page, with a message. It never carries, or rebuilds, an invitation link. */
    private function invitationFailure(string $message): RedirectResponse
    {
        return redirect()->route('login')
            ->withErrors(['email' => $message])
            ->with('error', $message);
    }

    private function invitationSsoDeferred(string $token, string $message = 'Single sign-on is not available yet. Create a password to accept this invitation.'): RedirectResponse
    {
        return redirect()->route('invitation.show', $token)
            ->withErrors(['provider' => $message])
            ->with('error', $message);
    }

    private function validContext(mixed $context, string $provider): bool
    {
        if (! is_array($context) || ($context['provider'] ?? null) !== $provider || ! isset($context['startedAt']) || ! is_string($context['startedAt'])) {
            return false;
        }

        $startedAt = \DateTimeImmutable::createFromFormat(DATE_ATOM, $context['startedAt']);

        // A context from before R1-3B carried the PLAINTEXT token (`invitationToken`) and no age limit. Such a context is never honoured, for
        // any intent: it is refused whole, and `pull` has already removed it from the session.
        if ($startedAt === false || ($context['invitationToken'] ?? null) !== null) {
            return false;
        }

        return match ($context['intent'] ?? null) {
            'login' => ($context['initiatingUserId'] ?? null) === null
                && ($context['invitationHash'] ?? null) === null,
            // A link started and abandoned cannot be completed long afterwards (the start required a recent authentication).
            'link' => isset($context['initiatingUserId'])
                && is_int($context['initiatingUserId'])
                && ($context['invitationHash'] ?? null) === null
                && $this->freshEnough($startedAt),
            'invitation' => ($context['initiatingUserId'] ?? null) === null
                && is_string($context['invitationHash'] ?? null)
                && preg_match('/\A[0-9a-f]{64}\z/', $context['invitationHash']) === 1
                && $this->freshEnough($startedAt),
            default => false,
        };
    }

    /** The invitation intent has a maximum age; a start time in the future (beyond a minute of clock skew) is as unusable as an old one. */
    private function freshEnough(\DateTimeImmutable $startedAt): bool
    {
        $age = now()->getTimestamp() - $startedAt->getTimestamp();

        return $age >= -60 && $age <= self::INVITATION_CONTEXT_MAX_AGE_SECONDS;
    }

    private function contextFailure(Request $request): RedirectResponse
    {
        return redirect()->route($request->user() ? 'profile.show' : 'login')
            ->withErrors(['provider' => 'This provider sign-in request is no longer valid.'])
            ->with('error', 'This provider sign-in request is no longer valid.');
    }

    /** @param array<string, mixed> $context */
    private function providerFailure(Request $request, array $context, string $provider): RedirectResponse
    {
        if ($context['intent'] === 'invitation') {
            // The context was consumed by `pull` and holds only a hash: there is no link to go back to, and none is rebuilt.
            return $this->invitationFailure(self::INVITATION_FAILED);
        }

        return redirect()->route($request->user() ? 'profile.show' : 'login')
            ->withErrors(['provider' => 'Authentication via '.ucfirst($provider).' failed. Please try again.'])
            ->with('error', 'Authentication via '.ucfirst($provider).' failed. Please try again.');
    }

    /**
     * Where a link start ends when the session has not authenticated recently. A password account confirms its password on the existing
     * confirmation page (which returns to the profile, where "Connect" is chosen again: an in-page redirect chain through a provider cannot
     * follow an Inertia form post). An account without a password has no password to confirm, so it signs in again with its provider.
     */
    private function reauthenticationRequired(Request $request): RedirectResponse
    {
        if (filled($request->user()->password)) {
            $message = 'Confirm your password to connect a sign-in provider, then choose Connect again.';

            // Straight to the confirmation page (the profile's own helper redirects once more and would lose the flash), returning to the profile.
            $request->session()->put('url.intended', route('profile.show'));

            return redirect()->route('password.confirm')->with('status', $message);
        }

        $message = 'For your security, sign out and sign in again with your connected provider, then choose Connect within five minutes.';

        return redirect()->route('profile.show')
            ->withErrors(['provider' => $message])
            ->with('error', $message);
    }
}
