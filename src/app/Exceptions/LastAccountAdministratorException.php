<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Release 0.9 (H3): a suspension or demotion was refused because it would leave no active account administrator.
 * Thrown inside the guarded transaction, so nothing the mutation did is kept.
 */
class LastAccountAdministratorException extends RuntimeException
{
    public const MESSAGE = 'This change would leave the portal without an active account administrator (an active user '
        .'who can view and manage users). Give another active user that access first.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    /** A conflict: HTTP 409 for a JSON caller, and the admin forms' convention (back, with the error) for a browser. */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], 409);
        }

        return back()->withErrors(['account' => self::MESSAGE]);
    }
}
