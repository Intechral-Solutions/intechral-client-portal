<?php

namespace App\Shared\Mfa;

use App\Models\User;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;

/**
 * Fortify's password sign-in loads the user TWICE: this step (`RedirectIfTwoFactorAuthenticatable`) validates the password and decides whether to
 * challenge, and the next one (`AttemptToAuthenticate` -> `attempt()`) loads and checks the user again before `login()`. Each password check costs a
 * hash, so a confirmation of the account's second factor can commit between the two. The `Login` stamp would then come from the SECOND copy, the new
 * epoch, and a first-factor-only sign-in that the first copy judged "no challenge needed" would be privileged (operator MFA session review B1).
 *
 * This subclass changes nothing about the decision. When the first copy leads to no challenge it records the epoch it saw in the session, and
 * {@see StampSessionEpoch} stamps that instead. A sign-in that straddles a confirmation therefore carries the OLD epoch and is refused at the gate.
 */
class EpochRecordingTwoFactorRedirect extends RedirectIfTwoFactorAuthenticatable
{
    private ?User $seen = null;

    public function handle($request, $next)
    {
        return parent::handle($request, function ($request) use ($next) {
            if ($this->seen !== null && $request->hasSession()) {
                $request->session()->put(OperatorMfaGate::PENDING_KEY, [
                    'id' => $this->seen->getKey(),
                    'epoch' => (string) $this->seen->session_epoch,
                ]);
            }

            return $next($request);
        });
    }

    protected function validateCredentials($request)
    {
        $user = parent::validateCredentials($request);
        $this->seen = $user instanceof User ? $user : null;

        return $user;
    }
}
