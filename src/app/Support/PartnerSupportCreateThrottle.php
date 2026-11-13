<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

/**
 * The limit on partner support staff RAISING requests for a customer's contact (Release 1.0, R1-5 review): 60 attempts an hour per authenticated
 * account.
 *
 * WHY. Every successful creation mails the CONTACT (a third party, with a staff-chosen subject) and the helpdesk inbox, so an unlimited route is a
 * mail-sending lever for a careless or hijacked session. Like {@see InvitationIssueThrottle} it is per ACCOUNT (a shared proxy address must not
 * make one member's traffic throttle another's), counts every attempt including a refused one, and runs before the controller, so an attempt past
 * the limit creates, mails and audits nothing. 60 an hour is a working day's intake for one person with headroom for corrections. Attached to the
 * creation route ONLY: reading and replying are not limited.
 */
final class PartnerSupportCreateThrottle
{
    public const NAME = 'partner-support-create';

    public const ATTEMPTS_PER_HOUR = 60;

    public static function limit(Request $request): Limit
    {
        $user = $request->user();

        return Limit::perHour(self::ATTEMPTS_PER_HOUR)->by($user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip());
    }
}
