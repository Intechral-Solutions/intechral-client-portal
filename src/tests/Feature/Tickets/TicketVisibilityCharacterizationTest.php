<?php

use App\Models\CrmCompany;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/TicketTestHelpers.php';

/*
 * EPIC-010D: H4 (list/detail visibility), H9 (Ticket visibility keyed on the tickets.assign
 * capability, never on the literal `operator` role) and F-1 (`company_id` is never persisted by
 * the create path). WP0 characterized them; WP1 converted every defect test into the TARGET
 * contract (pre-fix evidence: EPIC-010D Amendment 1).
 *
 * Prefixes: BASELINE (already correct, keep), TARGET Hn (the WP1 contract), GUARD (a contract the
 * WP1 defense-in-depth checks must not break).
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    Storage::fake('local');
});

afterEach(function () {
    ticketCleanFakeStorage();
});

// ── H4 — list/detail mismatch ───────────────────────────────────────────────

/**
 * Owner and peer in one organization, with the owner's ticket ATTRIBUTED to that organization (R1-2: the ticket names the organization, no
 * longer a CRM company). The proof stays the same: the peer, though in the same organization and holding `tickets.view_org`, sees nothing.
 */
function ticketCompanyFixture(): array
{
    $operator = ticketUser('operator');
    $owner = ticketUser();
    // tickets.view_org is no longer a built-in default (R1-1); it is granted explicitly so the test still proves it is inert.
    $peer = tap(ticketUser())->givePermissionTo('tickets.view_org');
    $outsider = ticketUser();

    $organization = Organization::factory()->create(['owner_id' => $operator->id]);
    $organization->members()->attach($owner, ['role' => 'member']);
    $organization->members()->attach($peer, ['role' => 'member']);
    $company = CrmCompany::factory()->create(['created_by' => $operator->id, 'organization_id' => $organization->id]);

    $ticket = ticketFor($owner, ['title' => 'Company linked ticket', 'organization_id' => $organization->id]);

    return compact('operator', 'owner', 'peer', 'outsider', 'organization', 'company', 'ticket');
}

it('TARGET H4: a same-company peer holding tickets.view_org neither lists nor opens the ticket', function () {
    $f = ticketCompanyFixture();

    expect($f['peer']->can('tickets.view_org'))->toBeTrue()
        ->and($f['peer']->orgCompanyIds())->toBe([$f['company']->id]);

    // From the page's props, not its markup: a URL assertion against serialized JSON (escaped
    // slashes) would pass whatever was listed.
    $listed = ticketOwnProps($this->actingAs($f['peer'])->get(route('tickets.index'))->assertOk())['tickets']['data'];
    expect(array_column($listed, 'title'))->not->toContain('Company linked ticket')
        ->and(array_column($listed, 'number'))->not->toContain($f['ticket']->ticket_number)
        ->and(array_column($listed, 'href'))->not->toContain(route('tickets.show', $f['ticket']));

    $this->actingAs($f['peer'])->get(route('tickets.show', $f['ticket']))->assertForbidden();
    expect(Gate::forUser($f['peer'])->allows('view', $f['ticket']))->toBeFalse();
});

it('BASELINE H4: the owner lists and opens their ticket; an unrelated customer does neither', function () {
    $f = ticketCompanyFixture();

    expect(ticketListedTitles($f['owner']))->toContain('Company linked ticket');
    $this->actingAs($f['owner'])->get(route('tickets.show', $f['ticket']))->assertOk();

    expect(ticketListedTitles($f['outsider']))->not->toContain('Company linked ticket');
    $this->actingAs($f['outsider'])->get(route('tickets.show', $f['ticket']))->assertForbidden();
});

it('TARGET H4: every row on a customer list is a ticket the customer may open (no dead links)', function () {
    $f = ticketCompanyFixture();
    ticketFor($f['peer'], ['title' => 'Peer own ticket', 'organization_id' => $f['organization']->id]);
    ticketFor($f['peer'], ['title' => 'Peer unlinked ticket']);

    $listed = ticketOwnProps($this->actingAs($f['peer'])->get(route('tickets.index'))->assertOk())['tickets']['data'];

    expect(collect($listed)->pluck('title')->sort()->values()->all())->toBe(['Peer own ticket', 'Peer unlinked ticket'])
        ->and(collect($listed)->every(fn (array $row) => Gate::forUser($f['peer'])->allows('view', Ticket::where('ticket_number', $row['number'])->firstOrFail())))->toBeTrue();
});

it('BASELINE H4: /tickets is an operator\'s own-requests list, while the operator still opens any ticket', function () {
    $f = ticketCompanyFixture();
    ticketFor($f['operator'], ['title' => 'Operator own request']);

    expect(ticketListedTitles($f['operator']))->toBe(['Operator own request']);
    // P2 (EPIC-017): the operator still reaches every ticket; only the page changes.
    $this->actingAs($f['operator'])->get(route('tickets.show', $f['ticket']))
        ->assertRedirect(route('operator.tickets.show', $f['ticket']));
    $this->actingAs($f['operator'])->get(route('operator.tickets.show', $f['ticket']))->assertOk();
});

