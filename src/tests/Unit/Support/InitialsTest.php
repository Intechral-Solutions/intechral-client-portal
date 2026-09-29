<?php

use App\Support\Initials;

/*
 * The rule must stay identical to `initialsOf()` in resources/js/components/ui/avatar.tsx: the
 * server derives `auth.user.avatar.initials` (EPIC-013 §12.5) so both renderers agree about the
 * same person. These cases mirror that component's own test table.
 */

it('derives initials deterministically and identically to the client rule', function (string $name, string $expected) {
    expect(Initials::from($name))->toBe($expected)
        ->and(Initials::from($name))->toBe(Initials::from($name));
})->with([
    ['Ada Lovelace', 'AL'],
    ['ada lovelace-byron', 'AL'],
    ['Grace Brewster Murray Hopper', 'GH'],
    ['  Cher  ', 'C'],
    ['Émile Zola', 'ÉZ'],
    ['', '?'],
    ['   ', '?'],
]);
