<?php

namespace App\Support\Helpdesk;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * The public identity of a ticket (Release 0.9 C6-R, ADR-008): `TK-XXXXX-XXXXX`, ten Crockford Base32 symbols (50 random bits), stored in
 * `tickets.ticket_number`. It is an identifier, never a credential: authorization stays in TicketPolicy.
 *
 * Two entry points, deliberately different:
 *  - {@see canonicalize()} is STRICT and serves route binding. Only the canonical spelling, upper-cased, resolves, so one ticket has one
 *    URL form and `I`/`L`/`O` are never silently re-read.
 *  - {@see fromSearch()} is LOOSE and serves the search box only: separators, case, the optional `TK` prefix and the Crockford look-alikes
 *    (I/L -> 1, O -> 0) are forgiven.
 *
 * The numeric primary key stays the internal identity (relations, queue payloads, private storage paths); it is never a public one.
 */
final class TicketReference
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const PATTERN = '/^TK-[0-9A-HJKMNP-TV-Z]{5}-[0-9A-HJKMNP-TV-Z]{5}\z/';

    /** A fresh reference: ten uniform draws from the CSPRNG over 32 symbols (no modulo bias, nothing derived from the id or the clock). */
    public static function generate(): string
    {
        $symbols = '';

        for ($i = 0; $i < 10; $i++) {
            $symbols .= self::ALPHABET[random_int(0, 31)];
        }

        return 'TK-'.substr($symbols, 0, 5).'-'.substr($symbols, 5);
    }

    /** Exactly the stored form: upper case, correct prefix, grouping and alphabet. */
    public static function isCanonical(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    /** Strict route-binding form: the canonical reference for an upper- or lower-case spelling of it, otherwise null. */
    public static function canonicalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $upper = strtoupper($value);

        return self::isCanonical($upper) ? $upper : null;
    }

    /** Loose search form: the canonical reference a typed term plausibly means, or null when it cannot be one. */
    public static function fromSearch(string $term): ?string
    {
        $compact = strtoupper(preg_replace('/[\s-]+/', '', $term) ?? '');

        if (strlen($compact) === 12 && str_starts_with($compact, 'TK')) {
            $compact = substr($compact, 2);
        }

        if (strlen($compact) !== 10) {
            return null;
        }

        $compact = strtr($compact, ['I' => '1', 'L' => '1', 'O' => '0']);
        $canonical = 'TK-'.substr($compact, 0, 5).'-'.substr($compact, 5);

        return self::isCanonical($canonical) ? $canonical : null;
    }

    /**
     * Whether a failed insert is a duplicate of THIS unique index and nothing else: MariaDB error 1062 naming
     * `tickets_ticket_number_unique`. Only that is a collision worth a new draw; every other integrity error is a real failure and
     * propagates unretried.
     */
    public static function isCollision(Throwable $e): bool
    {
        return $e instanceof QueryException
            && ($e->errorInfo[1] ?? null) === 1062
            && preg_match("/ for key '(?:tickets\\.)?tickets_ticket_number_unique'\\z/", (string) ($e->errorInfo[2] ?? '')) === 1;
    }
}