// ── F-1 — R1-2: a ticket is attributed to an ORGANIZATION; company_id is no longer an input ──────────────────

it('F-1 (R1-2, replaces the EPIC-010D baseline): creation attributes the ticket to the member\'s organization and ignores a company_id it is sent', function () {
    $operator = ticketUser('operator');
    $member = ticketUser();
    $organization = Organization::factory()->create(['owner_id' => $operator->id]);
    $organization->members()->attach($member, ['role' => 'member']);
    $company = CrmCompany::factory()->create(['created_by' => $operator->id, 'organization_id' => $organization->id]);
    $payload = ['description' => 'x', 'category' => 'General', 'priority' => 'low'];

    // A company id is neither validated nor stored any more (the column is gone); the organization is the member's own.
    $this->actingAs($member)->post(route('tickets.store'), [...$payload, 'title' => 'Sent a company', 'company_id' => $company->id])->assertRedirect();
    $this->actingAs($member)->post(route('tickets.store'), [...$payload, 'title' => 'Sent nothing'])->assertRedirect();

    $tickets = Ticket::whereIn('title', ['Sent a company', 'Sent nothing'])->get();
    expect($tickets)->toHaveCount(2)
        ->and($tickets->pluck('organization_id')->unique()->all())->toBe([$organization->id])
        ->and($tickets->pluck('submitted_by_id')->unique()->all())->toBe([$member->id])
        ->and(Schema::hasColumn('tickets', 'company_id'))->toBeFalse();
});

// ── H9 — literal operator role vs tickets.assign ────────────────────────────

/** Ticket owned by a customer, assigned to the capability-only agent, with public and internal attachments. */
function ticketRoleFixture(): array
{
    // Customers that hold time.log explicitly (R1-1: not a built-in default) so the O1 rule, not the route, refuses them.
    $owner = ticketTimeUser();
    $customer = ticketTimeUser();
    $operator = ticketUser('operator');
    $agent = ticketAgent(['name' => 'Capability Agent']);
    $ticket = ticketFor($owner, ['assignee_id' => $agent->id, 'status' => 'open', 'title' => 'Agent assigned ticket']);

    $public = ticketReply($ticket, $operator, false, 'Public answer');
    $internal = ticketReply($ticket, $operator, true, 'AGENT-VISIBLE-INTERNAL-NOTE');

    return [
        'owner' => $owner,
        'customer' => $customer,
        'operator' => $operator,
        'agent' => $agent,
        'ticket' => $ticket,
        'publicAttachment' => ticketAttachment($ticket, $public, $operator, 'public.txt', 'P'),
        'internalAttachment' => ticketAttachment($ticket, $internal, $operator, 'internal.txt', 'I'),
    ];
}

it('TARGET H9: view, reply and viewInternal key on the tickets.assign capability, never on a role name', function () {
    $f = ticketRoleFixture();
    $userWithAssign = ticketUser();
    $userWithAssign->givePermissionTo('tickets.assign');
    $ownerWithAssign = ticketUser();
    $ownerWithAssign->givePermissionTo('tickets.assign');
    $ownTicket = ticketFor($ownerWithAssign);
    $viewOnly = ticketCustomRoleUser('viewer_only', ['tickets.view']);

    $abilities = fn (User $actor, Ticket $ticket) => [
        'view' => Gate::forUser($actor)->allows('view', $ticket),
        'reply' => Gate::forUser($actor)->allows('reply', $ticket),
        'viewInternal' => Gate::forUser($actor)->allows('viewInternal', $ticket),
        'viewInternal (class)' => Gate::forUser($actor)->allows('viewInternal', Ticket::class),
    ];
    $all = ['view' => true, 'reply' => true, 'viewInternal' => true, 'viewInternal (class)' => true];
    $none = ['view' => false, 'reply' => false, 'viewInternal' => false, 'viewInternal (class)' => false];

    expect($abilities($f['owner'], $f['ticket']))->toBe(['view' => true, 'reply' => true, 'viewInternal' => false, 'viewInternal (class)' => false])
        ->and($abilities($ownerWithAssign, $ownTicket))->toBe($all)
        ->and($abilities($f['customer'], $f['ticket']))->toBe($none)
        ->and($abilities($f['operator'], $f['ticket']))->toBe($all)
        ->and($abilities($f['agent'], $f['ticket']))->toBe($all)
        ->and($abilities($userWithAssign, $f['ticket']))->toBe($all)
        ->and($abilities($viewOnly, $f['ticket']))->toBe($none);

    expect($f['agent']->hasRole('operator'))->toBeFalse()
        ->and($viewOnly->can('tickets.view'))->toBeTrue();
});

