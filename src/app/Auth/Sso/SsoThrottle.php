<?php

namespace App\Auth\Sso;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Limits on starting and completing a provider round trip (Release 1.0 pre-RC1 hardening).
 *
 *  - A SIGNED-IN account is limited PER ACCOUNT (the key is the account id, never the address): that is a link attempt, which is rare, so the
 *    allowance is small ({@see START_PER_ACCOUNT_PER_HOUR}, {@see CALLBACK_PER_ACCOUNT_PER_HOUR}). A refused attempt counts, and the limiter runs
 *    before the controller, so an attempt past the limit starts nothing and touches no provider.
 *  - A GUEST cannot be limited per account: the account is not known until the provider has proved who the person is. The per-address limit is
 *    the practical control, and it is only as good as the client address the host reports.
 *  - An invitation-initiated start has its own limiter (`invitation-sso`) and is not counted twice here.
 */
final class SsoThrottle
{
    public const START = 'sso-start';

    public const CALLBACK = 'sso-callback';

    public const START_PER_ACCOUNT_PER_HOUR = 10;

    public const CALLBACK_PER_ACCOUNT_PER_HOUR = 20;

    public const START_PER_ADDRESS_PER_MINUTE = 30;

    public const CALLBACK_PER_ADDRESS_PER_MINUTE = 60;

    public static function start(Request $request): Limit
    {
        if ($request->query->has('invitation')) {
            return Limit::none();
        }

        return self::limit($request, self::START_PER_ACCOUNT_PER_HOUR, self::START_PER_ADDRESS_PER_MINUTE);
    }

    public static function callback(Request $request): Limit
    {
        return self::limit($request, self::CALLBACK_PER_ACCOUNT_PER_HOUR, self::CALLBACK_PER_ADDRESS_PER_MINUTE);
    }

    private static function limit(Request $request, int $perAccountPerHour, int $perAddressPerMinute): Limit
    {
        $user = $request->user();

        return $user !== null
            ? Limit::perHour($perAccountPerHour)->by('user:'.$user->getAuthIdentifier())
            : Limit::perMinute($perAddressPerMinute)->by('ip:'.$request->ip());
    }

    /** Forget one account's counters (tests; the middleware derives its cache key as md5 of the limiter name and the limit key). */
    public static function clearFor(int $userId): void
    {
        foreach ([self::START, self::CALLBACK] as $name) {
            RateLimiter::clear(md5($name.'user:'.$userId));
        }
    }
}
