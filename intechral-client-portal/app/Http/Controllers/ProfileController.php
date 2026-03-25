<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user()->load('socialAccounts');
        $recoveryCodes = [];

        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            $recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true) ?? [];
        }

        return view('profile.show', compact('user', 'recoveryCodes'));
    }

    /** Invalidate all other browser sessions. */
    public function destroyOtherSessions(Request $request)
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return back()->with('status', 'Other sessions have been signed out.');
    }

    /** Unlink a social (SSO) provider from the account. */
    public function unlinkSocial(Request $request, string $provider)
    {
        $user = $request->user();

        // Prevent unlinking if the account has no password (would lock them out)
        if (! $user->password && $user->socialAccounts()->count() === 1) {
            return back()->withErrors(['provider' => 'You cannot unlink your only sign-in method. Set a password first.']);
        }

        $user->socialAccounts()->where('provider', $provider)->delete();

        return back()->with('status', ucfirst($provider) . ' account unlinked.');
    }
}
