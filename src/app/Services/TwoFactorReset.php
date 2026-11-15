<?php

namespace App\Services;

use App\Http\Requests\Auth\ActiveAccountTwoFactorLoginRequest;
use App\Models\User;
use App\Shared\Mfa\OperatorMfaGate;
use App\Shared\Pilot\CustomerPilotGate;
use Illuminate\Support\Facades\DB;

/**
 * Release 1.0: administrative recovery of an account that has lost BOTH its authenticator and its recovery codes (the `user:reset-two-factor`
 * Artisan command, the only caller). docs/architecture/operator-mfa.md.
 *
 * It removes the second factor and nothing else. It sets no password, signs nobody in, grants nothing and cannot be reached over HTTP: the
 * authorization is shell access to the server. Because an operator without a confirmed second factor is limited to the profile pages
 * ({@see OperatorMfaGate}), the reset is also what forces re-enrolment before privileged access resumes.
 *
 * In ONE transaction, under a row lock on the account, it
 *
 *  - clears the secret, the recovery codes and the confirmation time (all three, so no confirmed-looking remnant survives);
 *  - signs the account out everywhere ({@see DatabaseSessionManager::revokeAll()}: stored sessions deleted, remember-me token rotated, outstanding
 *    password-reset tokens deleted), which also discards any password-confirmation or SSO fresh-authentication proof those sessions held; and
 *  - records who, why and what in the activity log.
 *
 * It fails closed: with a session driver other than `database` the sessions cannot be revoked, `revokeAll()` throws, and the account is unchanged.
 * A sign-in parked at the two-factor challenge holds no session row to delete, so it is refused at the challenge itself (the challenged user must
 * have a confirmed second factor: {@see ActiveAccountTwoFactorLoginRequest}).
 *
 * The audit entry holds the account, the reason, the operating-system user who ran the command and booleans about what was there. It never holds a
 * secret, a recovery code or a code.
 */
final class TwoFactorReset
{
    public const EVENT = 'reset two-factor authentication';

    public const SOURCE = 'user:reset-two-factor';

    public function __construct(private readonly DatabaseSessionManager $sessions) {}

    /**
     * Whether the account holds any second-factor state to clear (a confirmed factor or an enrolment still in progress).
     */
    public static function hasState(User $user): bool
    {
        return filled($user->two_factor_secret) || filled($user->two_factor_recovery_codes) || $user->two_factor_confirmed_at !== null;
    }

    /**
     * Returns true when state was cleared, false when the account held none (nothing changes and nothing is logged).
     *
     * @param  string  $runBy  the operating-system user running the command (recorded as given)
     *
     * @throws \RuntimeException when sessions cannot be revoked (account unchanged)
     */
    public function reset(User $target, string $reason, string $runBy): bool
    {
        return DB::transaction(function () use ($target, $reason, $runBy) {
            $account = User::query()->lockForUpdate()->findOrFail($target->getKey());

            if (! self::hasState($account)) {
                return false;
            }

            $wasConfirmed = $account->hasConfirmedTwoFactor();

            $account->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            $sessionsRevoked = $this->sessions->revokeAll($account);

            activity()
                ->performedOn($account)
                ->withProperties([
                    'source' => self::SOURCE,
                    'reason' => $reason,
                    'run_by' => $runBy,
                    'was_confirmed' => $wasConfirmed,
                    'target_was_operator' => CustomerPilotGate::isOperator($account),
                    'sessions_revoked' => $sessionsRevoked,
                ])
                ->log(self::EVENT);

            $target->setRawAttributes($account->getAttributes(), true);

            return true;
        });
    }
}
