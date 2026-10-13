<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Release 0.9 (H4): ordinary administration tried to change or delete a built-in role. Built-in roles are owned by the
 * application and change only through the reviewed synchronization (`php artisan roles:sync`).
 */
class BuiltInRoleException extends RuntimeException
{
    public function __construct(public readonly string $role)
    {
        parent::__construct("The \"{$role}\" role is built-in. The application owns its permissions and it cannot be "
            .'changed or deleted here. Create a custom role instead, or ask for a reviewed role synchronization.');
    }

    /** A conflict: HTTP 409 for a JSON caller, and the admin forms' convention (back, with the error) for a browser. */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 409);
        }

        return back()->withErrors(['role' => $this->getMessage()]);
    }
}
