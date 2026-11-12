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
 *  - `<meta name="referrer" content="no-referrer">` in the document head (`app.blade.php`), switched on by the request attribute
 *    {@see self::ATTRIBUTE} that this middleware sets and nothing else sets. A header alone is not enough: a web server in front may APPEND
 *    its own `Referrer-Policy` (the development nginx did, and a browser applies the LAST of several header values), and Chromium then sent
 *    the full token-bearing URL as the `Referer` of every same-origin request the page made. The meta policy is the document's own and is
 *    not overridden by a header, so the token URL is never a referrer whatever the proxy adds. Ordinary pages never set the attribute and
 *    so never get the tag.
 *
 * Because that previous URL is gone, and the page's `Referrer-Policy` means the browser sends no `Referer` on the POST either, a failed
 * registration cannot rely on `back()`: the controller names its redirect target itself.
 */
final class ProtectInvitationCredential
{
    /** The request attribute the document root reads to render the referrer meta tag. Set here only. */
    public const ATTRIBUTE = 'credential_response';

    public function handle(Request $request, Closure $next, string $when = 'always'): Response
    {
        if ($when === 'query' && ! $request->query->has('invitation')) {
            return $next($request);
        }

        $request->headers->set('Sec-Purpose', 'prefetch');
        $request->attributes->set(self::ATTRIBUTE, true);

        $response = $next($request);

        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
