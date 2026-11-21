<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A Directory rule refused an operation on an Organization (Release 1.0, ORG-2; App\Services\OrganizationDirectory). Nothing was changed. `reason` is a stable
 * code for tests and callers; the message is written for the operator and names counts, never another record.
 *
 * It replaces CompanyHasProjectsException (the Company deletion guard) now that there is one Organization entity. Rendered like the other business refusals:
 * HTTP 409 with the message for a JSON caller, and back with the error under `field` for a browser (`company` while the Directory pages keep their
 * transitional name, until ORG-3).
 */
final class OrganizationDirectoryException extends RuntimeException
{
    /** Only an Organization that never had portal access can be deleted. */
    public const HAS_PORTAL_HISTORY = 'has_portal_history';

    /** A Project is linked to it. */
    public const HAS_PROJECTS = 'has_projects';

    /** Members, invitations, service requests or a relationship refer to it. */
    public const HAS_DEPENDANTS = 'has_dependants';

    /** The database refused the delete because a row somewhere still refers to it (the RESTRICT keys are the backstop). */
    public const STILL_REFERENCED = 'still_referenced';

    private function __construct(string $message, public readonly string $reason, public readonly string $field = 'company')
    {
        parent::__construct($message);
    }

    public static function hasPortalHistory(string $organization): self
    {
        return new self("Cannot delete \"{$organization}\": it has (or had) portal access. Only an Organization that never had portal access can be deleted; disable it instead.", self::HAS_PORTAL_HISTORY);
    }

    public static function hasProjects(string $organization, int $projects): self
    {
        return new self("Cannot delete \"{$organization}\": {$projects} project(s) still refer to it. Remove the Organization from those projects first.", self::HAS_PROJECTS);
    }

    /** @param  array<string, int>  $counts  what refers to it, by kind (members, invitations, requests, relationships), only the non-zero ones */
    public static function hasDependants(string $organization, array $counts): self
    {
        $parts = [];

        foreach ($counts as $kind => $n) {
            $parts[] = "{$n} {$kind}";
        }

        return new self("Cannot delete \"{$organization}\": it still has ".implode(', ', $parts).'. Nothing was changed.', self::HAS_DEPENDANTS);
    }

    public static function stillReferenced(string $organization): self
    {
        return new self("Cannot delete \"{$organization}\": a record still refers to it. Nothing was changed.", self::STILL_REFERENCED);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 409);
        }

        return back()->withErrors([$this->field => $this->getMessage()]);
    }
}
