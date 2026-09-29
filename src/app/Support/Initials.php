<?php

namespace App\Support;

/**
 * Person initials, derived identically on both renderers.
 *
 * The rule mirrors `initialsOf()` in `resources/js/components/ui/avatar.tsx` exactly: the first
 * letter of the first and last word, upper-cased, or `?` when the name carries no letters. It is
 * duplicated deliberately — EPIC-013 §12.5 derives `auth.user.avatar.initials` server-side so the
 * React shell, the Blade shell and the account menu never disagree about the same person.
 */
final class Initials
{
    public static function from(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '?';
        }

        $first = mb_substr($words[0], 0, 1);
        $last = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }
}
