<?php

namespace App\Support\Helpdesk;

use Illuminate\Support\Str;

/**
 * The one operator destination for the new-ticket alert (EPIC-017 O3, option c): `HELPDESK_NOTIFY_ADDRESS`, read through
 * `helpdesk.notify_address`.
 *
 * It is a single mailbox, deliberately not a list and not "every operator" (the owner's O3 ruling): one address that a person or a
 * shared inbox reads. Dispatch uses {@see usable()}; `runtime:preflight` uses {@see violation()}, so the two can never disagree
 * about what a valid address is. The address is never put into a violation message or a log line (it is a mailbox, not a secret, but
 * the preflight prints no configuration values).
 *
 * Local and test environments may leave it unset: the alert is then skipped with a log line. Production may not: RuntimeProfile
 * refuses it, and the cron workers refuse to start (A3), so a release cannot go live with the alert silently off (RE-H13).
 */
final class HelpdeskNotifyAddress
{
    /** Documentation domains, refused for the same reason `mail.from.address` refuses them: nobody reads them. */
    private const PLACEHOLDER_DOMAINS = ['example.com', 'example.org', 'example.net'];

    /** The configured value, trimmed; null when unset, empty or not a string. */
    public static function configured(): ?string
    {
        $value = config('helpdesk.notify_address');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /** The address to send to, or null when none is configured or the configured one is not usable. */
    public static function usable(): ?string
    {
        $address = self::configured();

        return $address !== null && self::problem($address) === null ? $address : null;
    }

    /** A preflight violation (no value in it), or null when the address is usable. */
    public static function violation(): ?string
    {
        $address = self::configured();

        if ($address === null) {
            return 'helpdesk.notify_address is not set (HELPDESK_NOTIFY_ADDRESS): production requires an operator new-ticket alert destination and must not ship with the alert off';
        }

        $problem = self::problem($address);

        return $problem === null ? null : "helpdesk.notify_address {$problem} (HELPDESK_NOTIFY_ADDRESS)";
    }

    private static function problem(string $address): ?string
    {
        if (strlen($address) > 254 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return 'is not a single valid email address';
        }

        if (in_array(strtolower((string) Str::after($address, '@')), self::PLACEHOLDER_DOMAINS, true)) {
            return 'is a documentation address (example.com, .org or .net), not a mailbox anyone reads';
        }

        return null;
    }
}
