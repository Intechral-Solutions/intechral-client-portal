<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The shared limit on posting a REPLY to a service request (Release 1.0 pre-RC1 hardening; owner ruling 2026-11-12, release gate 7).
 *
 * ONE allowance per authenticated ACCOUNT, spent across all three reply routes: the requester's (`tickets.replies.store`), the operator's
 * (`operator.tickets.replies.store`) and partner support's (`support.replies.store`). They share one limiter name and one key, so an account
 * cannot multiply its allowance by alternating routes, and a person who is both a requester and a partner staff member has one bucket.
 *
 * WHY. A public reply mails the other participants (the requester, the assignee and, since R1-6, every partner support participant), so an unlimited
 * route is a mail-sending and storage lever for a stolen or careless session. The key is the account id (never the client address, the ticket
 * or anything the client supplies), so a shared proxy address cannot make one person's traffic throttle another's and varying the request does not
 * dodge it. A request without an account (it cannot reach here: `auth` runs first) falls back to the client address.
 *
 * LIMITS. Two windows, both per account:
 *  - {@see ATTEMPTS_PER_MINUTE} stops a burst (a script, a held-down submit) without touching a person typing and sending;
 *  - {@see ATTEMPTS_PER_HOUR} bounds sustained volume. 300 an hour is five replies every minute for an hour: well above what one operator
 *    writes working a queue, with internal notes, canned replies and corrections included.
 * Every attempt counts, including a refused or invalid one, and the limiter runs before the controller, so an attempt past a limit validates,
 * stores, uploads and mails nothing. Reading is never limited (the limiter is on the POST routes only), and neither is any other operator action.
 */
final class TicketReplyThrottle
{
    public const NAME = 'ticket-reply';

    public const ATTEMPTS_PER_MINUTE = 30;

    public const ATTEMPTS_PER_HOUR = 300;

    /** @return list<Limit> */
    public static function limits(Request $request): array
    {
        $user = $request->user();
        $key = $user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip();

        // Distinct keys: the two windows must not share one counter (the middleware hashes limiter name and key, and a counter has one decay).
        return [
            Limit::perMinute(self::ATTEMPTS_PER_MINUTE)->by($key.':minute'),
            Limit::perHour(self::ATTEMPTS_PER_HOUR)->by($key.':hour'),
        ];
    }

    /** Forget one account's counters (tests; the throttle middleware derives its cache key as md5 of the limiter name and the limit key). */
    public static function clearFor(int $userId): void
    {
        foreach (['minute', 'hour'] as $window) {
            RateLimiter::clear(md5(self::NAME.'user:'.$userId.':'.$window));
        }
    }
}
