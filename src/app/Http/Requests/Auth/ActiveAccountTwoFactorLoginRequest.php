<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

/**
 * Release 0.9 (H3). Fortify finds the challenged user with a plain model lookup of `login.id`, not through the user
 * provider, so a password step completed before a suspension could otherwise be finished after it. A suspended
 * challenged user is treated exactly as a missing one: the pending challenge is dropped and the caller gets Fortify's
 * own "no challenge in progress" response, before any code or recovery code is checked or consumed.
 *
 * Release 1.0 (operator MFA): the same for a challenged user whose second factor is no longer CONFIRMED. A password step completed before
 * `user:reset-two-factor` (or the account disabling its own second factor) leaves a pending challenge that no session row names, so it cannot be
 * revoked; it is refused here instead (otherwise Fortify would try to decrypt a secret that is gone).
 */
class ActiveAccountTwoFactorLoginRequest extends TwoFactorLoginRequest
{
    public function hasChallengedUser()
    {
        if (! parent::hasChallengedUser()) {
            return false;
        }

        if ($this->mayComplete(parent::challengedUser())) {
            return true;
        }

        $this->forgetChallenge();

        return false;
    }

    public function challengedUser()
    {
        $user = parent::challengedUser();

        if (! $this->mayComplete($user)) {
            $this->forgetChallenge();

            throw new HttpResponseException(app(FailedTwoFactorLoginResponse::class)->toResponse($this));
        }

        return $user;
    }

    private function mayComplete(User $user): bool
    {
        return ! $user->isSuspended() && $user->hasConfirmedTwoFactor();
    }

    private function forgetChallenge(): void
    {
        $this->challengedUser = null;
        $this->session()->forget(['login.id', 'login.remember']);
    }
}
