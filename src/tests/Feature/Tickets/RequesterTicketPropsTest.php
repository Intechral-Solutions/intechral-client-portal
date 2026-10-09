<?php

/*
 * EPIC-017 WP2 G2 — the requester Helpdesk contract (§15, §19, §27).
 *
 * `/tickets*` is the requester-safe surface. Its props are written out by RequesterTicketPresenter,
 * and this suite pins them two ways, because hiding a value in the page is not a guarantee:
 *
 *  - EXACT KEYS at every depth: a key that is not on the allowlist fails the test, so adding one is
 *    a reviewed change (N1/O1: no time data; no internal note, SLA, history actor, assignee contact
 *    detail, requester id or company);
 *  - SENTINELS: distinctive strings planted in every operator-only field must not appear anywhere
 *    in the serialized page, including nested props.
 *
 * Both run for a plain customer AND for an operator who is the requester (P2: the requester view
 * never widens for anyone). P2 itself (the non-owner operator redirect), the three empty states,
 * the reply states and the query budgets are here too.
 */

use App\Models\CrmCompany;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TimeEntry;
use App\Models\User;
use App\Rules\AllowedTicketAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/TicketTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Storage::fake('local');
});

afterEach(function () {
    ticketCleanFakeStorage();
});

const PROPS_ROW_KEYS = ['id', 'number', 'title', 'status', 'statusLabel', 'priority', 'createdAt', 'createdAtHuman', 'href'];

/** Every key path the detail page may carry, own props only (`*` is a list index). */
const PROPS_DETAIL_PATHS = [
    'ticket', 'ticket.id', 'ticket.number', 'ticket.title', 'ticket.status', 'ticket.statusLabel', 'ticket.priority',
    'ticket.category', 'ticket.createdAt', 'ticket.createdAtHuman', 'ticket.handledBy',
    'conversation', 'conversation.*', 'conversation.*.key', 'conversation.*.kind', 'conversation.*.author',
    'conversation.*.author.name', 'conversation.*.body', 'conversation.*.createdAt', 'conversation.*.createdAtHuman',
    'conversation.*.attachments', 'conversation.*.attachments.*', 'conversation.*.attachments.*.id',
    'conversation.*.attachments.*.filename', 'conversation.*.attachments.*.size', 'conversation.*.attachments.*.href',
    'history', 'history.*', 'history.*.statusLabel', 'history.*.at', 'history.*.atHuman',
    'reply', 'reply.allowed', 'reply.notice', 'reply.closedMessage',
    'attachmentPolicy', 'attachmentPolicy.maxFiles', 'attachmentPolicy.maxSizeMb', 'attachmentPolicy.extensions', 'attachmentPolicy.extensions.*',
    'abilities', 'abilities.create',
];

/** Strings planted in operator-only fields; none may ever reach a requester. */
const PROPS_SENTINELS = [
    'INTERNAL-NOTE-SENTINEL', 'internal-attachment-sentinel.pdf', 'Coworker-Time-SENTINEL', 'operator-history-sentinel',
    'assignee-sentinel@example.test', 'Acme-Company-SENTINEL',
];

/**
 * A ticket whose operator-only fields are all populated with sentinels: an internal note with an
 * attachment, time entries (the requester's, a coworker's and a running timer), a status history
 * driven by a named operator, an assignee with an email, an SLA, and a company link.
 *
 * @return array{ticket: Ticket, owner: User, assignee: User}
 */
