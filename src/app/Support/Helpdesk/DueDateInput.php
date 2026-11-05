<?php

namespace App\Support\Helpdesk;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * The one place a due-date form value is read or written (Release 1.0, Help Desk due-date overrides).
 *
 * The operator types a wall-clock `datetime-local` value ("2026-11-20T14:30"), with no zone of its own. It is ALWAYS read in
 * the application's timezone (`app.timezone`, which RuntimeProfile pins to UTC in production and the database session is
 * pinned to match), and the form names that zone beside the field. Nothing reads the browser's zone, so the stored instant
 * does not depend on where the operator sits.
 *
 * Strict by construction: only `Y-m-d\TH:i` parses, and a value is accepted only if formatting the parsed instant gives the
 * same text back. That refuses an impossible date ("2026-02-31") and, were the application zone ever one with daylight
 * saving, a wall-clock time that does not exist (the spring-forward gap), which PHP would otherwise silently shift an hour.
 * A wall-clock time that occurs twice (the autumn overlap) is accepted and means its FIRST occurrence, deterministically.
 */
final class DueDateInput
{
    public const FORMAT = 'Y-m-d\TH:i';

    public static function timezone(): string
    {
        return (string) config('app.timezone');
    }

    /** The zone's short name for the form and the details panel ("UTC"). */
    public static function zoneLabel(): string
    {
        return CarbonImmutable::now(self::timezone())->format('T');
    }

    /** The instant the text means, or null when it is not a real wall-clock time in the application zone. */
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
            return null;
        }

        try {
            $at = CarbonImmutable::createFromFormat('!'.self::FORMAT, $value, self::timezone());
        } catch (Throwable) {
            return null;
        }

        if ($at === false || $at->format(self::FORMAT) !== $value) {
            return null;
        }

        return $at;
    }

    /** An instant as the `datetime-local` text in the application zone (the field's value). */
    public static function format(?CarbonInterface $at): string
    {
        return $at === null ? '' : $at->copy()->setTimezone(self::timezone())->format(self::FORMAT);
    }
}
