<?php

namespace App\Auth;

use App\Http\Middleware\ThrottlePasswordConfirmation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Limit on guessing the password through the three operations that verify the current password of a signed-in session (Release 1.0 session and authentication hardening): Fortify's confirmation `POST /user/confirm-password`, Fortify's password change `PUT /user/password` and `DELETE /profile/sessions` (see {@see ROUTES}).
 *
 * Each lets a signed-in session test password guesses with a side effect only on success, and the confirmation stands in front of two-factor enrolment, recovery-code
 * display and the profile's sensitive actions. The confirmation had no limit of its own (the sign-in form has Fortify's `login` limiter: 5 a minute per address
 * and email), and the other two are reachable by an operator who has NOT yet confirmed a second factor. One bucket across all three means a guesser cannot
 * alternate between them.
 *
 *  - Keyed PER ACCOUNT, never by address: the caller is signed in, so the account is known, and a guesser who changes address gains nothing (the same
 *    convention as {@see Sso\SsoThrottle}).
 *  - Two windows, {@see PER_MINUTE} and {@see PER_HOUR}: the first stops a burst, the second caps a patient guesser at a few hundred guesses a day
 *    per account. A mistyped password costs a person one or two attempts, so neither window is reachable by ordinary use.
 *  - It counts every attempt that is allowed to run, successes included (a person confirms once every few hours); a REFUSED attempt is not counted and
 *    does not extend the wait, so the wait is bounded by the window.
 *  - It is NOT an account lockout: sign-in, password reset, SSO and every other route are untouched; only these three operations pause, for at most the remainder
 *    of the window (the response says how long). It CAN be used to keep an owner out of them, because the windows are fixed and the allowance is shared: a holder of a
 *    session of the account can spend it at the start of each window. That includes the owner's own password change and sign-out-others, so the way out is not
 *    those: it is to wait, or to end every session of the account (`user:reset-two-factor` if the account holds any second-factor state; otherwise another administrator
 *    suspends and reactivates it). docs/architecture/operator-mfa.md section 6.
 *
 * The named limiter is applied by {@see ThrottlePasswordConfirmation}, because the route belongs to Fortify.
 */
final class PasswordConfirmationThrottle
{
    public const NAME = 'password-confirmation';

    public const PER_MINUTE = 5;

    public const PER_HOUR = 20;

    /**
     * The three operations that verify the account's CURRENT password for a signed-in session, each with where its refusal must appear so the page shows it
     * (the field the page already reads for a wrong password, and the error bag it posts under). They share ONE allowance per account (the limiter is
     * keyed on the account, not the route), so alternating between them does not multiply it.
     *
     *  - `password.confirm.store`: Fortify's confirmation, default bag, field `password`.
     *  - `user-password.update`: Fortify's password change, bag `updatePassword`, field `current_password`.
     *  - `profile.sessions.destroy`: our "sign out other sessions", bag `destroySessions`, field `password`.
     *
     * @var array<string, array{field: string, bag: string}>
     */
    public const ROUTES = [
        'password.confirm.store' => ['field' => 'password', 'bag' => 'default'],
        'user-password.update' => ['field' => 'current_password', 'bag' => 'updatePassword'],
        'profile.sessions.destroy' => ['field' => 'password', 'bag' => 'destroySessions'],
    ];

    /** @return list<Limit> */
    public static function limits(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [Limit::none()];
        }

        return [
            Limit::perMinute(self::PER_MINUTE)->by('minute:user:'.$user->getAuthIdentifier()),
            Limit::perHour(self::PER_HOUR)->by('hour:user:'.$user->getAuthIdentifier()),
        ];
    }

    /** Forget one account's counters (tests; the middleware derives its cache key as md5 of the limiter name and the limit key). */
    public static function clearFor(int $userId): void
    {
        foreach (['minute', 'hour'] as $window) {
            RateLimiter::clear(md5(self::NAME.$window.':user:'.$userId));
        }
    }
}
