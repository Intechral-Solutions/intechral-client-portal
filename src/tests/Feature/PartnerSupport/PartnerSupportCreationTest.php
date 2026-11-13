<?php

/*
 * Release 1.0 (R1-5): partner support staff raise a request for a CONTACT at a managed customer, in PILOT mode (the mode customers run in).
 *
 * The three values are decided by the server and are independent: `user_id` is the contact (an eligible customer contact of the organization),
 * `organization_id` the managed customer (the actor holds effective support authority over it RIGHT NOW), `submitted_by_id` the authenticated actor.
 * Nothing in the payload can set any of them. The race against revocation, suspension and membership removal is in PartnerSupportConcurrencyTest
 * (real second connection, cases F to J).
 */

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Notifications\NewTicketAlertNotification;
use App\Notifications\TicketCreatedNotification;
use App\Services\OrganizationRelationships;
use App\Services\PartnerSupportAccess;
use App\Services\PartnerSupportGrants;
use App\Services\ServiceRequestAccess;
use App\Services\TicketService;
use App\Support\PartnerSupportCreateThrottle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/PartnerSupportTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Storage::fake('local');
    Notification::fake();
    pilotMode('pilot');
});

afterEach(function () {
    ticketCleanFakeStorage();
});

function pscPayload(array $w, array $extra = []): array
{
    return [
        'organization_id' => $w['customer']->id,
        'requester_id' => $w['requester']->id,
        'title' => 'Printer offline',
        'description' => 'It stopped printing this morning.',
        'category' => 'General',
        'priority' => 'low',
        ...$extra,
    ];
}

