<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The limit on ISSUING organization invitations (Release 1.0, R1-3C remediation; design record section 20): 30 attempts per hour per
 * authenticated operator, covering both a new invitation and a Resend (they are one route).
 *
 * WHY. Every issuance mails an address of the operator's choosing, so an unlimited route is a mail-sending lever for a stolen or careless
 * operator session. The public invitation limiters are per client address; this one is per ACCOUNT, because the actor is authenticated and a
 * shared proxy address must not make one operator's traffic throttle another's.
 *
 *  - Attached to the issuance route ONLY. Revocation is deliberately not limited: an operator who has hit the limit must still be able to
 *    kill a link that should not be live.
 *  - Every attempt counts, including a refused or invalid one (it is an attempt), and the limiter runs before the controller, so the 31st
 *    attempt creates, changes, audits and mails nothing: the request ends at the middleware.
 *  - The key is the account id, never the address, the organization or anything the client supplies, so it cannot be dodged by varying the
 *    request and one operator cannot spend another's allowance. A request without an account (it cannot reach here: `auth` runs first) falls
 *    back to the client address rather than to one shared bucket.
 *
 * The cleanup command of the browser suite clears the SEEDED E2E operator's counter with {@see self::clearFor()}, so repeated local runs
 * stay under the production limit without changing it. That is the only reset there is: no route, no option.
 */
final class InvitationIssueThrottle
{
    public const NAME = 'invitation-issue';

    public const ATTEMPTS_PER_HOUR = 30;

    public static function limit(Request $request): Limit
    {
        $user = $request->user();

        return Limit::perHour(self::ATTEMPTS_PER_HOUR)->by($user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip());
    }

    /**
     * Forget one account's counter. The key is the one the `throttle` middleware derives (it hashes the limiter name with the limit's key),
     * so a test pins that clearing really restores access instead of trusting this derivation.
     */
    public static function clearFor(int $userId): void
    {
        RateLimiter::clear(md5(self::NAME.'user:'.$userId));
    }
}
