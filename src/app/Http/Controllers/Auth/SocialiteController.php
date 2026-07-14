<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialiteController extends Controller
{
    private const SUPPORTED_PROVIDERS = ['google', 'microsoft'];

    public function __construct(private readonly InvitationService $service) {}

    /** Redirect to the OAuth provider. */
    public function redirect(Request $request, string $provider)
    {
        abort_unless(in_array($provider, self::SUPPORTED_PROVIDERS), 404);

        // Carry invitation token through the OAuth flow via state
        if ($request->filled('invitation')) {
            session(['sso_invitation_token' => $request->input('invitation')]);
        }

        return Socialite::driver($provider)->redirect();
    }

    /** Handle the OAuth callback. */
    public function callback(Request $request, string $provider)
    {
        abort_unless(in_array($provider, self::SUPPORTED_PROVIDERS), 404);

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Exception) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Authentication via '.ucfirst($provider).' failed. Please try again.']);
        }

        // Already linked account — log in directly
        $socialAccount = SocialAccount::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->with('user')
            ->first();

        if ($socialAccount) {
            $this->updateToken($socialAccount, $socialUser);
            Auth::login($socialAccount->user, remember: true);

            return redirect()->intended(route('dashboard'));
        }

        // Check for invitation token in session (registration flow)
        $invitationToken = session()->pull('sso_invitation_token');

        if ($invitationToken) {
            return $this->registerViaSso($provider, $socialUser, $invitationToken);
        }

        // Try to link to an existing user by email (login flow)
        $user = User::where('email', strtolower($socialUser->getEmail()))->first();

        if ($user) {
            $this->linkAccount($user, $provider, $socialUser);
            Auth::login($user, remember: true);

            return redirect()->intended(route('dashboard'));
        }

        // No user found and no invitation — reject
        return redirect()->route('login')
            ->withErrors(['email' => 'No account found for that '.ucfirst($provider).' address. Please use an invitation link to register.']);
    }

    private function registerViaSso(string $provider, $socialUser, string $invitationToken)
    {
        $invitation = $this->service->findValid($invitationToken);

        if (! $invitation) {
            return view('auth.invitation-invalid');
        }

        if (strtolower($socialUser->getEmail()) !== strtolower($invitation->email)) {
            return redirect()->route('invitation.show', $invitationToken)
                ->withErrors(['email' => 'The '.ucfirst($provider).' account email does not match your invitation email ('.$invitation->email.').']);
        }

        $user = User::create([
            'name' => $socialUser->getName(),
            'email' => $invitation->email,
            'password' => null,
            'invited_by' => $invitation->invited_by,
        ]);

        $user->assignRole('user');
        $this->linkAccount($user, $provider, $socialUser);
        $this->service->accept($invitation, $user);

        Auth::login($user, remember: true);

        return redirect()->route('dashboard');
    }

    private function linkAccount(User $user, string $provider, $socialUser): void
    {
        SocialAccount::updateOrCreate(
            ['provider' => $provider, 'provider_id' => $socialUser->getId()],
            [
                'user_id' => $user->id,
                'token' => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
                'token_expires_at' => isset($socialUser->expiresIn)
                    ? now()->addSeconds($socialUser->expiresIn)
                    : null,
            ]
        );
    }

    private function updateToken(SocialAccount $account, $socialUser): void
    {
        $account->update([
            'token' => $socialUser->token,
            'refresh_token' => $socialUser->refreshToken,
            'token_expires_at' => isset($socialUser->expiresIn)
                ? now()->addSeconds($socialUser->expiresIn)
                : null,
        ]);
    }
}
