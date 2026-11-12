<?php

namespace App\Services;

use App\Exceptions\LastAccountAdministratorException;
use App\Exceptions\OwnAccountSuspensionException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Release 0.9 (H3): suspending and reactivating accounts, the only supported offboarding.
 *
 * Suspension keeps the user row and id, every business record and attribution, and every role and direct permission.
 * It sets `users.disabled_at`, signs the user out everywhere, and records who, when and why in the activity log.
 * Reactivation clears it and signs the user out everywhere again, so access resumes only through a fresh sign-in with
 * the ordinary credential and two-factor requirements; it grants nothing. Both are idempotent: repeating one changes
 * nothing and logs nothing. Enforcement at sign-in, on every request, in candidate lists and in notifications is
 * described in docs/architecture/account-lifecycle.md.
 *
 * Suspension also clears any partner support designation the account holds (Release 1.0, R1-4; {@see PartnerSupportGrants::revokeHeldBy()}).
 *
 * Both also permanently revoke the organization invitations the account issued that are still open (Release 1.0, R1-3A; see
 * {@see OrganizationInvitations}): suspension because the issuer lost authority, reactivation so that an invitation that survived a race with
 * the suspension can never come back. They run in the existing transaction, take no organization lock and never write the invitation's uniqueness slot (the revoked row keeps it until the next issuance releases it).
 */
final class AccountLifecycleService
{
    public const SUSPENDED = 'suspended account';

    public const REACTIVATED = 'reactivated account';

    public function __construct(
        private readonly AccountAdministrators $administrators,
        private readonly DatabaseSessionManager $sessions,
        private readonly OrganizationInvitations $invitations,
        private readonly PartnerSupportGrants $supportGrants,
    ) {}

    /**
     * Returns true when the account was suspended now, false when it already was (its original suspension time and
     * record are kept).
     *
     * @throws OwnAccountSuspensionException
     * @throws LastAccountAdministratorException
     */
    public function suspend(User $target, User $actor, string $reason): bool
    {
        if ($target->is($actor)) {
            throw new OwnAccountSuspensionException;
        }

        return $this->administrators->guard(function () use ($target, $actor, $reason) {
            $account = User::query()->lockForUpdate()->findOrFail($target->getKey());

            if ($account->isSuspended()) {
                return false;
            }

            $account->forceFill(['disabled_at' => now()])->save();
            $this->sessions->revokeAll($account);

            // R1-3A: the open organization invitations this account issued are revoked, permanently, in this same transaction.
            $this->invitations->revokeIssuedBy([$account->getKey() => OrganizationInvitations::REASON_ISSUER_SUSPENDED], OrganizationInvitations::SOURCE_SUSPENSION, $actor);

            // R1-4: a partner support designation ends with the suspension, permanently, in this same transaction, so a reactivation cannot revive it.
            // (Access already stops at `disabled_at`; this makes the end permanent.) It takes the user row's exclusive lock, held above, as its serialization point.
            $this->supportGrants->revokeHeldBy((int) $account->getKey(), $actor);

            activity()->causedBy($actor)
                ->performedOn($account)
                ->withProperties(['reason' => $reason])
                ->log(self::SUSPENDED);

            $target->setRawAttributes($account->getAttributes(), true);

            return true;
        });
    }

    /** Returns true when the account was reactivated now, false when it was already active. */
    public function reactivate(User $target, User $actor, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($target, $actor, $reason) {
            $account = User::query()->lockForUpdate()->findOrFail($target->getKey());

            if (! $account->isSuspended()) {
                return false;
            }

            $suspendedAt = $account->disabled_at;
            $account->forceFill(['disabled_at' => null])->save();
            $this->sessions->revokeAll($account);

            // R1-3A: an invitation still open at this moment was issued before the loss, or in a race with it. It must not come back.
            $this->invitations->revokeIssuedBy([$account->getKey() => OrganizationInvitations::REASON_ISSUER_REINSTATED], OrganizationInvitations::SOURCE_REACTIVATION, $actor);

            activity()->causedBy($actor)
                ->performedOn($account)
                ->withProperties(array_filter([
                    'reason' => $reason,
                    'suspended_at' => $suspendedAt->toIso8601String(),
                ], fn ($value) => $value !== null && $value !== ''))
                ->log(self::REACTIVATED);

            $target->setRawAttributes($account->getAttributes(), true);

            return true;
        });
    }
}