function propsFixture(?User $owner = null): array
{
    $owner ??= ticketUser();
    $assignee = ticketAgent(['name' => 'Handler Hana', 'email' => 'assignee-sentinel@example.test']);
    $operator = ticketAgent(['name' => 'operator-history-sentinel']);
    $coworker = ticketUser('operator', ['name' => 'Coworker-Time-SENTINEL']);
    $company = CrmCompany::factory()->create([
        'name' => 'Acme-Company-SENTINEL',
        'created_by' => $operator->id,
        'organization_id' => Organization::factory()->create(['owner_id' => $operator->id])->id,
    ]);
    $ticket = ticketFor($owner, [
        'status' => 'pending_user', 'assignee_id' => $assignee->id, 'company_id' => $company->id,
        'title' => 'Printer offline', 'description' => 'It prints nothing.', 'priority' => 'high',
    ]);

    $public = ticketReply($ticket, $assignee, false, 'We are looking into it.');
    $internal = ticketReply($ticket, $assignee, true, 'INTERNAL-NOTE-SENTINEL credit hold');
    ticketAttachment($ticket, null, $owner, 'photo.png', 'png-bytes');
    ticketAttachment($ticket, $public, $assignee, 'diagnostics.txt', 'text');
    ticketAttachment($ticket, $internal, $assignee, 'internal-attachment-sentinel.pdf', 'pdf-bytes');

    $ticket->statusHistories()->create(['user_id' => $operator->id, 'old_status' => 'open', 'new_status' => 'pending_user', 'created_at' => now()]);

    TimeEntry::factory()->create(['user_id' => $coworker->id, 'ticket_id' => $ticket->id, 'duration_minutes' => 217, 'date' => today()]);
    TimeEntry::factory()->create(['user_id' => $owner->id, 'ticket_id' => $ticket->id, 'duration_minutes' => 61, 'date' => today()]);
    TimeEntry::factory()->running()->create(['user_id' => $coworker->id, 'ticket_id' => $ticket->id]);

    return compact('ticket', 'owner', 'assignee');
}

// ── Exact keys and sentinels ───────────────────────────────────────────────

it('sends the detail page exactly the allowlisted keys at every depth, never an operator-only one', function (string $viewer) {
    $owner = $viewer === 'operator-requester' ? ticketAgent() : ticketUser();
    $f = propsFixture($owner);

    $response = $this->actingAs($owner)->get(route('tickets.show', $f['ticket']))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page->component('tickets/show'));
    $own = ticketOwnProps($response);

    expect(array_keys($own))->toEqualCanonicalizing(['ticket', 'conversation', 'history', 'reply', 'attachmentPolicy', 'abilities'])
        ->and(ticketKeyPaths($own))->toEqualCanonicalizing(PROPS_DETAIL_PATHS);
})->with(['customer' => ['customer'], 'operator who is the requester' => ['operator-requester']]);

it('carries no sentinel from any operator-only field anywhere in the serialized page', function (string $viewer) {
    $owner = $viewer === 'operator-requester' ? ticketAgent() : ticketUser();
    $f = propsFixture($owner);

    $response = $this->actingAs($owner)->get(route('tickets.show', $f['ticket']))->assertOk();

    // Everything the page carries except the viewer's OWN shared auth/navigation data.
    $page = ticketPage($response);
    $serialized = json_encode(['props' => ticketOwnProps($response), 'url' => $page['url'], 'component' => $page['component']]);

    foreach (PROPS_SENTINELS as $sentinel) {
        expect($serialized)->not->toContain($sentinel);
    }

    // The planted time is not reachable by figure either: 217 and 61 minutes, a running timer, a total.
    foreach (['duration', 'timer', 'time_entr', 'timeEntr', 'totalMinutes', 'sla_due', 'sladue', 'overdue', 'company', 'assignee_id', 'user_id', 'email'] as $word) {
        expect(strtolower(implode(' ', ticketKeyPaths(ticketOwnProps($response)))))->not->toContain(strtolower($word));
    }
})->with(['customer' => ['customer'], 'operator who is the requester' => ['operator-requester']]);

