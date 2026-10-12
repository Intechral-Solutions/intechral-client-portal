<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A CRM company that projects still reference cannot be deleted (the database enforces it with ON DELETE RESTRICT; the
 * application refuses first, with this). Nothing was changed. Not a project, company or archival policy (H6).
 */
class CompanyHasProjectsException extends RuntimeException
{
    public function __construct(public readonly string $company, public readonly int $projects)
    {
        parent::__construct("Cannot delete \"{$company}\": ".($projects > 0 ? "{$projects} project(s) still refer" : 'projects still refer')
            .' to it. Remove the company from those projects first.');
    }

    /** A conflict: HTTP 409 for a JSON caller, and the admin forms' convention (back, with the error) for a browser. */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 409);
        }

        return back()->withErrors(['company' => $this->getMessage()]);
    }
}
