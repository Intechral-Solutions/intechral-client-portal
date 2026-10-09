<?php

/*
 * EPIC-017 WP2 step 4 — the requester list and search contract (§12, §19.1; EPIC-010D F-4, H3).
 *
 * RequesterTicketQuery serves `/tickets`: the actor's OWN tickets, searched over number, title,
 * description and PUBLIC reply bodies only, for every actor (an operator who is the requester
 * included). Terms are trimmed, capped at 200 characters and matched literally; unknown filters are
 * ignored, never an error; the order is created_at DESC, id DESC. The operator queue keeps its
 * internal-text opt-in (H3) and gains the same literal matching.
 */

use App\Models\User;
use App\Queries\RequesterTicketQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/TicketTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

/** The requester list for $user and a query string, as ticket titles in page order. */
function requesterSearchTitles(User $user, array $query): array
{
    $paginator = RequesterTicketQuery::paginate($user, RequesterTicketQuery::filters(Request::create('/tickets', 'GET', $query)));

    return collect($paginator->items())->pluck('title')->all();
}

// ── Normalization ──────────────────────────────────────────────────────────

it('trims the term, caps it at 200 characters and ignores anything that is not a string', function () {
    $long = str_repeat('a', 250);

    expect(RequesterTicketQuery::filters(Request::create('/tickets', 'GET', ['search' => "  needle \t"])))
        ->toBe(['search' => 'needle', 'status' => null])
        ->and(mb_strlen(RequesterTicketQuery::filters(Request::create('/tickets', 'GET', ['search' => $long]))['search']))->toBe(200)
        ->and(RequesterTicketQuery::filters(Request::create('/tickets', 'GET', ['search' => '   '])))->toBe(['search' => null, 'status' => null])
        ->and(RequesterTicketQuery::filters(Request::create('/tickets', 'GET', ['search' => ['x'], 'status' => ['open']])))->toBe(['search' => null, 'status' => null]);
});

it('keeps a known status and ignores an unknown one', function () {
    expect(RequesterTicketQuery::filters(Request::create('/tickets', 'GET', ['status' => 'pending_user']))['status'])->toBe('pending_user')
        ->and(RequesterTicketQuery::filters(Request::create('/tickets', 'GET', ['status' => 'archived']))['status'])->toBeNull()
        ->and(RequesterTicketQuery::filters(Request::create('/tickets', 'GET', ['status' => "open' OR 1=1"]))['status'])->toBeNull();
});

// Array-valued parameters are refused by filters() (above). Over HTTP the Blade list view itself still
// echoes the raw parameter into its search input and fails on an array, a pre-existing defect of a view
// WP2 G2 replaces; the React page renders from the normalized filters instead.
it('answers malformed scalar filters on /tickets with the page, never an error', function (array $query, bool $listed) {
    $owner = ticketUser();
    ticketFor($owner, ['title' => 'Still listed']);

    $response = $this->actingAs($owner)->get(route('tickets.index', $query))->assertOk();

    $listed ? $response->assertSee('Still listed') : $response->assertDontSee('Still listed');
})->with([
    'unknown status is ignored' => [['status' => 'archived'], true],
    'overlong search is capped, and matches nothing here' => [['search' => str_repeat('x', 5000)], false],
    'non-numeric page falls back to the first' => [['page' => 'abc'], true],
]);

it('filters by a known status and carries only the normalized filters into the page links', function () {
    $owner = ticketUser();
    foreach (range(1, 21) as $i) {
        ticketFor($owner, ['title' => "Open {$i}", 'status' => 'open']);
    }
    ticketFor($owner, ['title' => 'Closed one', 'status' => 'closed']);

    $request = Request::create('/tickets', 'GET', ['status' => 'open', 'search' => '  Open  ', 'junk' => '1']);
    $paginator = RequesterTicketQuery::paginate($owner, RequesterTicketQuery::filters($request));
    parse_str((string) parse_url((string) $paginator->nextPageUrl(), PHP_URL_QUERY), $next);

    expect($paginator->total())->toBe(21)
        ->and(collect($paginator->items())->pluck('status')->unique()->all())->toBe(['open'])
        // Parameter order is irrelevant to a query string; the content must be exactly the normalized filters.
        ->and($next)->toEqual(['status' => 'open', 'search' => 'Open', 'page' => '2'])
        ->and(array_keys($next))->toEqualCanonicalizing(['status', 'search', 'page']);
});

// ── Literal matching (F-4) ─────────────────────────────────────────────────

