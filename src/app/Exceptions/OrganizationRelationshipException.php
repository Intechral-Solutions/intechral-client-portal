<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A managed-organization rule refused an operation (Release 1.0, MO-1A; App\Services\OrganizationRelationships). Nothing was
 * changed. `reason` is a stable code for tests and callers; the message is written for the operator.
 *
 * Rendered like the other business refusals: HTTP 409 with the message for a JSON caller, and back with the error under
 * `field` ("relationship" for a relationship operation, "company" for the company-deletion guard) for a browser.
 */
final class OrganizationRelationshipException extends RuntimeException
{
    public const SELF = 'self';

    public const NOT_PARTNER = 'not_partner';

    public const IS_PARTNER = 'is_partner';

    public const IS_MANAGED = 'is_managed';

    public const ALREADY_MANAGED = 'already_managed';

    public const HAS_CUSTOMERS = 'has_customers';

    public const COMPANY_PAIRING = 'company_pairing';

    public const COMPANY_IN_RELATIONSHIP = 'company_in_relationship';

    private function __construct(string $message, public readonly string $reason, public readonly string $field = 'relationship')
    {
        parent::__construct($message);
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

    public static function companyPairing(string $organization, int $companies): self
    {
        $state = $companies === 0 ? 'has no CRM company' : "is linked to {$companies} CRM companies";

        return new self("\"{$organization}\" {$state}; a managed relationship needs exactly one. Resolve it in the CRM first.", self::COMPANY_PAIRING);
    }

    public static function companyInRelationship(string $company, string $organization): self
    {
        return new self("Cannot delete \"{$company}\": its organization \"{$organization}\" is designated as a partner or is managed by another organization. Remove the relationship first.", self::COMPANY_IN_RELATIONSHIP, 'company');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 409);
        }

        return back()->withErrors([$this->field => $this->getMessage()]);
    }
}
