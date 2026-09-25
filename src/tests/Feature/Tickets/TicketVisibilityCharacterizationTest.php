<?php

use App\Models\CrmCompany;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
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
 * Owner and peer in one organization whose CRM company is linked to the owner's ticket. The
 * company link is written directly: no application path sets tickets.company_id (F-1).
 */
function ticketCompanyFixture(): array
{
    $operator = ticketUser('operator');
    $owner = ticketUser();
    $peer = ticketUser();
    $outsider = ticketUser();

    $organization = Organization::factory()->create(['owner_id' => $operator->id]);
    $organization->members()->attach($owner, ['role' => 'member']);
    $organization->members()->attach($peer, ['role' => 'member']);
    $company = CrmCompany::factory()->create(['created_by' => $operator->id, 'organization_id' => $organization->id]);

    $ticket = ticketFor($owner, ['title' => 'Company linked ticket', 'company_id' => $company->id]);

    return compact('operator', 'owner', 'peer', 'outsider', 'organization', 'company', 'ticket');
}

it('TARGET H4: a same-company peer holding tickets.view_org neither lists nor opens the ticket', function () {
    $f = ticketCompanyFixture();

    expect($f['peer']->can('tickets.view_org'))->toBeTrue()
        ->and($f['peer']->orgCompanyIds())->toBe([$f['company']->id]);

    $this->actingAs($f['peer'])->get(route('tickets.index'))
        ->assertOk()
        ->assertDontSee('Company linked ticket')
        ->assertDontSee($f['ticket']->ticket_number)
        ->assertDontSee(route('tickets.show', $f['ticket']), false);

    $this->actingAs($f['peer'])->get(route('tickets.show', $f['ticket']))->assertForbidden();
    expect(Gate::forUser($f['peer'])->allows('view', $f['ticket']))->toBeFalse();
});

it('BASELINE H4: the owner lists and opens their ticket; an unrelated customer does neither', function () {
    $f = ticketCompanyFixture();

    $this->actingAs($f['owner'])->get(route('tickets.index'))->assertSee('Company linked ticket');
    $this->actingAs($f['owner'])->get(route('tickets.show', $f['ticket']))->assertOk();

    $this->actingAs($f['outsider'])->get(route('tickets.index'))->assertDontSee('Company linked ticket');
    $this->actingAs($f['outsider'])->get(route('tickets.show', $f['ticket']))->assertForbidden();
});

it('TARGET H4: every row on a customer list is a ticket the customer may open (no dead links)', function () {
    $f = ticketCompanyFixture();
    ticketFor($f['peer'], ['title' => 'Peer own ticket', 'company_id' => $f['company']->id]);
    ticketFor($f['peer'], ['title' => 'Peer unlinked ticket']);

    $listed = $this->actingAs($f['peer'])->get(route('tickets.index'))->assertOk()->viewData('tickets');

    expect($listed->pluck('title')->sort()->values()->all())->toBe(['Peer own ticket', 'Peer unlinked ticket'])
        ->and($listed->every(fn (Ticket $ticket) => Gate::forUser($f['peer'])->allows('view', $ticket)))->toBeTrue();
});

it('BASELINE H4: /tickets is an operator\'s own-requests list, while the operator still opens any ticket', function () {
    $f = ticketCompanyFixture();
    ticketFor($f['operator'], ['title' => 'Operator own request']);

    $this->actingAs($f['operator'])->get(route('tickets.index'))
        ->assertSee('Operator own request')
        ->assertDontSee('Company linked ticket');
    $this->actingAs($f['operator'])->get(route('tickets.show', $f['ticket']))->assertOk();
});

// ── F-1 — company_id is validated but never persisted ───────────────────────

it('BASELINE F-1 (D3: no write-path fix in EPIC-010D): ticket creation never persists company_id, chosen or auto-selected', function () {
    $operator = ticketUser('operator');
    $member = ticketUser();
    $organization = Organization::factory()->create(['owner_id' => $operator->id]);
    $organization->members()->attach($member, ['role' => 'member']);
    $company = CrmCompany::factory()->create(['created_by' => $operator->id, 'organization_id' => $organization->id]);
    $payload = ['description' => 'x', 'category' => 'General', 'priority' => 'low'];

    // Explicitly chosen (passes AccessibleCrmCompany validation)...
    $this->actingAs($member)->post(route('tickets.store'), [...$payload, 'title' => 'Chosen company', 'company_id' => $company->id])
        ->assertRedirect();
    // ...and auto-selected for a single-organization member.
    $this->actingAs($member)->post(route('tickets.store'), [...$payload, 'title' => 'Auto company'])
        ->assertRedirect();

    expect(Ticket::whereIn('title', ['Chosen company', 'Auto company'])->pluck('company_id')->all())->toBe([null, null]);
});

// ── H9 — literal operator role vs tickets.assign ────────────────────────────

/** Ticket owned by a customer, assigned to the capability-only agent, with public and internal attachments. */
function ticketRoleFixture(): array
{
    $owner = ticketUser();
    $customer = ticketUser();
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

it('TARGET H9: the agent opens the user show page and every attachment linked from the operator show page', function () {
    $f = ticketRoleFixture();

    $this->actingAs($f['agent'])->get(route('tickets.show', $f['ticket']))->assertOk();
    $this->actingAs($f['agent'])->get(route('tickets.attachment.download', $f['publicAttachment']))->assertOk();
    $this->actingAs($f['agent'])->get(route('tickets.attachment.download', $f['internalAttachment']))->assertOk();
});

it('TARGET H9: the agent starts a timer on, and is offered, a ticket assigned to them; a customer still cannot use a foreign ticket', function () {
    $f = ticketRoleFixture();

    $this->actingAs($f['agent'])
        ->getJson(route('time.context.options', ['type' => 'ticket']))
        ->assertOk()
        ->assertJsonPath('0.id', $f['ticket']->id);
    $this->actingAs($f['agent'])
        ->postJson(route('time.timer.start'), ['ticket_id' => $f['ticket']->id])
        ->assertSuccessful()
        ->assertJsonPath('context.id', $f['ticket']->id);

    $this->actingAs($f['customer'])
        ->postJson(route('time.timer.start'), ['ticket_id' => $f['ticket']->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ticket_id');

    $this->actingAs($f['owner'])
        ->postJson(route('time.timer.start'), ['ticket_id' => $f['ticket']->id])
        ->assertSuccessful();
});

it('BASELINE H9: dashboard and navigation already key on tickets.assign, so the agent gets the queue and operator links', function () {
    $f = ticketRoleFixture();

    $this->actingAs($f['agent'])->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('recentTickets.0.href', route('operator.tickets.show', $f['ticket']))
            ->where('navigation', fn ($navigation) => collect($navigation)->flatten()->contains('ticket-queue')));
});

it('TARGET H9: internal-note visibility no longer depends on the route; the agent sees notes on both show pages, the owner on neither', function () {
    $f = ticketRoleFixture();

    $this->actingAs($f['agent'])->get(route('operator.tickets.show', $f['ticket']))->assertSee('AGENT-VISIBLE-INTERNAL-NOTE');
    $this->actingAs($f['agent'])->get(route('tickets.show', $f['ticket']))->assertOk()->assertSee('AGENT-VISIBLE-INTERNAL-NOTE');
    $this->actingAs($f['operator'])->get(route('tickets.show', $f['ticket']))->assertOk()->assertSee('AGENT-VISIBLE-INTERNAL-NOTE');
    $this->actingAs($f['owner'])->get(route('tickets.show', $f['ticket']))->assertOk()->assertDontSee('AGENT-VISIBLE-INTERNAL-NOTE');
});