it('GUARD H9 (defense-in-depth in place): the agent still uses the operator queue, show, status, assign and reply after the policy checks were added', function () {
    $f = ticketRoleFixture();

    $this->actingAs($f['agent'])->get(route('operator.tickets.index'))->assertOk()->assertSee('Agent assigned ticket');
    $this->actingAs($f['agent'])->get(route('operator.tickets.show', $f['ticket']))
        ->assertOk()
        ->assertSee('AGENT-VISIBLE-INTERNAL-NOTE')
        ->assertSee('internal.txt');
    $this->actingAs($f['agent'])->put(route('operator.tickets.status', $f['ticket']), ['status' => 'in_progress'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($f['agent'])->put(route('operator.tickets.assign', $f['ticket']), ['assignee_id' => $f['agent']->id])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($f['agent'])->post(route('operator.tickets.replies.store', $f['ticket']), ['body' => 'Agent internal', 'is_internal' => '1'])
        ->assertRedirect();

    expect($f['ticket']->fresh()->status)->toBe('in_progress')
        ->and($f['ticket']->replies()->where('body', 'Agent internal')->sole()->is_internal)->toBeTrue();
});

it('TARGET H9: the agent reaches the ticket (P2: the operator page) and every attachment linked from it', function () {
    $f = ticketRoleFixture();

    // CONVERTED IN EPIC-017 WP2 (P2): `/tickets/{id}` is the requester page, so a capability-only agent
    // who is not the requester is sent to the operator page, which they open.
    $this->actingAs($f['agent'])->get(route('tickets.show', $f['ticket']))->assertRedirect(route('operator.tickets.show', $f['ticket']));
    $this->actingAs($f['agent'])->get(route('operator.tickets.show', $f['ticket']))->assertOk();
    $this->actingAs($f['agent'])->get(ticketAttachmentUrl($f['publicAttachment']))->assertOk();
    $this->actingAs($f['agent'])->get(ticketAttachmentUrl($f['internalAttachment']))->assertOk();
});

// FLIPPED IN EPIC-017 WP2 (O1, N1): the ticket's OWNER used to be allowed to time it (`TicketPolicy::view`
// was the whole rule). Ticket-context time is now operator work, so the owner is refused like any other
// customer, with nothing written. The agent half is unchanged.
it('TARGET H9: the agent starts a timer on, and is offered, a ticket assigned to them; no customer, the owner included, may time a ticket', function () {
    $f = ticketRoleFixture();

    $this->actingAs($f['agent'])
        ->getJson(route('time.context.options', ['type' => 'ticket']))
        ->assertOk()
        ->assertJsonPath('0.id', $f['ticket']->id);
    $this->actingAs($f['agent'])
        ->postJson(route('time.timer.start'), ticketTimeContext($f['ticket']))
        ->assertSuccessful()
        ->assertJsonPath('context.id', $f['ticket']->id);

    $this->actingAs($f['customer'])
        ->postJson(route('time.timer.start'), ticketTimeContext($f['ticket']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ticket_id');

    $this->actingAs($f['owner'])
        ->postJson(route('time.timer.start'), ticketTimeContext($f['ticket']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ticket_id');

    expect(TimeEntry::where('ticket_id', $f['ticket']->id)->where('user_id', '!=', $f['agent']->id)->exists())->toBeFalse();
});

it('BASELINE H9: dashboard and navigation already key on tickets.assign, so the agent gets the queue and operator links', function () {
    $f = ticketRoleFixture();

    $this->actingAs($f['agent'])->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('recentTickets.0.href', route('operator.tickets.show', $f['ticket']))
            ->where('navigation', fn ($navigation) => collect($navigation)->flatten()->contains('ticket-queue')));
});

it('TARGET H9: internal-note visibility follows the capability; every operator sees notes on the operator page, the requester page never carries them', function () {
    $f = ticketRoleFixture();

    // CONVERTED IN EPIC-017 WP2 (P2): the operators' `/tickets/{id}` is a redirect to the operator page, where
    // the notes are; the requester page (the owner's) is built without them.
    foreach (['agent', 'operator'] as $actor) {
        $this->actingAs($f[$actor])->get(route('tickets.show', $f['ticket']))->assertRedirect(route('operator.tickets.show', $f['ticket']));
        $this->actingAs($f[$actor])->get(route('operator.tickets.show', $f['ticket']))->assertOk()->assertSee('AGENT-VISIBLE-INTERNAL-NOTE');
    }

    $owner = $this->actingAs($f['owner'])->get(route('tickets.show', $f['ticket']))->assertOk();
    expect(json_encode(ticketOwnProps($owner)))->not->toContain('AGENT-VISIBLE-INTERNAL-NOTE');
});
