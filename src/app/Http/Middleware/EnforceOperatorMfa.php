<?php

namespace App\Http\Middleware;

use App\Shared\Mfa\OperatorMfaGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The server-side half of the operator second-factor boundary (Release 1.0). See {@see OperatorMfaGate}.
 *
 * Runs on every web request, after the session has identified the user and before route-model binding, so a refused route resolves no record, reaches
 * no controller and cannot tell the caller which ids exist. Guests, customers and operators with a confirmed second factor pass untouched.
 *
 * What the refusal looks like depends on the caller, and none of them performs the action:
 *
 *  - a JSON caller (`Accept: application/json`) gets 403 with {@see OperatorMfaGate::CODE};
 *  - everything else (a browser page, an Inertia visit, a form post, a download) is redirected to the profile, where the second factor is set up,
 *    with the reason flashed. Inertia turns that redirect into a 303 for PUT, PATCH and DELETE, which is why this middleware must sit INSIDE
 *    HandleInertiaRequests (registered after it). The profile is on the allow-list, so the redirect cannot loop.
 */
final class EnforceOperatorMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! OperatorMfaGate::blocks($request->user())
            || OperatorMfaGate::allowsRoute($request->route()?->getName())) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => OperatorMfaGate::MESSAGE,
                'code' => OperatorMfaGate::CODE,
            ], 403);
        }

        return redirect()->route('profile.show')->with('error', OperatorMfaGate::MESSAGE);
    }
}
