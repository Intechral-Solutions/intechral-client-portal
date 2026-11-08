<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A membership rule refused an operation (Release 1.0, MO-2A1; App\Services\OrganizationMembership). Nothing was changed.
 * `reason` is a stable code for tests and callers; the message is written for the operator.
 *
 * Rendered like the other business refusals: HTTP 409 with the message for a JSON caller, and back with the error under
 * `membership` for a browser.
 */
final class OrganizationMembershipException extends RuntimeException
{
    public const ALREADY_MEMBER = 'already_member';

    public const NOT_MEMBER = 'not_member';

    public const NOT_ADMIN_ROLE = 'not_admin_role';

    public const ACCOUNT_SUSPENDED = 'account_suspended';

    public const OPERATOR_ACCOUNT = 'operator_account';

    /** The membership carries a half-written approval (a time without an approver, or the reverse). Revoke it, then approve again. */
    public const INCOMPLETE_APPROVAL = 'incomplete_approval';

    /** An ordinary demotion or removal would leave an organization with no active approved administrator. Never overridable. */
    public const LAST_APPROVED_ADMIN = 'last_approved_admin';

    /** An explicit revocation would leave none; it is refused unless the operator confirmed that intent. */
    public const LAST_APPROVAL_UNCONFIRMED = 'last_approval_unconfirmed';

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function alreadyMember(string $user, string $organization): self
    {
        return new self("{$user} is already a member of \"{$organization}\". Adding never changes an existing membership; promote, demote or remove it instead.", self::ALREADY_MEMBER);
    }

    public static function notMember(string $user, string $organization): self
    {
        return new self("{$user} is not a member of \"{$organization}\".", self::NOT_MEMBER);
    }

    public static function notAdminRole(string $user): self
    {
        return new self("{$user} is not an administrator of this organization. Only a membership with the administrator role can be approved.", self::NOT_ADMIN_ROLE);
    }

    public static function suspended(string $user): self
    {
        return new self("{$user}'s account is suspended. Only an active account can be added or approved.", self::ACCOUNT_SUSPENDED);
    }

    public static function operatorAccount(string $user): self
    {
        return new self("{$user} is an operator. Operators act with platform authority and cannot be approved as an organization administrator.", self::OPERATOR_ACCOUNT);
    }

    public static function incompleteApproval(string $user): self
    {
        return new self("{$user}'s membership carries an incomplete approval record (a time without an approver, or an approver without a time). It does not count as an approval and cannot be completed in place. Revoke the approval first, then approve again if the owner has reviewed this administrator.", self::INCOMPLETE_APPROVAL);
    }

    public static function lastApprovedAdmin(string $organization): self
    {
        return new self("This would leave \"{$organization}\" without an active approved administrator. Approve another administrator first. (Only an operator's explicit \"revoke approval\" can remove the last one.)", self::LAST_APPROVED_ADMIN);
    }

    public static function lastApprovalUnconfirmed(string $organization): self
    {
        return new self("Revoking this approval leaves \"{$organization}\" without an active approved administrator. Nothing was changed. Confirm that you intend this to proceed.", self::LAST_APPROVAL_UNCONFIRMED);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 409);
        }

        return back()->withErrors(['membership' => $this->getMessage()]);
    }
}