it('matches %, _ and \\ literally, not as wildcards', function (string $term, array $expected) {
    $owner = ticketUser();
    foreach (['Discount 100% off', 'Discount 1000 off', 'file_name issue', 'filename issue', 'Path C:\\temp broken', 'Path C:temp broken'] as $title) {
        ticketFor($owner, ['title' => $title]);
    }

    expect(requesterSearchTitles($owner, ['search' => $term]))->toEqualCanonicalizing($expected);
})->with([
    'percent' => ['100%', ['Discount 100% off']],
    'underscore' => ['file_name', ['file_name issue']],
    'backslash' => ['C:\\temp', ['Path C:\\temp broken']],
    'a lone percent' => ['%', ['Discount 100% off']],
    'a lone underscore' => ['_', ['file_name issue']],
]);

it('gives the operator queue the same literal matching, with its internal-text opt-in kept', function () {
    $owner = ticketUser();
    $agent = ticketAgent();
    $literal = ticketFor($owner, ['title' => 'Quota 100% used']);
    ticketFor($owner, ['title' => 'Quota 1000 used']);
    $internal = ticketFor($owner, ['title' => 'Quiet ticket']);
    ticketReply($internal, $agent, true, 'internal codeword zebra');

    $this->actingAs($agent)->get(route('operator.tickets.index', ['search' => '100%']))
        ->assertOk()->assertSee($literal->ticket_number)->assertDontSee('Quota 1000 used');
    $this->actingAs($agent)->get(route('operator.tickets.index', ['search' => 'zebra']))
        ->assertOk()->assertSee($internal->ticket_number);
});

// ── Internal text never steers the requester list ──────────────────────────

it('never matches internal-note text for the requester', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner, ['title' => 'Printer down']);
    ticketReply($ticket, ticketAgent(), true, 'internal codeword zebra');
    ticketReply($ticket, ticketAgent(), false, 'public answer giraffe');

    expect(requesterSearchTitles($owner, ['search' => 'zebra']))->toBe([])
        ->and(requesterSearchTitles($owner, ['search' => 'giraffe']))->toBe(['Printer down']);
});

it('never matches internal-note text even when an operator is the requester', function () {
    $agent = ticketAgent();
    $own = ticketFor($agent, ['title' => 'My own laptop']);
    ticketReply($own, ticketAgent(), true, 'internal codeword zebra');

    expect(requesterSearchTitles($agent, ['search' => 'zebra']))->toBe([]);

    $this->actingAs($agent)->get(route('tickets.index', ['search' => 'zebra']))
        ->assertOk()->assertDontSee('My own laptop');
});

// ── Ownership, order and the empty states ──────────────────────────────────

it('lists only the actor\'s own tickets, whatever matches elsewhere', function () {
    $owner = ticketUser();
    $other = ticketUser();
    ticketFor($owner, ['title' => 'Mine: shared word']);
    ticketFor($other, ['title' => 'Theirs: shared word']);
    ticketFor(ticketAgent(), ['title' => 'Operator: shared word']);

    expect(requesterSearchTitles($owner, ['search' => 'shared word']))->toBe(['Mine: shared word'])
        ->and(requesterSearchTitles($owner, []))->toBe(['Mine: shared word']);
});

it('orders by created_at then id, so tied timestamps page deterministically without gaps or repeats', function () {
    $owner = ticketUser();
    $at = now()->subDay()->startOfSecond();
    $ids = collect(range(1, 25))->map(fn () => ticketFor($owner, ['created_at' => $at])->id);

    $first = RequesterTicketQuery::paginate($owner, ['search' => null, 'status' => null])->getCollection()->pluck('id');
    // The paginator reads the current page from the request, as on a real `?page=2` visit.
    request()->merge(['page' => 2]);
    $second = RequesterTicketQuery::paginate($owner, ['search' => null, 'status' => null])->getCollection()->pluck('id');

    expect($first->all())->toBe($ids->sortDesc()->take(20)->values()->all())
        ->and($second->all())->toBe($ids->sortDesc()->slice(20)->values()->all())
        ->and($first->intersect($second)->all())->toBe([]);
});

it('tells a truly empty list from a filtered-empty one', function () {
    $newcomer = ticketUser();
    $owner = ticketUser();
    ticketFor($owner, ['title' => 'Only one']);

    expect(RequesterTicketQuery::hasRequests($newcomer))->toBeFalse()
        ->and(RequesterTicketQuery::hasRequests($owner))->toBeTrue()
        ->and(requesterSearchTitles($owner, ['search' => 'nothing like it']))->toBe([])
        ->and(RequesterTicketQuery::hasRequests(ticketUser()))->toBeFalse();
});

it('reads a page in two queries, whatever the number of matching tickets', function () {
    $owner = ticketUser();
    $count = function () use ($owner): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        RequesterTicketQuery::paginate($owner, ['search' => 'Probe', 'status' => 'open'])->items();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    ticketFor($owner, ['title' => 'Probe 1', 'status' => 'open']);
    $one = $count();
    foreach (range(2, 30) as $i) {
        ticketFor($owner, ['title' => "Probe {$i}", 'status' => 'open']);
    }

    expect($one)->toBe(2)
        ->and($count())->toBe(2);
});
