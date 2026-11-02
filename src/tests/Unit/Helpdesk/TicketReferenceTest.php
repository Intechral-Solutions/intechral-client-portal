<?php

use App\Support\Helpdesk\TicketReference;
use Illuminate\Database\QueryException;

/*
 * Release 0.9 C6-R (ADR-008): the format, the strict and loose spellings, and which insert failure counts as a collision.
 * Pure functions: no database.
 */

it('generates canonical references from the 32-symbol Crockford alphabet', function () {
    $symbols = [];

    foreach (range(1, 1500) as $_) {
        $reference = TicketReference::generate();

        expect($reference)->toMatch(TicketReference::PATTERN)
            ->and(strlen($reference))->toBe(14)
            ->and(TicketReference::isCanonical($reference))->toBeTrue();

        foreach (str_split(str_replace(['TK', '-'], '', $reference)) as $symbol) {
            $symbols[$symbol] = true;
        }
    }

    // 15,000 uniform draws over 32 symbols miss a symbol with probability ~1e-200: every symbol appears, and none outside the alphabet does.
    expect(array_keys($symbols))->toEqualCanonicalizing(str_split(TicketReference::ALPHABET))
        ->and(strlen(TicketReference::ALPHABET))->toBe(32)
        ->and(TicketReference::ALPHABET)->not->toMatch('/[ILOU]/');
});

it('does not repeat in a realistic run', function () {
    $references = array_map(fn () => TicketReference::generate(), range(1, 2000));

    expect(array_unique($references))->toHaveCount(2000);
});

it('treats only the exact canonical spelling as canonical', function (mixed $value, bool $canonical) {
    expect(TicketReference::isCanonical($value))->toBe($canonical);
})->with([
    'canonical' => ['TK-0123A-BCDEF', true],
    'lower case' => ['tk-0123a-bcdef', false],
    'legacy number' => ['TKT-0001', false],
    'legacy fixture' => ['TKT-E2E1', false],
    'temporary' => ['TMP-abcdef012345', false],
    'numeric id' => ['7', false],
    'no hyphens' => ['TK0123ABCDEF', false],
    'excluded letter I' => ['TK-0123I-BCDEF', false],
    'excluded letter L' => ['TK-0123L-BCDEF', false],
    'excluded letter O' => ['TK-0123O-BCDEF', false],
    'excluded letter U' => ['TK-0123U-BCDEF', false],
    'too short' => ['TK-0123-BCDEF', false],
    'too long' => ['TK-0123AB-BCDEF', false],
    'trailing newline' => ["TK-0123A-BCDEF\n", false],
    'trailing space' => ['TK-0123A-BCDEF ', false],
    'null' => [null, false],
    'int' => [12345, false],
    'array' => [['TK-0123A-BCDEF'], false],
]);

it('canonicalizes strictly for routing: upper-case fold only, never look-alike mapping', function () {
    expect(TicketReference::canonicalize('TK-0123A-BCDEF'))->toBe('TK-0123A-BCDEF')
        ->and(TicketReference::canonicalize('tk-0123a-bcdef'))->toBe('TK-0123A-BCDEF')
        ->and(TicketReference::canonicalize('TK-0123I-BCDEF'))->toBeNull()
        ->and(TicketReference::canonicalize('TK-0123O-BCDEF'))->toBeNull()
        ->and(TicketReference::canonicalize('TK0123ABCDEF'))->toBeNull()
        ->and(TicketReference::canonicalize('TKT-0001'))->toBeNull()
        ->and(TicketReference::canonicalize('42'))->toBeNull()
        ->and(TicketReference::canonicalize(null))->toBeNull();
});

it('reads a typed search term loosely: separators, case, optional TK prefix and Crockford look-alikes', function (string $term, ?string $expected) {
    expect(TicketReference::fromSearch($term))->toBe($expected);
})->with([
    'canonical' => ['TK-0123A-BCDEF', 'TK-0123A-BCDEF'],
    'lower, no hyphens' => ['tk0123abcdef', 'TK-0123A-BCDEF'],
    'spaces' => ['  tk 0123a bcdef ', 'TK-0123A-BCDEF'],
    'without the prefix' => ['0123abcdef', 'TK-0123A-BCDEF'],
    'without the prefix, hyphenated' => ['0123A-BCDEF', 'TK-0123A-BCDEF'],
    'O read as zero' => ['TK-O123A-BCDEF', 'TK-0123A-BCDEF'],
    'I and L read as one' => ['TK-0I23A-BCDEF', 'TK-0123A-BCDEF'],
    'a body that itself starts with TK' => ['TKABC12345', 'TK-TKABC-12345'],
    'too short' => ['TK-0123A', null],
    'a word' => ['printer', null],
    'excluded letter U' => ['TK-0123U-BCDEF', null],
    'empty' => ['', null],
]);

it('counts only a duplicate of the ticket_number unique index as a collision', function () {
    $make = function (int $driverCode, string $message): QueryException {
        $pdo = new PDOException($message);
        $pdo->errorInfo = ['23000', $driverCode, $message];

        return new QueryException('mysql', 'insert into `tickets` ...', [], $pdo);
    };

    expect(TicketReference::isCollision($make(1062, "Duplicate entry 'TK-0123A-BCDEF' for key 'tickets_ticket_number_unique'")))->toBeTrue()
        ->and(TicketReference::isCollision($make(1062, "Duplicate entry 'x' for key 'some_other_unique'")))->toBeFalse()
        ->and(TicketReference::isCollision($make(1452, 'Cannot add or update a child row: a foreign key constraint fails (tickets_ticket_number_unique)')))->toBeFalse()
        ->and(TicketReference::isCollision(new RuntimeException("Duplicate entry for key 'tickets_ticket_number_unique'")))->toBeFalse();
});

it('judges a collision by the driver message of the failing key, not by text that happens to be in the SQL or the data', function () {
    $make = function (string $driverMessage, string $sqlAndData): QueryException {
        $pdo = new PDOException($driverMessage);
        $pdo->errorInfo = ['23000', 1062, $driverMessage];

        return new QueryException('mysql', $sqlAndData, [], $pdo);
    };

    expect(TicketReference::isCollision($make("Duplicate entry 'TK-0123A-BCDEF' for key 'tickets.tickets_ticket_number_unique'", 'insert ...')))->toBeTrue()
        ->and(TicketReference::isCollision($make("Duplicate entry '7' for key 'PRIMARY'", 'insert into tickets (title) values (tickets_ticket_number_unique)')))->toBeFalse();
});
