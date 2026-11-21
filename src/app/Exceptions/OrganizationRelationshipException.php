<?php

namespace App\Exceptions;

use App\Support\Organizations\PortalState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A managed-organization rule refused an operation (Release 1.0, MO-1A; App\Services\OrganizationRelationships). Nothing was
 * changed. `reason` is a stable code for tests and callers; the message is written for the operator.
 *
 * Rendered like the other business refusals: HTTP 409 with the message for a JSON caller, and back with the error under
 * `field` ("relationship" for a relationship operation, "company" for the enablement refusals on the Directory pages, whose name is transitional until ORG-3) for a browser.
 */
final class OrganizationRelationshipException extends RuntimeException
{
    public const SELF = 'self';

    public const NOT_PARTNER = 'not_partner';

    public const IS_PARTNER = 'is_partner';

    public const IS_MANAGED = 'is_managed';

    public const ALREADY_MANAGED = 'already_managed';

    public const HAS_CUSTOMERS = 'has_customers';

    /** Release 1.0 (ORG-1): the portal-state lifecycle. Every one is a refusal made BEFORE the database could be asked to violate a CHECK. */
    public const PORTAL_NOT_ENABLED = 'portal_not_enabled';

    public const PORTAL_DISABLED = 'portal_disabled';

    public const ENABLEMENT_REFUSED = 'enablement_refused';

    /** Initial enablement of an Organization that is already Enabled: a controlled refusal with no side effect (ORG-2; ADR-011 amendment 1, ruling 4). */
    public const ALREADY_ENABLED = 'already_enabled';

    private function __construct(string $message, public readonly string $reason, public readonly string $field = 'relationship')
    {
        parent::__construct($message);
    }

    /** A relationship act (designate, link, reassign) against an Organization that is not Enabled. Removing one is never refused. */
    public static function portalRefused(string $organization, PortalState $state): self
    {
        return $state->isDisabled()
            ? new self("\"{$organization}\" is disabled. Re-enable it before designating it or linking it; removing a partner designation or a link stays possible.", self::PORTAL_DISABLED)
            : new self("\"{$organization}\" has no portal access, so it cannot be designated as a partner or linked. Enable portal access first.", self::PORTAL_NOT_ENABLED);
    }

    /** Disable or re-enable asked of an Organization that has never had portal access: it has no portal state to change. */
    public static function neverEnabled(string $organization): self
    {
        return new self("\"{$organization}\" has never had portal access, so it cannot be disabled or re-enabled. Enable portal access first.", self::PORTAL_NOT_ENABLED);
    }

    /** Initial enablement of an Organization that is ALREADY Enabled. It is not idempotent: re-enabling is a different act (`manageRelationships`), and a second initial enablement would re-record who enabled it and when. */
    public static function alreadyEnabled(string $organization): self
    {
        return new self("\"{$organization}\" already has portal access. Nothing was changed.", self::ALREADY_ENABLED, 'company');
    }

    /** Initial enablement of an Organization that is Disabled: enabling never clears a disabled state (re-enable is a separate act), and never starts from a row that is not the one it created. */
    public static function enablementRefused(string $organization, PortalState $state): self
    {
        $message = $state->isDisabled()
            ? "\"{$organization}\" already has portal access and it is disabled. Enabling portal access never clears that; use Re-enable on the organization page."
            : "\"{$organization}\" cannot have portal access enabled from here.";

        return new self($message, self::ENABLEMENT_REFUSED, 'company');
    }

    public static function self(string $organization): self
    {
        return new self("\"{$organization}\" cannot be managed by itself.", self::SELF);
    }

    public static function notPartner(string $organization): self
    {
        return new self("\"{$organization}\" is not designated as a partner, so it cannot manage customer organizations. Designate it as a partner first.", self::NOT_PARTNER);
    }

    public static function isPartner(string $organization): self
    {
        return new self("\"{$organization}\" is designated as a partner, and a partner cannot itself be managed. Remove its partner designation first.", self::IS_PARTNER);
    }

    public static function isManaged(string $organization, string $manager): self
    {
        return new self("\"{$organization}\" is managed by \"{$manager}\", and a managed organization cannot be a partner. Unlink it first.", self::IS_MANAGED);
    }

    public static function alreadyManaged(string $organization, string $manager): self
    {
        return new self("\"{$organization}\" is already managed by \"{$manager}\" (it may have been linked since this page was loaded). Nothing was changed. Reload, then use Reassign if you mean to move it.", self::ALREADY_MANAGED);
    }

    public static function hasCustomers(string $organization, int $customers): self
    {
        return new self("\"{$organization}\" still manages {$customers} customer organization(s). Unlink or reassign them before removing its partner designation.", self::HAS_CUSTOMERS);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 409);
        }

        return back()->withErrors([$this->field => $this->getMessage()]);
    }
}
