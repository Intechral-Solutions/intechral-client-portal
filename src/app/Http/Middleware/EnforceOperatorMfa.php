<?php

namespace App\Http\Middleware;

use App\Shared\Mfa\OperatorMfaGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The server-side half of the operator second-factor boundary (Release 1.0). See {@see OperatorMfaGate}.
 *
 * Runs on every web request, after the session has identified the user and before route-model binding, so a refused route resolves no record, reaches
 * no controller and cannot tell the caller which ids exist. Guests, customers and operators with a confirmed second factor pass untouched.
 *
 * What the refusal looks like depends on the caller, and none of them performs the action:
 *
 *  - a session opened under an EARLIER second-factor state (see {@see OperatorMfaGate::staleSession()}) is signed out first and sent to sign in (JSON: 401
 *    with {@see OperatorMfaGate::STALE_CODE});
 *  - a JSON caller (`Accept: application/json`) gets 403 with {@see OperatorMfaGate::CODE};
 *  - everything else (a browser page, an Inertia visit, a form post, a download) is redirected to the profile, where the second factor is set up,
 *    with the reason flashed. Inertia turns that redirect into a 303 for PUT, PATCH and DELETE, which is why this middleware must sit INSIDE
 *    HandleInertiaRequests (registered after it). The profile is on the allow-list, so the redirect cannot loop.
 */
final class EnforceOperatorMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($request->hasSession() && OperatorMfaGate::staleSession($user, $request->session())) {
            return $this->endStaleSession($request);
        }

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

    /**
     * A session opened under an earlier second-factor state is not an operator session at all: end it (this device only; other devices' remember-me
     * tokens are not touched) and make the person sign in, which presents the factor. Not the profile redirect: the allow-list there includes the
     * endpoints that CHANGE the factor, and a stale session must reach none of them.
     */
    private function endStaleSession(Request $request): Response
    {
        Auth::guard()->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => OperatorMfaGate::STALE_MESSAGE,
                'code' => OperatorMfaGate::STALE_CODE,
            ], 401);
        }

        return redirect()->route('login')->with('error', OperatorMfaGate::STALE_MESSAGE);
    }
}
