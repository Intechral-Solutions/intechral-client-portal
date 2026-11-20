<?php

namespace App\Exceptions;

use App\Support\Organizations\PortalState;
use RuntimeException;

/**
 * An organization-invitation rule refused an operation (Release 1.0, R1-3A; App\Services\OrganizationInvitations). Nothing was changed
 * (the transaction rolled back, and a refused acceptance writes nothing).
 *
 * `reason` is the stable code a caller may act on. For ACCEPTANCE every dead link is the same `unavailable` reason and the same message,
 * because the page that answers a dead link must not tell a stranger which kind of dead it is (unknown, expired, revoked, accepted, "the
 * address already has an account", "the issuer is no longer authorized"). The specific cause is kept in `detail`, for tests and for the
 * operator's logs, and is NEVER rendered to the person holding the link.
 */
final class OrganizationInvitationException extends RuntimeException
{
    /** Issuance: the address already belongs to an account (operator-only message; use Add member). */
    public const ACCOUNT_EXISTS = 'account_exists';

    /** Issuance: another invitation for the address took the slot at the same moment (the lock should make this unreachable). */
    public const CONCURRENT_ISSUE = 'concurrent_issue';

    /** Acceptance: the link cannot be used. One reason for every dead link; the cause is in `detail`. */
    public const UNAVAILABLE = 'unavailable';

    /** Acceptance by identity provider: the provider's address is not the invited one. */
    public const EMAIL_MISMATCH = 'email_mismatch';

    /** Issuance: the Organization is not Enabled (Release 1.0, ORG-1): never enabled, or Disabled. Operator-only message; the cause is in `detail`. */
    public const PORTAL_REFUSED = 'portal_refused';

    /** Revocation: no such invitation in this organization (a cross-organization id is not found, never "forbidden"). */
    public const NOT_FOUND = 'not_found';

    private function __construct(string $message, public readonly string $reason, public readonly ?string $detail = null)
    {
        parent::__construct($message);
    }

    public static function accountExists(): self
    {
        return new self('That address already belongs to an account. Use Add member on the organization page instead of an invitation.', self::ACCOUNT_EXISTS);
    }

    public static function concurrentIssue(): self
    {
        return new self('Another invitation for this address was issued at the same moment. Check the invitations list and try again.', self::CONCURRENT_ISSUE);
    }

    public static function portalRefused(string $organization, PortalState $state): self
    {
        $message = $state->isDisabled()
            ? "\"{$organization}\" is disabled, so it cannot be invited into. Re-enable it first; invitations that were pending when it was disabled were revoked and stay revoked."
            : "\"{$organization}\" has no portal access, so it cannot be invited into. Enable portal access first.";

        return new self($message, self::PORTAL_REFUSED, $state->value);
    }

    public static function unavailable(string $detail): self
    {
        return new self('This invitation is no longer available.', self::UNAVAILABLE, $detail);
    }

    public static function emailMismatch(): self
    {
        return new self('The email address of that account does not match the invitation.', self::EMAIL_MISMATCH);
    }

    public static function notFound(): self
    {
        return new self('There is no such invitation for this organization.', self::NOT_FOUND);
    }
}
