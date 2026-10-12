<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Release 0.9 (H3): an operator cannot suspend their own account. */
class OwnAccountSuspensionException extends RuntimeException
{
    public const MESSAGE = 'You cannot suspend your own account.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], 422);
        }

        return back()->withErrors(['account' => self::MESSAGE]);
    }
}