it('shows the description first, then the public replies in order, with only their own attachments', function () {
    $f = propsFixture();

    $conversation = ticketOwnProps($this->actingAs($f['owner'])->get(route('tickets.show', $f['ticket'])))['conversation'];

    expect(array_column($conversation, 'kind'))->toBe(['description', 'reply'])
        ->and($conversation[0]['body'])->toBe('It prints nothing.')
        ->and(array_column($conversation[0]['attachments'], 'filename'))->toBe(['photo.png'])
        ->and($conversation[1]['body'])->toBe('We are looking into it.')
        ->and($conversation[1]['author'])->toBe(['name' => 'Handler Hana'])
        ->and(array_column($conversation[1]['attachments'], 'filename'))->toBe(['diagnostics.txt']);
});

it('names the assignee but never exposes their id or email, and uses the customer vocabulary', function () {
    $f = propsFixture();

    $own = ticketOwnProps($this->actingAs($f['owner'])->get(route('tickets.show', $f['ticket'])));

    expect($own['ticket']['handledBy'])->toBe('Handler Hana')
        ->and($own['ticket']['statusLabel'])->toBe('Waiting on you')
        ->and($own['history'])->toHaveCount(1)
        ->and(array_keys($own['history'][0]))->toBe(['statusLabel', 'at', 'atHuman'])
        ->and($own['history'][0]['statusLabel'])->toBe('Waiting on you');
});

it('keeps the internal attachment download refused to the requester', function () {
    $f = propsFixture();
    $internal = TicketAttachment::where('ticket_id', $f['ticket']->id)->where('filename', 'internal-attachment-sentinel.pdf')->sole();

    $this->actingAs($f['owner'])->get(route('tickets.attachment.download', $internal))->assertForbidden();
});

it('sends the list page exactly the allowlisted row keys and no operator-only field', function (string $viewer) {
    $owner = $viewer === 'operator-requester' ? ticketAgent() : ticketUser();
    $f = propsFixture($owner);

    $response = $this->actingAs($owner)->get(route('tickets.index'))->assertOk();
    $own = ticketOwnProps($response);

    expect(array_keys($own))->toEqualCanonicalizing(['tickets', 'filters', 'statusOptions', 'hasRequests', 'canCreate'])
        ->and(array_keys($own['tickets']['data'][0]))->toBe(PROPS_ROW_KEYS)
        ->and(array_keys($own['filters']))->toBe(['search', 'status'])
        ->and($own['tickets']['data'][0])->toMatchArray(['id' => $f['ticket']->id, 'statusLabel' => 'Waiting on you', 'href' => route('tickets.show', $f['ticket'])]);

    $serialized = json_encode($own);
    foreach (PROPS_SENTINELS as $sentinel) {
        expect($serialized)->not->toContain($sentinel);
    }
})->with(['customer' => ['customer'], 'operator who is the requester' => ['operator-requester']]);

it('lists only the viewer\'s own requests, newest first with a stable tie-break', function () {
    $owner = ticketUser();
    $at = now()->subDay();
    $first = ticketFor($owner, ['title' => 'First', 'created_at' => $at]);
    $second = ticketFor($owner, ['title' => 'Second', 'created_at' => $at]);
    ticketFor(ticketUser(), ['title' => 'Someone else', 'created_at' => $at]);

    $titles = array_column(ticketOwnProps($this->actingAs($owner)->get(route('tickets.index')))['tickets']['data'], 'title');

    expect($titles)->toBe(['Second', 'First'])
        ->and($second->id)->toBeGreaterThan($first->id);
});

// ── Time data: absent for the requester ────────────────────────────────────

/*
 * PERMANENT (N1/O1). This is the requester-side privacy contract; it stays when WP3 replaces the temporary
 * operator tracker, and must keep passing whatever the operator page then shows.
 */
it('leaves ticket time off the requester page: no tracker markup, no coworker name, no figure', function () {
    $f = propsFixture();

    $html = $this->actingAs($f['owner'])->get(route('tickets.show', $f['ticket']))->assertOk()
        ->assertDontSee('Coworker-Time-SENTINEL')->assertDontSee('data-time-tracker', false)
        ->assertDontSee('Time Tracked')->getContent();

    // The planted 217 and 61 minutes are not on the page in any form either ("3h 37m", "1h 1m", "217").
    expect($html)->not->toMatch('/\b(3h 37m|1h 1m|217)\b/');
});

