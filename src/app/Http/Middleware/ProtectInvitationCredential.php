<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Release 1.0 (R1-3B): the response protections of every request that carries an invitation token in its URL (docs/product/
 * release-1.0-r1-3-organization-invitations.md, 5.8): the registration page (`invitation.show`), its POST (`invitation.register`) and the
 * single sign-on initiation (`sso.redirect`) WHEN it carries an `invitation` query. A request on `sso.redirect` without one is ordinary SSO
 * login and is left exactly as it was (`query` mode).
 *
 *  - `Referrer-Policy: no-referrer`, on the redirect to the identity provider too, so the provider and every page the registration page links
 *    to never receive the token-bearing URL as a referrer.
 *  - `Cache-Control: no-store`, so no browser, proxy or back-forward cache keeps the page (its props repeat the token).
 *  - The token-bearing URL is not recorded as the session's "previous URL". The framework records every full-page GET there, in the SESSION
 *    STORE (`StartSession::storeCurrentUrl`), unless the request is a prefetch; the token would otherwise sit in the sessions table until
 *    the next navigation. Marking the request as a prefetch BEFORE it runs is the framework's own switch for "not a navigation to remember"
 *    and nothing else in this application reads that mark. A test pins the outcome (the stored session payload holds no token), so a
 *    framework change to that rule fails loudly instead of silently re-leaking.
 *
 * Because that previous URL is gone, and the page's `Referrer-Policy` means the browser sends no `Referer` on the POST either, a failed
 * registration cannot rely on `back()`: the controller names its redirect target itself.
 */
final class ProtectInvitationCredential
{
    public function handle(Request $request, Closure $next, string $when = 'always'): Response
    {
        if ($when === 'query' && ! $request->query->has('invitation')) {
            return $next($request);
        }

        $request->headers->set('Sec-Purpose', 'prefetch');

        $response = $next($request);

        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
