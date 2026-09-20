<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\InvitationEmailMismatchException;
use App\Exceptions\InvitationUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Services\InvitationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialiteController extends Controller
{
    private const CONTEXT_KEY = 'oauth_context';

    private const SUPPORTED_PROVIDERS = ['google', 'microsoft'];

    public function __construct(private readonly InvitationService $service) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::SUPPORTED_PROVIDERS, true), 404);

        $request->session()->forget(self::CONTEXT_KEY);
        $hasInvitation = $request->query->has('invitation');

        if ($hasInvitation && $request->user()) {
            return redirect()->route('profile.show')
                ->withErrors(['provider' => 'Sign out before accepting an invitation.'])
                ->with('error', 'Sign out before accepting an invitation.');
        }

        if ($hasInvitation) {
            $token = $request->string('invitation')->toString();

            if ($token === '' || ! $this->service->findValid($token)) {
                return redirect()->route('login')
                    ->withErrors(['email' => 'This invitation is no longer available.'])
                    ->with('error', 'This invitation is no longer available.');
            }

            $context = [
                'intent' => 'invitation',
                'provider' => $provider,
                'initiatingUserId' => null,
                'invitationToken' => $token,
                'startedAt' => now()->toIso8601String(),
            ];
        } elseif ($request->user()) {
            $context = [
                'intent' => 'link',
                'provider' => $provider,
                'initiatingUserId' => $request->user()->getKey(),
                'invitationToken' => null,
                'startedAt' => now()->toIso8601String(),
            ];
        } else {
            $context = [
                'intent' => 'login',
                'provider' => $provider,
                'initiatingUserId' => null,
                'invitationToken' => null,
                'startedAt' => now()->toIso8601String(),
            ];
        }

        $request->session()->put(self::CONTEXT_KEY, $context);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::SUPPORTED_PROVIDERS, true), 404);

        $context = $request->session()->pull(self::CONTEXT_KEY);

        if (! $this->validContext($context, $provider)) {
            return $this->contextFailure($request);
        }

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (Throwable) {
            return $this->providerFailure($request, $context, $provider);
        }

        return match ($context['intent']) {
            'login' => $this->login($provider, $socialUser),
            'link' => $this->link($request, $provider, $socialUser, $context['initiatingUserId']),
            'invitation' => $this->registerViaInvitation($provider, $socialUser, $context['invitationToken']),
        };
    }

    private function login(string $provider, SocialiteUser $socialUser): RedirectResponse
    {
        $account = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->with('user')
            ->first();

        if (! $account) {
            return redirect()->route('login')
                ->withErrors(['email' => 'That provider account is not linked to a portal account.'])
                ->with('error', 'That provider account is not linked to a portal account.');
        }

        $this->updateToken($account, $socialUser);
        Auth::login($account->user, remember: true);

        return redirect()->intended(route('dashboard'));
    }

    private function link(Request $request, string $provider, SocialiteUser $socialUser, int $initiatingUserId): RedirectResponse
    {
        $user = $request->user();

        if (! $user || $user->getKey() !== $initiatingUserId) {
            return redirect()->route($user ? 'profile.show' : 'login')
                ->withErrors(['provider' => 'This provider connection could not be verified.'])
                ->with('error', 'This provider connection could not be verified.');
        }

        $identity = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if ($identity) {
            if ($identity->user_id !== $user->getKey()) {
                return redirect()->route('profile.show')
                    ->withErrors(['provider' => 'That provider account is already connected elsewhere.'])
                    ->with('error', 'That provider account is already connected elsewhere.');
            }

            $this->updateToken($identity, $socialUser);

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
                'provider_id' => $socialUser->getId(),
                ...$this->tokenAttributes($socialUser),
            ]);
        } catch (QueryException) {
            return redirect()->route('profile.show')
                ->withErrors(['provider' => 'That provider account could not be connected.'])
                ->with('error', 'That provider account could not be connected.');
        }

        return redirect()->route('profile.show')->with('status', ucfirst($provider).' account connected.');
    }

    private function registerViaInvitation(string $provider, SocialiteUser $socialUser, string $token): RedirectResponse
    {
        try {
            $user = $this->service->acceptWithSocialAccount($token, $provider, [
                'name' => $socialUser->getName(),
                'email' => $socialUser->getEmail(),
                'provider_id' => $socialUser->getId(),
                ...$this->tokenAttributes($socialUser),
            ]);
        } catch (InvitationEmailMismatchException) {
            return redirect()->route('invitation.show', $token)
                ->withErrors(['email' => 'The provider account email does not match the invitation email.'])
                ->with('error', 'The provider account email does not match the invitation email.');
        } catch (InvitationUnavailableException) {
            return redirect()->route('invitation.show', $token);
        }

        Auth::login($user, remember: true);

        return redirect()->route('dashboard');
    }

    private function validContext(mixed $context, string $provider): bool
    {
        if (
            ! is_array($context)
            || ($context['provider'] ?? null) !== $provider
            || ! isset($context['startedAt'])
            || ! is_string($context['startedAt'])
            || \DateTimeImmutable::createFromFormat(DATE_ATOM, $context['startedAt']) === false
        ) {
            return false;
        }

        return match ($context['intent'] ?? null) {
            'login' => ($context['initiatingUserId'] ?? null) === null
                && ($context['invitationToken'] ?? null) === null,
            'link' => isset($context['initiatingUserId'])
                && is_int($context['initiatingUserId'])
                && ($context['invitationToken'] ?? null) === null,
            'invitation' => ($context['initiatingUserId'] ?? null) === null
                && isset($context['invitationToken'])
                && is_string($context['invitationToken'])
                && $context['invitationToken'] !== '',
            default => false,
        };
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
            return redirect()->route('invitation.show', $context['invitationToken'])
                ->withErrors(['provider' => 'Authentication via '.ucfirst($provider).' failed. Please try again.'])
                ->with('error', 'Authentication via '.ucfirst($provider).' failed. Please try again.');
        }

        return redirect()->route($request->user() ? 'profile.show' : 'login')
            ->withErrors(['provider' => 'Authentication via '.ucfirst($provider).' failed. Please try again.'])
            ->with('error', 'Authentication via '.ucfirst($provider).' failed. Please try again.');
    }

    private function updateToken(SocialAccount $account, SocialiteUser $socialUser): void
    {
        $account->update($this->tokenAttributes($socialUser));
    }

    /** @return array{token: ?string, refresh_token: ?string, token_expires_at: mixed} */
    private function tokenAttributes(SocialiteUser $socialUser): array
    {
        $expiresIn = property_exists($socialUser, 'expiresIn') ? $socialUser->expiresIn : null;

        return [
            'token' => property_exists($socialUser, 'token') ? $socialUser->token : null,
            'refresh_token' => property_exists($socialUser, 'refreshToken') ? $socialUser->refreshToken : null,
            'token_expires_at' => $expiresIn !== null ? now()->addSeconds((int) $expiresIn) : null,
        ];
    }
}