/*
 * TEMPORARY (N2 continuity; EPIC-017 section 37). These two tests pin the operator page's embedded Blade
 * tracker. WP3 deletes them with the embed, whole, when the React operator page ships its TimerControl and
 * summary (the full removal list is in section 37). Nothing above this line depends on them.
 */
it('N2: puts the tracker on the operator page, with the total, the recent entries and an in-context Start', function () {
    $f = propsFixture();
    $agent = ticketAgent();

    $html = $this->actingAs($agent)->get(route('operator.tickets.show', $f['ticket']))->assertOk()
        ->assertSee('Time Tracked')
        ->assertSee('Coworker-Time-SENTINEL')
        ->assertSee('data-context-type="ticket"', false)
        ->assertSee('data-context-url="'.route('operator.tickets.show', $f['ticket']).'"', false)
        ->getContent();

    expect($html)->toMatch('/<button[^>]*data-time-tracker-start/');
});

it('N2: offers the operator-page tracker only to a holder of time.log', function () {
    $f = propsFixture();
    $noTime = ticketCustomRoleUser('queue_only', ['tickets.view', 'tickets.assign']);

    $this->actingAs($noTime)->get(route('operator.tickets.show', $f['ticket']))->assertOk()
        ->assertDontSee('data-time-tracker', false);
});

// ── P2: /tickets/{id} is the requester page ────────────────────────────────

/**
 * The headers of an Inertia visit from a client that has already loaded the app: the asset version is
 * read from a document load, as a browser gets it. Without it Inertia answers a stale-version 409
 * before the controller runs, which would test nothing.
 *
 * @return array<string, string>
 */
function propsInertiaHeaders(User $actor): array
{
    $version = test()->actingAs($actor)->get(route('tickets.index'))->viewData('page')['version'];

    return ['X-Inertia' => 'true', 'X-Inertia-Version' => $version];
}

it('P2: sends a non-owner operator to the operator ticket page with a 302', function () {
    $f = propsFixture();
    $agent = ticketAgent();

    $this->actingAs($agent)->get(route('tickets.show', $f['ticket']))
        ->assertRedirect(route('operator.tickets.show', $f['ticket']))
        ->assertStatus(302);
});

it('P2: answers an Inertia visit with a 409 hard visit to the operator page, never a client redirect', function () {
    $f = propsFixture();

    $agent = ticketAgent();

    $this->actingAs($agent)
        ->withHeaders(propsInertiaHeaders($agent))
        ->get(route('tickets.show', $f['ticket']))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('operator.tickets.show', $f['ticket']));
});

it('P2: renders the requester page for an operator who owns the ticket', function () {
    $agent = ticketAgent();
    $ticket = ticketFor($agent, ['title' => 'My own request']);

    $this->actingAs($agent)->get(route('tickets.show', $ticket))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tickets/show')->where('ticket.title', 'My own request'));
});

it('P2: refuses an unrelated viewer with 403 and no redirect, for a document and an Inertia request', function () {
    $f = propsFixture();
    $stranger = ticketUser();

    $this->actingAs($stranger)->get(route('tickets.show', $f['ticket']))->assertForbidden()->assertHeaderMissing('Location');
    $this->actingAs($stranger)
        ->withHeaders(propsInertiaHeaders($stranger))
        ->get(route('tickets.show', $f['ticket']))->assertForbidden()->assertHeaderMissing('X-Inertia-Location');
});

it('P2: the operator still reaches every ticket on the operator page', function () {
    $f = propsFixture();

    $this->actingAs(ticketAgent())->followingRedirects()->get(route('tickets.show', $f['ticket']))
        ->assertOk()->assertSee('INTERNAL-NOTE-SENTINEL');
});

