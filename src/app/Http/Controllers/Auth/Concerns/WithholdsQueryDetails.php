<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Exceptions\InvitationDatabaseFailure;
use Closure;
use Illuminate\Database\QueryException;

/**
 * A database failure while an invitation credential is in play must not put that credential in the application log.
 *
 * Laravel's QueryException message embeds the SQL WITH its bindings, and an invitation lookup binds the token's hash (the plaintext never
 * reaches the database). The hash cannot be turned back into a link, but it is the server-side credential the single-sign-on callback holds
 * in the session, so it is treated as one: this reports a fixed message with no SQL and no bindings (the same pattern as the provider-link
 * failure in SocialiteController) plus an allowlisted diagnostic (App\Exceptions\InvitationDatabaseFailure), and answers a plain 500. Nothing
 * is swallowed: the failure is still reported and still diagnosable. The HTTP answer stays a generic 500 and the transaction has already
 * rolled back by the time a QueryException leaves the core.
 */
trait WithholdsQueryDetails
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withoutQueryDetails(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $exception) {
            // Reported with an allowlisted, credential-free diagnostic (SQLSTATE, driver code, violated key name, connection, original class
            // and call-site locations without arguments), never the original message, driver text, SQL, bindings or the original exception.
            report(InvitationDatabaseFailure::from($exception));

            abort(500);
        }
    }
}
