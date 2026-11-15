<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Release 1.0 (operator MFA): a role change was refused because it would make an account with NO local password operator-eligible.
 *
 * Operators must confirm a second factor, Fortify's enrolment stands behind password confirmation, and a passwordless (provider-only) account can
 * neither confirm a password nor obtain one, so such an operator would be locked out of every privileged feature with no way to enrol. Owner ruling:
 * Release 1.0 operator accounts must have a local password. Thrown inside the guarded transaction, so nothing the mutation did is kept.
 */
class PasswordlessOperatorException extends RuntimeException
{
    public const MESSAGE = 'An operator needs a local password to set up two-factor authentication, and this change would give operator access to an account '
        .'that signs in only through a provider and has none. Nothing was changed.';

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