// ── The reply area ─────────────────────────────────────────────────────────

it('states what a reply does on each status, and offers no composer on a closed ticket', function (string $status, bool $allowed, ?string $notice) {
    $owner = ticketUser();
    $ticket = ticketFor($owner, ['status' => $status]);

    $own = ticketOwnProps($this->actingAs($owner)->get(route('tickets.show', $ticket)));

    expect($own['reply']['allowed'])->toBe($allowed)
        ->and($own['reply']['notice'])->toBe($notice)
        ->and($own['reply']['closedMessage'])->toBe($allowed ? null : 'This request is closed. Raise a new request if you need more help.')
        ->and($own['abilities'])->toBe(['create' => true]);
})->with([
    'open' => ['open', true, null],
    'in progress' => ['in_progress', true, null],
    'waiting on the customer' => ['pending_user', true, 'returns'],
    'resolved' => ['resolved', true, 'reopens'],
    'closed' => ['closed', false, null],
]);

it('still refuses a direct reply POST to a closed ticket with 422 on body (the page offering no composer is not the rule)', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner, ['status' => 'closed']);

    $this->actingAs($owner)->post(route('tickets.replies.store', $ticket), ['body' => 'Hello?'])
        ->assertSessionHasErrors(['body' => 'This request is closed. Raise a new request if you need more help.']);

    expect($ticket->replies()->count())->toBe(0);
});

// ── Create ─────────────────────────────────────────────────────────────────

it('sends the create page its exact props, with no company selector data even for a multi-company member', function () {
    $operator = ticketUser('operator');
    $member = ticketUser();
    $organization = Organization::factory()->create(['owner_id' => $operator->id]);
    $organization->members()->attach($member, ['role' => 'member']);
    CrmCompany::factory()->count(2)->create(['created_by' => $operator->id, 'organization_id' => $organization->id]);

    $response = $this->actingAs($member)->get(route('tickets.create'))->assertOk();
    $own = ticketOwnProps($response);

    expect(array_keys($own))->toEqualCanonicalizing(['categories', 'priorities', 'defaultPriority', 'attachmentPolicy'])
        ->and($own['categories'])->toBe(['General', 'Technical', 'Billing', 'Account', 'Other'])
        ->and(array_column($own['priorities'], 'value'))->toBe(['low', 'medium', 'high', 'critical'])
        ->and($own['defaultPriority'])->toBe('medium')
        ->and($own['attachmentPolicy'])->toBe(['maxFiles' => 10, 'maxSizeMb' => 20, 'extensions' => array_keys(AllowedTicketAttachment::ALLOWED)])
        ->and(json_encode($own))->not->toContain('compan');
});

it('confirms a new request with the approved flash, and never links a company', function () {
    $member = ticketUser();

    $response = $this->actingAs($member)->post(route('tickets.store'), [
        'title' => 'Cannot log in', 'description' => 'Details', 'category' => 'Account', 'priority' => 'medium',
    ]);

    $ticket = Ticket::sole();
    $response->assertRedirect(route('tickets.show', $ticket))->assertSessionHas('status', "Request {$ticket->ticket_number} received.");
    expect($ticket->company_id)->toBeNull();
});

// ── Empty states and malformed queries ─────────────────────────────────────

it('tells the three empty lists apart by their props', function () {
    $owner = ticketUser();

    $none = ticketOwnProps($this->actingAs($owner)->get(route('tickets.index')));
    expect($none['hasRequests'])->toBeFalse()->and($none['tickets']['data'])->toBe([])->and($none['tickets']['total'])->toBe(0);

    ticketFor($owner, ['title' => 'Printer offline']);

    $filtered = ticketOwnProps($this->actingAs($owner)->get(route('tickets.index', ['search' => 'zzz-no-match'])));
    expect($filtered['hasRequests'])->toBeTrue()->and($filtered['tickets']['data'])->toBe([])->and($filtered['tickets']['total'])->toBe(0);

    $past = ticketOwnProps($this->actingAs($owner)->get(route('tickets.index', ['page' => 9])));
    expect($past['hasRequests'])->toBeTrue()->and($past['tickets']['data'])->toBe([])->and($past['tickets']['total'])->toBe(1)
        ->and($past['tickets']['first_page_url'])->toContain('page=1');
});

