<?php

namespace App\Auth\Sso;

use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * Has this session authenticated RECENTLY? The proof asked for before a provider is linked (Release 1.0 pre-RC1 hardening).
 *
 * Linking adds a second, durable way into the account, so a hijacked or unattended session must not be able to do it silently. Within
 * {@see WINDOW_SECONDS}, the proof is:
 *
 *  - the PASSWORD was confirmed (the framework's own `auth.password_confirmed_at`, written by Fortify's confirmation page), for any account; or
 *  - for an account that has NO password only, a PROVIDER SIGN-IN completed in this session for that user, second-factor challenge included.
 *
 * The second proof is recorded in two steps because the sign-in may be parked at the two-factor challenge: {@see begin()} notes, for the user the
 * provider just identified, that a provider authentication happened; {@see recordLogin()} (a `Login` listener) turns it into the proof only when
 * THAT user is actually signed in, whichever request completes it. A noted authentication for another user, or one older than the window, is
 * discarded. Nothing here grants access; it only answers "how recently did this session prove who it is".
 */
final class SsoFreshness
{
    public const WINDOW_SECONDS = 300;

    private const PASSWORD = 'auth.password_confirmed_at';

    private const PENDING = 'sso.authenticated';

    private const PROVEN = 'auth.sso_authenticated_at';

    /** Note that the provider has just authenticated `$userId`. Call after the session id was regenerated and before the sign-in is established. */
    public static function begin(Request $request, int $userId): void
    {
        $request->session()->put(self::PENDING, ['user' => $userId, 'at' => now()->getTimestamp()]);
    }

    /** `Login` listener: promote a noted provider authentication to a proof, for the user it names and only if still recent. */
    public static function recordLogin(Login $event): void
    {
        if (! app()->bound('session.store') || ! request()->hasSession()) {
            return;
        }

        $session = request()->session();
        $pending = $session->pull(self::PENDING);

        if (is_array($pending)
            && ($pending['user'] ?? null) === $event->user->getAuthIdentifier()
            && is_int($pending['at'] ?? null)
            && now()->getTimestamp() - $pending['at'] <= self::WINDOW_SECONDS) {
            $session->put(self::PROVEN, ['user' => $pending['user'], 'at' => $pending['at']]);
        }
    }

    /**
     * Fresh for the signed-in user? A password account proves it by confirming the password; ONLY an account with no password may rely on a provider
     * sign-in (it has no password to confirm), and that proof names the user it was made for.
     */
    public static function isFresh(Request $request): bool
    {
        $user = $request->user();
        $now = now()->getTimestamp();

        if ($user === null) {
            return false;
        }

        $password = $request->session()->get(self::PASSWORD);

        if (is_int($password) && $now - $password <= self::WINDOW_SECONDS && $now - $password >= -60) {
            return true;
        }

        $proven = $request->session()->get(self::PROVEN);

        return blank($user->password)
            && is_array($proven)
            && ($proven['user'] ?? null) === $user->getAuthIdentifier()
            && is_int($proven['at'] ?? null)
            && $now - $proven['at'] <= self::WINDOW_SECONDS
            && $now - $proven['at'] >= -60;
    }
}
