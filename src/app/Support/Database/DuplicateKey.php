<?php

namespace App\Support\Database;

use Illuminate\Database\QueryException;

/**
 * Which UNIQUE key a duplicate-entry error violated (Release 1.0, R1-3A). The old invitation code treated every 1062 as "the invitation is
 * unavailable", which would also have hidden a bug. A caller now names the keys it expects and rethrows anything else.
 *
 * The server reports the key by name at the END of the message ("... for key 'users_email_unique'"); newer servers may prefix the table
 * ("'users.users_email_unique'"). Both read as the bare key name. An error that is not a duplicate entry, or whose key cannot be read,
 * yields null: an UNKNOWN duplicate, which the caller must rethrow rather than convert.
 */
final class DuplicateKey
{
    public static function name(QueryException $exception): ?string
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        if ($sqlState !== '23000' || $driverCode !== 1062) {
            return null;
        }

        // The DRIVER's message only, and the key is the LAST quoted name at the very END of it. The message embeds the duplicated VALUE, which can
        // be caller-controlled and can itself contain the text "for key 'something'": an unanchored match would read that fake key instead of
        // the real one. (QueryException::getMessage() appends the SQL and the connection, so it is never searched.) Anything else is unreadable.
        $message = $exception->errorInfo[2] ?? null;

        if (! is_string($message) || preg_match("/ for key '(?:[^'.]+\\.)?([^'.]+)'\\s*\$/", $message, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