it('does not echo malformed query values: arrays and unknown statuses reach the page as null filters', function () {
    $owner = ticketUser();
    ticketFor($owner);

    $response = $this->actingAs($owner)->get('/tickets?search[]=x&status[]=open&page[]=2')->assertOk();
    $own = ticketOwnProps($response);

    expect($own['filters'])->toBe(['search' => null, 'status' => null])
        ->and($own['tickets']['total'])->toBe(1)
        ->and(json_encode($own['tickets']['next_page_url']))->not->toContain('search');

    $unknown = ticketOwnProps($this->actingAs($owner)->get('/tickets?status=bogus&search='.str_repeat('a', 500)));
    expect($unknown['filters']['status'])->toBeNull()->and(mb_strlen($unknown['filters']['search']))->toBe(200);
});

it('carries only the normalized filters on page links', function () {
    $owner = ticketUser();
    Ticket::factory()->count(25)->for($owner, 'user')->create(['status' => 'open', 'title' => 'Printer']);

    $own = ticketOwnProps($this->actingAs($owner)->get('/tickets?search=Printer&status=open&junk=1&search2[]=x'));

    parse_str(parse_url($own['tickets']['next_page_url'], PHP_URL_QUERY), $query);
    expect($query)->toBe(['search' => 'Printer', 'status' => 'open', 'page' => '2']);
});

// ── Query budgets ──────────────────────────────────────────────────────────

/** The number of queries a request issues, after a warm-up request has primed the permission cache. */
function propsQueryCount(User $actor, string $url): int
{
    test()->actingAs($actor)->get($url)->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->actingAs($actor)->get($url)->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('issues the same number of queries for a list of one request as for a full page of them', function () {
    $owner = ticketUser();
    ticketFor($owner);
    $one = propsQueryCount($owner, route('tickets.index'));

    Ticket::factory()->count(24)->for($owner, 'user')->create(['status' => 'open']);

    expect(propsQueryCount($owner, route('tickets.index')))->toBe($one);
});

it('spends one extra query only when a filtered list is empty and the existence check is actually needed', function () {
    $owner = ticketUser();

    $none = propsQueryCount($owner, route('tickets.index'));
    ticketFor($owner);
    $matching = propsQueryCount($owner, route('tickets.index'));
    $filteredEmpty = propsQueryCount($owner, route('tickets.index', ['search' => 'zzz-no-match']));

    // Nothing at all: the count answers it. Matches: the count and the page. Filtered-empty: the count and the check.
    expect($matching)->toBe($none + 1)
        ->and($filteredEmpty)->toBe($matching);
});

it('issues the same number of queries for a one-reply conversation as for a long one with attachments', function () {
    $owner = ticketUser();
    $agent = ticketAgent();
    $ticket = ticketFor($owner, ['assignee_id' => $agent->id]);
    ticketReply($ticket, $agent, false);
    $one = propsQueryCount($owner, route('tickets.show', $ticket));

    foreach (range(1, 12) as $i) {
        $reply = ticketReply($ticket, $i % 2 ? $agent : $owner, false, "Reply {$i}");
        ticketAttachment($ticket, $reply, $agent, "file{$i}.txt", 'x');
        ticketReply($ticket, $agent, true, "Internal {$i}");
    }
    $ticket->statusHistories()->create(['user_id' => $agent->id, 'old_status' => 'open', 'new_status' => 'resolved', 'created_at' => now()]);

    expect(propsQueryCount($owner, route('tickets.show', $ticket)))->toBe($one);
});
