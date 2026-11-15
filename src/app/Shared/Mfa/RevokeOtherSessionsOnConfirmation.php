<?php

namespace App\Shared\Mfa;

use App\Models\User;
use App\Services\DatabaseSessionManager;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;

/**
 * When an account confirms a second factor, every OTHER session of that account is signed out (stored sessions deleted, remember-me token rotated),
 * and the confirming one is kept.
 *
 * Why: the operator gate reads only the account's state, not what a session once proved. A session opened with the first factor alone before the
 * account enrolled (a password holder's, or a remembered browser) would otherwise become fully privileged the moment the real person confirms their
 * authenticator, without ever having presented a second factor. Ending those sessions at confirmation closes that. The session that confirmed
 * (the person who just proved possession of the new factor) continues. Release 1.0 (operator MFA review B1).
 *
 * For EVERY account, not only operators: an account may confirm a factor as a customer and be granted `portal.operator` later, and a session a
 * thief opened before that confirmation must not survive into the operator role (closure review B1-bis). The cost to a customer is a re-sign-in on their
 * other browsers when they enable the optional factor. An account that confirmed a factor BEFORE this release has no such event: the runbook has its owner
 * sign out their other sessions when the role is granted.
 */
final class RevokeOtherSessionsOnConfirmation
{
    public function __construct(private readonly DatabaseSessionManager $sessions) {}

    public function handle(TwoFactorAuthenticationConfirmed $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || ! app()->bound('session.store') || ! request()->hasSession()) {
            return;
        }

        $this->sessions->revokeOtherSessions($user, request()->session()->getId());
    }
}