/** What a refused creation must not leave behind. */
function pscAssertNothingWritten(int $tickets): void
{
    expect(Ticket::count())->toBe($tickets)
        ->and(TicketAttachment::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    Notification::assertNothingSent();
}

// ── the happy path and provenance ───────────────────────

it('records the contact as requester, the managed customer as organization and the staff member as submitter', function () {
    $w = psWorld();
    Ticket::query()->delete();

    $response = $this->actingAs($w['staff'])->post(route('support.store'), pscPayload($w));

    $ticket = Ticket::sole();
    $response->assertRedirect(route('support.show', $ticket));

    expect($ticket->user_id)->toBe($w['requester']->id)
        ->and($ticket->submitted_by_id)->toBe($w['staff']->id)
        ->and($ticket->organization_id)->toBe($w['customer']->id)
        ->and($ticket->status)->toBe('open')
        ->and($ticket->assignee_id)->toBeNull()
        ->and($ticket->ticket_number)->toMatch('/^TK-[A-Z0-9]{5}-[A-Z0-9]{5}$/');

    // The partner can read it on the support surface, the contact owns it on their own, and the partner's ordinary requester page does NOT widen.
    $this->actingAs($w['staff'])->get(route('support.show', $ticket))->assertOk();
    $this->actingAs($w['requester'])->get(route('tickets.show', $ticket))->assertOk();
    $this->actingAs($w['staff'])->get(route('tickets.show', $ticket))->assertForbidden();
    expect(DB::table('ticket_status_histories')->where('ticket_id', $ticket->id)->count())->toBe(0);
});

it('mails the REQUESTER their receipt and the helpdesk its alert, and the submitter nothing', function () {
    config(['helpdesk.notify_address' => 'helpdesk@desk.invalid']);
    $w = psWorld();

    $this->actingAs($w['staff'])->post(route('support.store'), pscPayload($w))->assertRedirect();

    Notification::assertSentTo($w['requester'], TicketCreatedNotification::class);
    Notification::assertNotSentTo($w['staff'], TicketCreatedNotification::class);
    Notification::assertSentTimes(TicketCreatedNotification::class, 1);
    Notification::assertSentOnDemandTimes(NewTicketAlertNotification::class, 1);
});

it('does not mail a contact who was suspended between the commit decision and the hand-over', function () {
    $w = psWorld();

    // The hand-over runs after the OUTER transaction commits and re-reads the account: a contact suspended inside it is never mailed.
    DB::transaction(function () use ($w) {
        app(TicketService::class)->createForCustomerContact($w['staff'], $w['customer']->id, $w['requester']->id, ['title' => 'T', 'description' => 'D', 'category' => 'General', 'priority' => 'low']);
        DB::table('users')->where('id', $w['requester']->id)->update(['disabled_at' => now()]);
    });

    expect(Ticket::where('title', 'T')->sole()->user_id)->toBe($w['requester']->id);
    Notification::assertNotSentTo($w['requester'], TicketCreatedNotification::class);
});

it('records the staff member as the uploader of the opening attachments, and both parties can download them', function () {
    $w = psWorld();
    Ticket::query()->delete();

    $this->actingAs($w['staff'])->post(route('support.store'), pscPayload($w, [
        'attachments' => [ticketUpload('screenshot.pdf')],
    ]))->assertRedirect();

    $ticket = Ticket::sole();
    $attachment = TicketAttachment::sole();
    expect($attachment->ticket_id)->toBe($ticket->id)->and($attachment->reply_id)->toBeNull()->and($attachment->user_id)->toBe($w['staff']->id);

    $this->actingAs($w['staff'])->get(route('support.attachment.download', [$ticket, $attachment]))->assertOk();
    $this->actingAs($w['requester'])->get(route('tickets.attachment.download', [$ticket, $attachment]))->assertOk();
});

it('shows the request on the contact\'s own list and on the staff member\'s supported list', function () {
    $w = psWorld();
    Ticket::query()->delete();

    $this->actingAs($w['staff'])->post(route('support.store'), pscPayload($w, ['title' => 'Via partner']));

    $this->actingAs($w['requester'])->get(route('tickets.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tickets.data.0.title', 'Via partner'));
    $this->actingAs($w['staff'])->get(route('support.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tickets.data.0.title', 'Via partner')->where('tickets.data.0.requester', 'Rae Requester'));
});

// ── nothing in the payload decides attribution ──────────

it('ignores a forged requester, submitter, status, assignee, due date or internal flag in the payload', function () {
    $w = psWorld();
    Ticket::query()->delete();
    $other = pilotCustomer(['name' => 'Eve Other']);

    $this->actingAs($w['staff'])->post(route('support.store'), pscPayload($w, [
        'user_id' => $other->id, 'submitted_by_id' => $other->id, 'status' => 'resolved', 'assignee_id' => $w['operator']->id,
        'due_override_at' => now()->addDay()->toIso8601String(), 'is_internal' => true, 'company_id' => 1,
    ]))->assertRedirect();

    $ticket = Ticket::sole();
    expect([$ticket->user_id, $ticket->submitted_by_id, $ticket->organization_id])->toBe([$w['requester']->id, $w['staff']->id, $w['customer']->id])
        ->and($ticket->status)->toBe('open')
        ->and($ticket->assignee_id)->toBeNull()
        ->and($ticket->due_override_at)->toBeNull();
});

it('requires both ids as integers and does not say whether anything exists', function (array $override, string $field) {
    $w = psWorld();
    $before = Ticket::count();

    $this->actingAs($w['staff'])->post(route('support.store'), array_replace(pscPayload($w), $override))->assertSessionHasErrors($field);

    pscAssertNothingWritten($before);
})->with([
    'no customer' => [['organization_id' => ''], 'organization_id'],
    'no contact' => [['requester_id' => ''], 'requester_id'],
    'customer is text' => [['organization_id' => 'Charter'], 'organization_id'],
    'contact is an email' => [['requester_id' => 'rae@example.test'], 'requester_id'],
    'contact is an array' => [['requester_id' => [1, 2]], 'requester_id'],
]);

// ── the contact ─────────────────────────────────────────

dataset('pscIneligibleContacts', [
    'a member of an unrelated organization only' => function (array $w) {
        $user = pilotCustomer();
        psJoin($user, psPaired('Elsewhere'));

        return $user;
    },
    'a member of the partner only (a colleague)' => function (array $w) {
        $user = pilotCustomer();
        psJoin($user, $w['partner']);

        return $user;
    },
    'a customer with no membership at all' => fn (array $w) => pilotCustomer(),
    'an operator who is a member of the customer' => function (array $w) {
        $operator = pilotOperator();
        psJoin($operator, $w['customer']);

        return $operator;
    },
    'a suspended member of the customer' => function (array $w) {
        $user = pilotCustomer(['disabled_at' => now()]);
        psJoin($user, $w['customer']);

        return $user;
    },
    'a member who cannot raise a request (no tickets.create)' => function (array $w) {
        $user = pilotCustomRoleUser('viewer_only', ['tickets.view']);
        psJoin($user, $w['customer']);

        return $user;
    },
    'a member who cannot read requests (no tickets.view)' => function (array $w) {
        $user = pilotCustomRoleUser('creator_only', ['tickets.create']);
        psJoin($user, $w['customer']);

        return $user;
    },
    'a member of the customer who also holds portal.operator directly' => function (array $w) {
        $user = pilotCustomer();
        psJoin($user, $w['customer']);
        $user->givePermissionTo('portal.operator');

        return $user;
    },
]);

it('refuses an ineligible contact with the one contact message, whatever the reason, and writes nothing', function (Closure $make) {
    $w = psWorld();
    $before = Ticket::count();
    $contact = $make($w);

    $this->actingAs($w['staff'])->post(route('support.store'), pscPayload($w, ['requester_id' => $contact->id]))
        ->assertSessionHasErrors(['requester_id' => ServiceRequestAccess::SUPPORT_CONTACT_UNAVAILABLE])
        ->assertSessionDoesntHaveErrors('organization_id');

    pscAssertNothingWritten($before);
})->with('pscIneligibleContacts');

it('refuses a contact id that does not exist with the same message as one that exists but is ineligible', function () {
    $w = psWorld();
    $before = Ticket::count();

    $this->actingAs($w['staff'])->post(route('support.store'), pscPayload($w, ['requester_id' => 99999999]))
        ->assertSessionHasErrors(['requester_id' => ServiceRequestAccess::SUPPORT_CONTACT_UNAVAILABLE]);

    pscAssertNothingWritten($before);
});

it('does not accept the staff member themselves as the contact unless they are an eligible member of the customer', function () {
    $w = psWorld();
    $before = Ticket::count();

    $this->actingAs($w['staff'])->post(route('support.store'), pscPayload($w, ['requester_id' => $w['staff']->id]))
        ->assertSessionHasErrors('requester_id');

    pscAssertNothingWritten($before);
});

it('accepts a staff member who is ALSO a member of the customer as their own contact, with both ids equal', function () {
    $w = psWorld();
    psJoin($w['staff'], $w['customer']);
    Ticket::query()->delete();

    $this->actingAs($w['staff'])->post(route('support.store'), pscPayload($w, ['requester_id' => $w['staff']->id]))->assertRedirect();

    $ticket = Ticket::sole();
    expect([$ticket->user_id, $ticket->submitted_by_id])->toBe([$w['staff']->id, $w['staff']->id]);
});

// ── the customer organization ───────────────────────────

dataset('pscUnsupportedCustomers', [
    'an organization the partner does not manage' => function (array $w) {
        $other = psPaired('Elsewhere');
        $contact = pilotCustomer();
        psJoin($contact, $other);

        return [$other, $contact];
    },
    "the partner's own organization" => function (array $w) {
        $contact = pilotCustomer();
        psJoin($contact, $w['partner']);

        return [$w['partner'], $contact];
    },
    'a customer managed by ANOTHER partner' => function (array $w) {
        $otherPartner = psPaired('Other Partner');
        app(OrganizationRelationships::class)->designatePartner($otherPartner, $w['operator']);
        $theirs = psPaired('Theirs');
        app(OrganizationRelationships::class)->assignCustomer($theirs, $otherPartner, $w['operator']);
        $contact = pilotCustomer();
        psJoin($contact, $theirs);

        return [$theirs, $contact];
    },
    'a customer that is disabled' => function (array $w) {
        app(OrganizationRelationships::class)->disable($w['customer'], $w['operator']);

        return [$w['customer'], $w['requester']];
    },
    'a customer that was unlinked from the partner' => function (array $w) {
        app(OrganizationRelationships::class)->unlinkCustomer($w['customer'], $w['operator']);

        return [$w['customer'], $w['requester']];
    },
    'an organization that does not exist' => fn (array $w) => [(object) ['id' => 99999999], $w['requester']],
]);

it('refuses an organization the actor does not support with the one customer message, and writes nothing', function (Closure $make) {
    $w = psWorld();
    $before = Ticket::count();
    [$organization, $contact] = $make($w);

    $this->actingAs($w['staff'])->post(route('support.store'), pscPayload($w, ['organization_id' => $organization->id, 'requester_id' => $contact->id]))
        ->assertSessionHasErrors(['organization_id' => ServiceRequestAccess::SUPPORT_CUSTOMER_UNAVAILABLE])
        ->assertSessionDoesntHaveErrors('requester_id');

    pscAssertNothingWritten($before);
})->with('pscUnsupportedCustomers');

it('does not let a contact of ANOTHER supported customer be filed under this customer', function () {
    $w = psWorld();
    $second = psPaired('Second Customer');
    app(OrganizationRelationships::class)->assignCustomer($second, $w['partner'], $w['operator']);
    $stranger = pilotCustomer();
    psJoin($stranger, $second);
    $before = Ticket::count();

    $this->actingAs($w['staff'])->post(route('support.store'), pscPayload($w, ['requester_id' => $stranger->id]))
        ->assertSessionHasErrors('requester_id');

    pscAssertNothingWritten($before);

    // Their own customer works.
    $this->actingAs($w['staff'])->post(route('support.store'), pscPayload($w, ['organization_id' => $second->id, 'requester_id' => $stranger->id]))->assertRedirect();
    expect(Ticket::query()->latest('id')->first()->organization_id)->toBe($second->id);
});

// ── the actor ───────────────────────────────────────────

dataset('pscOutsiders', [
    'a partner member without a designation' => function (array $w) {
        $member = pilotCustomer();
        psJoin($member, $w['partner']);

        return $member;
    },
    'a designation that was revoked' => function (array $w) {
        app(PartnerSupportGrants::class)->revoke($w['partner'], $w['staff'], $w['operator']);

        return $w['staff'];
    },
    'the requester (a customer member)' => fn (array $w) => $w['requester'],
    'a customer with no membership' => fn (array $w) => pilotCustomer(),
    'an operator holding a designation' => function (array $w) {
        $operator = pilotOperator();
        psJoin($operator, $w['partner']);
        DB::table('organization_members')->where('user_id', $operator->id)->update(['support_granted_at' => now(), 'support_granted_by' => $w['operator']->id]);

        return $operator;
    },
    'a designated member of a partner that is disabled' => function (array $w) {
        app(OrganizationRelationships::class)->disable($w['partner'], $w['operator']);

        return $w['staff'];
    },
    'a designated member whose account is suspended' => function (array $w) {
        DB::table('users')->where('id', $w['staff']->id)->update(['disabled_at' => now()]);

        return $w['staff'];
    },
    'a designated member after the partner designation was removed' => function (array $w) {
        DB::table('organizations')->where('id', $w['partner']->id)->update(['can_manage_customers' => 0, 'managed_by_organization_id' => null]);

        return $w['staff'];
    },
]);

it('answers 404 on both creation routes to everyone without authority, and writes nothing', function (Closure $actor) {
    $w = psWorld();
    $before = Ticket::count();
    $user = $actor($w);

    $this->actingAs($user)->get(route('support.create'))->assertNotFound();
    $this->actingAs($user)->post(route('support.store'), pscPayload($w))->assertNotFound();

    pscAssertNothingWritten($before);
})->with('pscOutsiders');

it('gives a designated member of an UNRELATED partner an empty page and a 422 on the customer, never the managed customer or its contact', function () {
    $w = psWorld();
    $before = Ticket::count();
    $other = psPaired('Other Partner');
    app(OrganizationRelationships::class)->designatePartner($other, $w['operator']);
    $stranger = pilotCustomer();
    psJoin($stranger, $other);
    app(PartnerSupportGrants::class)->grant($other, $stranger, $w['operator']);

    $this->actingAs($stranger)->get(route('support.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('customers', 0));
    $this->actingAs($stranger)->post(route('support.store'), pscPayload($w))->assertSessionHasErrors(['organization_id' => ServiceRequestAccess::SUPPORT_CUSTOMER_UNAVAILABLE]);

    pscAssertNothingWritten($before);
});

it('does not require tickets.create of the staff member: the designation is the authority', function () {
    $w = psWorld();
    Ticket::query()->delete();
    // A designated member whose own role cannot raise requests (a custom role) still uses the support route.
    $staff = pilotCustomRoleUser('support_only', ['tickets.view']);
    psJoin($staff, $w['partner']);
    app(PartnerSupportGrants::class)->grant($w['partner'], $staff, $w['operator']);

    $this->actingAs($staff)->post(route('support.store'), pscPayload($w))->assertRedirect();

    expect(Ticket::sole()->submitted_by_id)->toBe($staff->id);
});

it('limits creation to 60 attempts an hour per account, counting refused ones, and creates and mails nothing past the limit', function () {
    $w = psWorld();
    $before = Ticket::count();

    // Refused attempts count (an unavailable contact), so a probe cannot be free either.
    for ($i = 0; $i < PartnerSupportCreateThrottle::ATTEMPTS_PER_HOUR; $i++) {
        $this->actingAs($w['staff'])->post(route('support.store'), pscPayload($w, ['requester_id' => 99999999]))->assertSessionHasErrors('requester_id');
    }

    $this->actingAs($w['staff'])->post(route('support.store'), pscPayload($w))->assertStatus(429);

    // Reading is not limited.
    $this->actingAs($w['staff'])->get(route('support.create'))->assertOk();
    pscAssertNothingWritten($before);
});

it('is absent in closed mode and for guests', function () {
    $w = psWorld();
    $before = Ticket::count();
    pilotMode('closed');

    $this->actingAs($w['staff'])->get(route('support.create'))->assertNotFound();
    $this->actingAs($w['staff'])->post(route('support.store'), pscPayload($w))->assertNotFound();

    auth()->logout();
    $this->get(route('support.create'))->assertRedirect(route('login'));
    $this->post(route('support.store'), pscPayload($w))->assertRedirect(route('login'));

    pscAssertNothingWritten($before);
});

it('is not reachable through the ordinary creation route: a requester_id there is ignored and the actor is the requester', function () {
    $w = psWorld();
    $stranger = pilotCustomer();
    psJoin($stranger, $w['partner']);
    Ticket::query()->delete();

    // The staff member files an ordinary request for their OWN partner organization, naming a different requester: it is not read.
    $this->actingAs($stranger)->post(route('tickets.store'), [
        'title' => 'Mine', 'description' => 'Mine', 'category' => 'General', 'priority' => 'low',
        'requester_id' => $w['requester']->id, 'organization_id' => $w['customer']->id,
    ])->assertSessionHasErrors('organization_id');

    expect(Ticket::count())->toBe(0);

    $this->actingAs($w['staff'])->post(route('tickets.store'), [
        'title' => 'Mine', 'description' => 'Mine', 'category' => 'General', 'priority' => 'low', 'requester_id' => $w['requester']->id,
    ])->assertRedirect();

    $ticket = Ticket::sole();
    expect([$ticket->user_id, $ticket->submitted_by_id, $ticket->organization_id])->toBe([$w['staff']->id, $w['staff']->id, $w['partner']->id]);
});

it('keeps ordinary creation closed to a staff member who names a managed customer they are not a member of', function () {
    $w = psWorld();
    Ticket::query()->delete();

    $this->actingAs($w['staff'])->post(route('tickets.store'), [
        'title' => 'Sneaky', 'description' => 'x', 'category' => 'General', 'priority' => 'low', 'organization_id' => $w['customer']->id,
    ])->assertSessionHasErrors(['organization_id' => ServiceRequestAccess::NOT_AVAILABLE]);

    expect(Ticket::count())->toBe(0);
});

it('does not give the staff member any other door: a request raised for a contact is not visible on the ordinary or operator routes', function () {
    $w = psWorld();
    Ticket::query()->delete();
    $this->actingAs($w['staff'])->post(route('support.store'), pscPayload($w));
    $ticket = Ticket::sole();

    $this->actingAs($w['staff'])->get(route('tickets.show', $ticket))->assertForbidden();
    $this->actingAs($w['staff'])->post(route('tickets.replies.store', $ticket), ['body' => 'x'])->assertForbidden();
    $this->actingAs($w['staff'])->get(route('operator.tickets.show', $ticket))->assertNotFound();
    $this->actingAs($w['staff'])->get(route('tickets.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('tickets.total', 0));
});

// ── the page ────────────────────────────────────────────

it('offers exactly the supported customers and their eligible contacts, with names and emails and nothing else', function () {
    $w = psWorld();
    // An ineligible member of the customer, and a contact of an unsupported organization: neither is offered.
    $operator = pilotOperator(['name' => 'Operator Member']);
    psJoin($operator, $w['customer']);
    $suspended = pilotCustomer(['name' => 'Suspended Sue', 'disabled_at' => now()]);
    psJoin($suspended, $w['customer']);
    $elsewhere = pilotCustomer(['name' => 'Elsewhere Eve']);
    psJoin($elsewhere, psPaired('Elsewhere'));
    $second = pilotCustomer(['name' => 'Aaron First', 'email' => 'aaron@example.test']);
    psJoin($second, $w['customer']);

    $this->actingAs($w['staff'])->get(route('support.create'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('support/create')
            ->has('customers', 1)
            ->where('customers.0.id', $w['customer']->id)
            ->where('customers.0.name', 'Charter')
            ->has('customers.0.contacts', 2)
            ->where('customers.0.contacts.0.name', 'Aaron First')
            ->where('customers.0.contacts.1.name', 'Rae Requester')
            ->has('customers.0.contacts.0', fn (Assert $contact) => $contact->hasAll(['id', 'name', 'email'])->etc())
            ->whereType('categories', 'array')
            ->where('defaultPriority', 'medium')
            ->has('attachmentPolicy')
            ->missing('organizations'));

    $raw = $this->actingAs($w['staff'])->get(route('support.create'))->getContent();
    expect($raw)->not->toContain('Operator Member')->not->toContain('Suspended Sue')->not->toContain('Elsewhere Eve');
});

it('offers the same customers and contacts the creation rule would accept, across a state matrix (the two readings agree)', function () {
    $w = psWorld();
    $second = psPaired('Second');
    app(OrganizationRelationships::class)->assignCustomer($second, $w['partner'], $w['operator']);
    $contacts = [
        'ok' => $w['requester'],
        'operator' => tap(pilotOperator(), fn ($u) => psJoin($u, $w['customer'])),
        'suspended' => tap(pilotCustomer(['disabled_at' => now()]), fn ($u) => psJoin($u, $w['customer'])),
        'nocaps' => tap(pilotCustomRoleUser('nothing', []), fn ($u) => psJoin($u, $w['customer'])),
        'other' => tap(pilotCustomer(), fn ($u) => psJoin($u, $second)),
        'nowhere' => pilotCustomer(),
    ];

    $steps = [
        fn () => null,
        fn () => app(OrganizationRelationships::class)->disable($second, $w['operator']),
        fn () => app(OrganizationRelationships::class)->disable($w['customer'], $w['operator']),
        fn () => app(OrganizationRelationships::class)->enable($w['customer'], $w['operator']),
        fn () => app(PartnerSupportGrants::class)->revoke($w['partner'], $w['staff'], $w['operator']),
    ];

    foreach ($steps as $step) {
        $step();

        $offered = collect(PartnerSupportAccess::creationOptions($w['staff']->id))
            ->flatMap(fn ($c) => collect($c['contacts'])->map(fn ($u) => [$c['id'], $u['id']]))->all();
        usort($offered, fn ($a, $b) => $a <=> $b);

        $accepted = [];
        foreach ([$w['customer'], $second] as $customer) {
            foreach ($contacts as $contact) {
                $refusal = DB::transaction(fn () => PartnerSupportAccess::refusal($w['staff']->id, $customer->id, $contact->id));

                if ($refusal === null) {
                    $accepted[] = [$customer->id, $contact->id];
                }
            }
        }
        usort($accepted, fn ($a, $b) => $a <=> $b);

        expect($offered)->toBe($accepted);
    }
});

// ── attribution contract is unchanged for the requester's own path ──

it('leaves ordinary creation exactly as it was: the actor is requester and submitter, the membership decides the organization', function () {
    $requester = pilotCustomer();
    $organization = ticketJoin($requester);

    $this->actingAs($requester)->post(route('tickets.store'), ['title' => 'Own', 'description' => 'Own', 'category' => 'General', 'priority' => 'low'])->assertRedirect();

    $ticket = Ticket::sole();
    expect([$ticket->user_id, $ticket->submitted_by_id, $ticket->organization_id])->toBe([$requester->id, $requester->id, $organization->id]);
});

it('cannot reach the partner path from the service without an effective authority: an operator and an ungranted member are refused', function () {
    $w = psWorld();
    $before = Ticket::count();
    $member = pilotCustomer();
    psJoin($member, $w['partner']);

    foreach ([$member, $w['operator']] as $actor) {
        expect(fn () => app(TicketService::class)->createForCustomerContact($actor, $w['customer']->id, $w['requester']->id, ['title' => 'T', 'description' => 'D', 'category' => 'General', 'priority' => 'low']))
            ->toThrow(HttpException::class);
    }

    expect(Ticket::count())->toBe($before);
});
