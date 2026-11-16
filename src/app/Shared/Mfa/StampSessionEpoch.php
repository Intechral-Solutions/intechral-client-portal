<?php

namespace App\Shared\Mfa;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;

/**
 * Stamps the session with the account's session epoch (see {@see OperatorMfaGate::staleSession()}).
 *
 *  - On `Login`: fired for EVERY way a session comes to hold a user, a password sign-in, the second-factor challenge, an SSO callback, an invitation
 *    registration and a remember-me cookie that logs a browser back in. The stamp is the epoch of the user row the sign-in HOLDS, so a sign-in that
 *    loaded the user before a confirmation and completes after it stamps the OLD epoch and is refused at the gate. That is the in-flight case.
 *  - On `TwoFactorAuthenticationConfirmed`: the session that just confirmed continues, so it takes the new epoch (the user object already carries it:
 *    it was rotated by the very save that recorded the confirmation).
 */
final class StampSessionEpoch
{
    public function onLogin(Login $event): void
    {
        $this->stamp($event->user);
    }

    public function onConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->stamp($event->user);
    }

    private function stamp(mixed $user): void
    {
        if (! $user instanceof User || ! app()->bound('session.store') || ! request()->hasSession()) {
            return;
        }

        $session = request()->session();

        // A password sign-in loads the user twice (Fortify decides on a challenge from the first copy, `attempt()` loads the second). When the first copy
        // recorded what it saw, the stamp is THAT epoch, so a confirmation that lands between the two loads leaves the new session stale (review B1). It is
        // consumed here, and only used for the same account: it can only be older than the object this sign-in holds, never newer.
        $pending = $session->pull(OperatorMfaGate::PENDING_KEY);

        if (is_array($pending) && ($pending['id'] ?? null) === $user->getKey() && is_string($pending['epoch'] ?? null)) {
            $session->put(OperatorMfaGate::SESSION_KEY, $pending['epoch']);

            return;
        }

        OperatorMfaGate::stampSession($session, $user);
    }
}
