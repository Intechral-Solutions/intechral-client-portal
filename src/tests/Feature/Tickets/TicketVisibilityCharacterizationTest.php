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
 * EPIC-010D WP0 characterization: H4 (list/detail mismatch through legacy company membership),
 * H9 (literal `operator` role in TicketPolicy vs the `tickets.assign` route gate) and F-1
 * (`company_id` is never persisted by the create path).
 *
 * Prefixes as in TicketSecurityCharacterizationTest: BASELINE (keep), DEFECT Hn (current broken
 * behavior, flipped by the named WP), GUARD (a contract WP1 must not break while fixing Hn).
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

it('DEFECT H4 (WP1 flips to not listed): a same-company peer sees the ticket on /tickets with a link that 403s', function () {
    $f = ticketCompanyFixture();

    expect($f['peer']->can('tickets.view_org'))->toBeTrue()
        ->and($f['peer']->orgCompanyIds())->toBe([$f['company']->id]);

    $this->actingAs($f['peer'])->get(route('tickets.index'))
        ->assertOk()
        ->assertSee('Company linked ticket')
        ->assertSee($f['ticket']->ticket_number)
        ->assertSee(route('tickets.show', $f['ticket']), false);

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

it('BASELINE H4: the company clause is driven by tickets.view_org; a member without it does not see the peer ticket', function () {
    $f = ticketCompanyFixture();
    $member = ticketCustomRoleUser('customer_no_org', ['tickets.view']);
    $f['organization']->members()->attach($member, ['role' => 'member']);

    $this->actingAs($member)->get(route('tickets.index'))
        ->assertOk()
        ->assertDontSee('Company linked ticket');
});

it('BASELINE H4: an operator opens any ticket but /tickets lists only their own and company-linked ones', function () {
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

it('DEFECT H9 (WP1 flips agent and user+assign to true): TicketPolicy::view keys operator visibility on the literal role, not on tickets.assign', function () {
    $f = ticketRoleFixture();
    $userWithAssign = ticketUser();
    $userWithAssign->givePermissionTo('tickets.assign');

    $matrix = collect([
        'owner' => $f['owner'],
        'customer' => $f['customer'],
        'operator' => $f['operator'],
        'agent (custom role: tickets.view + tickets.assign)' => $f['agent'],
        'user role + direct tickets.assign' => $userWithAssign,
    ])->map(fn (User $actor) => Gate::forUser($actor)->allows('view', $f['ticket']))->all();

    expect($matrix)->toBe([
        'owner' => true,
        'customer' => false,
        'operator' => true,
        'agent (custom role: tickets.view + tickets.assign)' => false,
        'user role + direct tickets.assign' => false,
    ]);
    expect($f['agent']->can('tickets.assign'))->toBeTrue()
        ->and($f['agent']->hasRole('operator'))->toBeFalse();
});

it('GUARD H9 (must stay 200 through WP1; see the authorize(view) sequencing rule): the route gate alone lets the agent use the operator queue, show, status, assign and reply', function () {
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

it('DEFECT H9 (WP1 flips to allowed): the same agent is refused the user show page and every attachment link rendered on the operator show page', function () {
    $f = ticketRoleFixture();

    $this->actingAs($f['agent'])->get(route('tickets.show', $f['ticket']))->assertForbidden();
    $this->actingAs($f['agent'])->get(route('tickets.attachment.download', $f['publicAttachment']))->assertForbidden();
    $this->actingAs($f['agent'])->get(route('tickets.attachment.download', $f['internalAttachment']))->assertForbidden();

    // Control: the built-in operator gets both.
    $this->actingAs($f['operator'])->get(route('tickets.attachment.download', $f['internalAttachment']))->assertOk();
});

it('DEFECT H9 (WP1 flips to allowed): the agent cannot start a timer on, or be offered, a ticket assigned to them', function () {
    $f = ticketRoleFixture();

    $this->actingAs($f['agent'])
        ->postJson(route('time.timer.start'), ['ticket_id' => $f['ticket']->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ticket_id');

    $this->actingAs($f['agent'])
        ->getJson(route('time.context.options', ['type' => 'ticket']))
        ->assertOk()
        ->assertExactJson([]);

    // Control: the same ticket assigned to the built-in operator is offered.
    $f['ticket']->update(['assignee_id' => $f['operator']->id]);
    $this->actingAs($f['operator'])
        ->getJson(route('time.context.options', ['type' => 'ticket']))
        ->assertOk()
        ->assertJsonPath('0.id', $f['ticket']->id);
});

it('BASELINE H9: dashboard and navigation already key on tickets.assign, so the agent gets the queue and operator links', function () {
    $f = ticketRoleFixture();

    $this->actingAs($f['agent'])->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('recentTickets.0.href', route('operator.tickets.show', $f['ticket']))
            ->where('navigation', fn ($navigation) => collect($navigation)->flatten()->contains('ticket-queue')));
});

it('DEFECT H9 (WP1 flips to allowed): the agent\'s internal-note visibility depends on the route used, because the user show page is policy-gated', function () {
    $f = ticketRoleFixture();

    // Operator show (route gate only): sees the internal note. User show (policy): never reached.
    $this->actingAs($f['agent'])->get(route('operator.tickets.show', $f['ticket']))->assertSee('AGENT-VISIBLE-INTERNAL-NOTE');
    $this->actingAs($f['agent'])->get(route('tickets.show', $f['ticket']))->assertForbidden();

    // Built-in operator: both routes, both show the note.
    $this->actingAs($f['operator'])->get(route('tickets.show', $f['ticket']))->assertOk()->assertSee('AGENT-VISIBLE-INTERNAL-NOTE');
});
