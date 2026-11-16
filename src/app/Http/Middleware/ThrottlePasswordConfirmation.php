<?php

namespace App\Http\Middleware;

use App\Auth\PasswordConfirmationThrottle;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies {@see PasswordConfirmationThrottle} to the three routes that verify the current password ({@see PasswordConfirmationThrottle::ROUTES}) and to nothing else.
 *
 * It is a web-group middleware that keys on the route NAME rather than a change to Fortify's route definition: the route is registered by the
 * package, and a modification made to it at boot would be lost under `route:cache`. It runs after the session has started, so the signed-in account
 * is known; a guest passes straight through to the `auth` middleware that redirects them (a guest has no account to limit and the limit would
 * otherwise be a shared bucket).
 *
 * A refusal keeps each operation's own failure shape, so no page needs a change: a browser or Inertia form is sent back with the message on the field and
 * in the error bag where a wrong password already appears for THAT operation (see ROUTES), and a JSON caller gets 429 with `Retry-After`. Nothing is
 * validated, changed or deleted on a refused attempt, so a correct password presented while limited is refused exactly like a wrong one: the refusal
 * does not depend on the password, so it tells a caller nothing about it. A guest and a signed-out caller never reach it (the `auth` middleware answers them
 * as before), and a stale operator session is signed out by the MFA boundary first, so it neither consumes nor learns anything about the allowance.
 */
final class ThrottlePasswordConfirmation
{
    public function handle(Request $request, Closure $next): Response
    {
        $target = PasswordConfirmationThrottle::ROUTES[$request->route()?->getName()] ?? null;

        if ($target === null || $request->user() === null) {
            return $next($request);
        }

        try {
            // Resolved here, for the routes that need it, so every other request constructs nothing.
            return app(ThrottleRequests::class)->handle($request, $next, PasswordConfirmationThrottle::NAME);
        } catch (ThrottleRequestsException $exception) {
            $seconds = (int) ($exception->getHeaders()['Retry-After'] ?? 60);
            $message = __('Too many password attempts. Try again in :seconds seconds.', ['seconds' => max($seconds, 1)]);

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'errors' => [$target['field'] => [$message]]], 429, $exception->getHeaders());
            }

            return redirect()->back()->withErrors([$target['field'] => $message], $target['bag'])->withHeaders($exception->getHeaders());
        }
    }
}
