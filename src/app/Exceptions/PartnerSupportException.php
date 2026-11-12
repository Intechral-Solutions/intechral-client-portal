<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A partner-support rule refused an operation (Release 1.0, R1-4; App\Services\PartnerSupportGrants and OrganizationRelationships::disable()).
 * Nothing was changed. `reason` is a stable code for tests and callers; the message is written for the operator.
 *
 * Rendered like the other business refusals: HTTP 409 for a JSON caller, and back with the error under `support` for a browser.
 */
final class PartnerSupportException extends RuntimeException
{
    public const NOT_PARTNER = 'not_partner';

    public const NOT_MEMBER = 'not_member';

    public const ACCOUNT_SUSPENDED = 'account_suspended';

    public const OPERATOR_ACCOUNT = 'operator_account';

    public const ORGANIZATION_DISABLED = 'organization_disabled';

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function notPartner(string $organization): self
    {
        return new self("\"{$organization}\" is not a partner organization, so its members cannot be given support access.", self::NOT_PARTNER);
    }

    public static function notMember(string $user, string $organization): self
    {
        return new self("{$user} is not a member of \"{$organization}\".", self::NOT_MEMBER);
    }

    public static function suspended(string $user): self
    {
        return new self("{$user}'s account is suspended. Only an active account can be given support access.", self::ACCOUNT_SUSPENDED);
    }

    public static function operatorAccount(string $user): self
    {
        return new self("{$user} is an operator. Operators work from the operator surface and are never given partner support access.", self::OPERATOR_ACCOUNT);
    }

    public static function organizationDisabled(string $organization): self
    {
        return new self("\"{$organization}\" is disabled. Enable it before giving its members support access.", self::ORGANIZATION_DISABLED);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 409);
        }

        return back()->withErrors(['support' => $this->getMessage()]);
    }
}
