<?php

namespace App\Http\Requests\Auth;

use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

/**
 * Release 0.9 (H3). Fortify finds the challenged user with a plain model lookup of `login.id`, not through the user
 * provider, so a password step completed before a suspension could otherwise be finished after it. A suspended
 * challenged user is treated exactly as a missing one: the pending challenge is dropped and the caller gets Fortify's
 * own "no challenge in progress" response, before any code or recovery code is checked or consumed.
 */
class ActiveAccountTwoFactorLoginRequest extends TwoFactorLoginRequest
{
    public function hasChallengedUser()
    {
        if (! parent::hasChallengedUser()) {
            return false;
        }

        if (! parent::challengedUser()->isSuspended()) {
            return true;
        }

        $this->forgetChallenge();

        return false;
    }

    public function challengedUser()
    {
        $user = parent::challengedUser();

        if ($user->isSuspended()) {
            $this->forgetChallenge();

            throw new HttpResponseException(app(FailedTwoFactorLoginResponse::class)->toResponse($this));
        }

        return $user;
    }

    private function forgetChallenge(): void
    {
        $this->challengedUser = null;
        $this->session()->forget(['login.id', 'login.remember']);
    }
}
