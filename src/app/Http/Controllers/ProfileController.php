<?php

namespace App\Http\Controllers;

use App\Services\DatabaseSessionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    private const PROVIDERS = ['google', 'microsoft'];

    public function __construct(private readonly DatabaseSessionManager $sessions) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $connectedProviders = $user->socialAccounts()
            ->whereIn('provider', self::PROVIDERS)
            ->pluck('provider');

        return Inertia::render('profile/show', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'hasPassword' => filled($user->password),
            ],
            'twoFactor' => [
                'enabled' => filled($user->two_factor_secret),
                'confirmed' => filled($user->two_factor_confirmed_at),
            ],
            'connectedAccounts' => collect(self::PROVIDERS)
                ->map(fn (string $provider) => [
                    'provider' => $provider,
                    'connected' => $connectedProviders->contains($provider),
                ])
                ->all(),
            'sessions' => $this->sessions->forUser($user, $request->session()->getId()),
        ]);
    }

    public function confirmPassword(Request $request): RedirectResponse
    {
        $request->session()->put('url.intended', route('profile.show'));

        return redirect()->route('password.confirm');
    }

    /** Invalidate all other browser sessions. */
    public function destroyOtherSessions(Request $request)
    {
        $request->validateWithBag('destroySessions', [
            'password' => ['required', 'current_password'],
        ]);

        $this->sessions->revokeOtherSessions($request->user(), $request->session()->getId());

        return back()->with('status', 'Other sessions have been signed out.');
    }

    /** Unlink a social (SSO) provider from the account. */
    public function unlinkSocial(Request $request, string $provider)
    {
        validator(['provider' => $provider], [
            'provider' => ['required', Rule::in(self::PROVIDERS)],
        ])->validate();

        $user = $request->user();

        // Prevent unlinking if the account has no password (would lock them out)
        if (! $user->password && $user->socialAccounts()->whereIn('provider', self::PROVIDERS)->count() <= 1) {
            return back()->withErrors([
                'provider' => 'Connect another sign-in provider before unlinking your only sign-in method.',
            ]);
        }

        $user->socialAccounts()->where('provider', $provider)->delete();

        return back()->with('status', ucfirst($provider).' account unlinked.');
    }
}
