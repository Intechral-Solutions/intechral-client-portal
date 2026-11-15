<?php

namespace App\Shared\Mfa;

use App\Http\Middleware\EnforceOperatorMfa;
use App\Models\User;
use App\Shared\Pilot\CustomerPilotGate;

/**
 * The Release 1.0 operator second-factor boundary (docs/architecture/operator-mfa.md).
 *
 * **Policy.** An account that holds `portal.operator` ({@see CustomerPilotGate::isOperator()}, the one test of operator eligibility) must have a
 * CONFIRMED second factor before it reaches anything privileged. Enforcement is here, at the application boundary, and not at sign-in: a password or
 * SSO sign-in still succeeds (it is the first factor and, for an account that has not yet enrolled, the only one there is), and the account then reaches
 * the pages it needs to enrol and nothing else. There is no grace period and no configuration switch in any deployed environment.
 *
 * **Confirmed** means {@see User::hasConfirmedTwoFactor()}: a stored secret AND a confirmation time. A secret that was generated but whose code was
 * never entered (Fortify's `confirm` option) is an enrolment in progress and does not count, which is also what decides whether Fortify
 * challenges the sign-in. Provider-side MFA never substitutes: SSO is the first factor only.
 *
 * **What an unconfirmed operator may reach** is the exact list of route names in {@see ALLOWED_ROUTES}: sign out, the profile (where enrolment
 * lives), the password-confirmation step Fortify puts in front of the enrolment endpoints, and the enrolment endpoints themselves. It is an
 * ALLOW-list, so a route added later (outside `/operator` included, named or not) is refused to such an operator until a human adds it here and the
 * pinned test changes. It is enforced by {@see EnforceOperatorMfa}; hiding navigation is never the control.
 *
 * **Why the eligibility is re-read on every request.** The middleware looks at the user row the session just loaded, not at anything remembered in the
 * session, so a disabled second factor, a reset by `user:reset-two-factor`, a newly granted operator role or a revoked one takes effect on the very
 * next request (the permission lookup itself is the database-store cache, invalidated by role changes).
 *
 * **Test switch.** {@see enforced()} can be turned off by `config('operator_mfa.enforced')` ONLY when APP_ENV is `testing`, so that the many
 * suites whose subject is some other operator feature do not each have to enrol an operator. Anywhere else the value is ignored: enforcement is
 * unconditional. (Contrast the customer surface mode, which is configurable: this is a security floor, not a rollout choice.)
 */
final class OperatorMfaGate
{
    /**
     * Route names an operator WITHOUT a confirmed second factor may reach, nothing else.
     *
     * `root` only redirects. The profile is where enrolment happens; `profile.password.confirm` and Fortify's `password.confirm*` are the
     * fresh-password step that stands in front of every enrolment endpoint; `two-factor.*` are Fortify's own enrolment endpoints. `two-factor.disable`
     * is included deliberately: it only moves the account back to "not enrolled", which this gate already treats as no privileged access.
     * Provider linking (`sso.*`), the dashboard and every other route are deliberately absent, and so is `user-profile-information.update`: changing the email
     * takes effect at once (no verification step), and with the password-reset flow it would let someone holding only a stolen, not-yet-enrolled session
     * take the account over (operator MFA review S1). `user-password.update` stays: it demands the current password.
     *
     * @var list<string>
     */
    public const ALLOWED_ROUTES = [
        'root',
        'logout',
        'profile.show',
        'profile.password.confirm',
        'profile.sessions.destroy',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'user-password.update',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
    ];

    /** The machine-readable reason a JSON refusal carries. */
    public const CODE = 'operator_two_factor_required';

    public const MESSAGE = 'Operator accounts must set up two-factor authentication before using operator features.';

    public static function enforced(): bool
    {
        return ! app()->environment('testing') || config('operator_mfa.enforced', true) !== false;
    }

    /**
     * Whether this actor must be refused anything outside {@see ALLOWED_ROUTES} right now: an operator without a confirmed second factor.
     * The cheap column test is first, so an account that has confirmed one never pays for the permission lookup.
     */
    public static function blocks(?User $user): bool
    {
        return $user !== null
            && self::enforced()
            && ! $user->hasConfirmedTwoFactor()
            && CustomerPilotGate::isOperator($user);
    }

    public static function allowsRoute(?string $routeName): bool
    {
        return $routeName !== null && in_array($routeName, self::ALLOWED_ROUTES, true);
    }
}
