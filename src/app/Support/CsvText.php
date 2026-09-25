<?php

namespace App\Support;

/**
 * Spreadsheet-safe text for CSV exports.
 *
 * A cell whose text starts (after optional whitespace) with `=`, `+`, `-` or `@` is read by
 * spreadsheet applications as a formula, so free text that users control is prefixed with an
 * apostrophe. Pair it with `fputcsv(..., escape: '')` so quoting stays RFC 4180. Shared by the
 * Time and Ticket exports.
 */
final class CsvText
{
    public static function safe(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\\s]*[=+\-@]/u', $value) === 1
            ? "'{$value}"
            : $value;
    }
}
