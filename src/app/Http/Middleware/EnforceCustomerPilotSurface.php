<?php

namespace App\Http\Middleware;

use App\Shared\Pilot\CustomerPilotGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The server-side half of the Release 0.9 customer-surface boundary (R0.9-CS1). See {@see CustomerPilotGate}.
 *
 * Runs on every web request, after the session has identified the user and before route-model binding, so a
 * refused route resolves no record and reaches no controller. A signed-in customer (any user without
 * `portal.operator`) may reach only the routes the effective mode names, closed by default; every other route,
 * unnamed ones included, answers 404 (the same answer an absent module gives, whatever the verb, the identifier or
 * the Accept header). An allowed route that additionally needs a capability the customer lacks answers 403, before
 * any record is loaded, so that answer cannot depend on which identifier was asked for.
 *
 * Guests, operators, legacy mode and unauthenticated external callers (the Stripe webhook) pass untouched.
 */
final class EnforceCustomerPilotSurface
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! CustomerPilotGate::restricts($request->user())) {
            return $next($request);
        }

        $name = $request->route()?->getName();

        abort_unless(CustomerPilotGate::allowsRoute($name), 404);

        $capability = CustomerPilotGate::requiredCapability($name);

        abort_if($capability !== null && ! $request->user()->can($capability), 403);

        return $next($request);
    }
}
