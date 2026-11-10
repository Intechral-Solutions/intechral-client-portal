<?php

use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\ServiceRequestAccess;
use App\Services\TicketService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/TicketTestHelpers.php';

/*
 * Release 1.0 (R1-2A): who may create a Ticket for which organization, and what the Ticket then records. Visibility is NOT under test
 * here except to prove it did not widen: attribution is provenance, and TicketPolicy still answers "requester or operator" only.
 * The race against a membership removal is in TicketAttributionConcurrencyTest (real second connection).
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    Storage::fake('local');
});

function attrPayload(array $extra = []): array
{
    return ['title' => 'Printer offline', 'description' => 'It stopped.', 'category' => 'General', 'priority' => 'low', ...$extra];
}

/** Everything a refused creation must not leave behind. */
function attrAssertNothingWritten(): void
{
    expect(Ticket::count())->toBe(0)
        ->and(TicketReply::count())->toBe(0)
        ->and(TicketAttachment::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(DB::table('activity_log')->where('subject_type', Ticket::class)->count())->toBe(0);

    Notification::assertNothingSent();
}

/** A partner organization whose member manages the customer organization (relationship columns are not mass-assignable). */
function attrManagedPair(): array
{
    $partner = Organization::factory()->create();
    $customer = Organization::factory()->create();
    DB::table('organizations')->where('id', $partner->id)->update(['can_manage_customers' => true]);
    DB::table('organizations')->where('id', $customer->id)->update(['managed_by_organization_id' => $partner->id]);

    return [$partner, $customer];
}

describe('organization selection', function () {
    it('attributes to the only organization the actor belongs to, with no id sent', function () {
        $user = ticketUser();
        $organization = ticketJoin($user);

        $this->actingAs($user)->post(route('tickets.store'), attrPayload())->assertRedirect();

        $ticket = Ticket::sole();
        expect($ticket->organization_id)->toBe($organization->id)
            ->and($ticket->submitted_by_id)->toBe($user->id)
            ->and($ticket->user_id)->toBe($user->id);
    });

    it('accepts the only organization named explicitly', function () {
        $user = ticketUser();
        $organization = ticketJoin($user);

        $this->actingAs($user)->post(route('tickets.store'), attrPayload(['organization_id' => $organization->id]))->assertRedirect();

        expect(Ticket::sole()->organization_id)->toBe($organization->id);
    });

    it('requires an explicit choice when the actor belongs to several organizations, and honours each choice', function () {
        $user = ticketUser();
        $first = ticketJoin($user);
        $second = ticketJoin($user);

        $this->actingAs($user)->post(route('tickets.store'), attrPayload())
            ->assertSessionHasErrors(['organization_id' => ServiceRequestAccess::CHOOSE]);
        attrAssertNothingWritten();

        $this->actingAs($user)->post(route('tickets.store'), attrPayload(['title' => 'For the second', 'organization_id' => $second->id]))->assertRedirect();
        $this->actingAs($user)->post(route('tickets.store'), attrPayload(['title' => 'For the first', 'organization_id' => $first->id]))->assertRedirect();

        expect(Ticket::where('title', 'For the second')->sole()->organization_id)->toBe($second->id)
            ->and(Ticket::where('title', 'For the first')->sole()->organization_id)->toBe($first->id);
    });

    it('refuses an actor with no membership, whatever they name, and writes nothing', function () {
        $user = ticketUser();
        $someone = Organization::factory()->create();

        $this->actingAs($user)->post(route('tickets.store'), attrPayload(['attachments' => [ticketUpload('a.pdf')]]))
            ->assertSessionHasErrors(['organization_id' => ServiceRequestAccess::NOT_LINKED]);
        $this->actingAs($user)->post(route('tickets.store'), attrPayload(['organization_id' => $someone->id]))
            ->assertSessionHasErrors(['organization_id' => ServiceRequestAccess::NOT_AVAILABLE]);

        attrAssertNothingWritten();
    });

    it('gives the same answer for an organization that does not exist and one the actor is not a member of (no existence oracle)', function () {
        $user = ticketUser();
        ticketJoin($user);
        $foreign = Organization::factory()->create();
        $missing = Organization::max('id') + 1000;

        $responses = collect([$foreign->id, $missing])->map(
            fn (int $id) => $this->actingAs($user)->post(route('tickets.store'), attrPayload(['organization_id' => $id]))
        );

        $responses->each(fn ($response) => $response->assertStatus(302)->assertSessionHasErrors(['organization_id' => ServiceRequestAccess::NOT_AVAILABLE]));
        expect($responses[0]->headers->get('Location'))->toBe($responses[1]->headers->get('Location'));
        attrAssertNothingWritten();
    });

    it('treats a non-integer organization id as a plain validation error, never a lookup', function () {
        $user = ticketUser();
        ticketJoin($user);

        $this->actingAs($user)->post(route('tickets.store'), attrPayload(['organization_id' => '1 OR 1=1']))->assertSessionHasErrors('organization_id');
        $this->actingAs($user)->post(route('tickets.store'), attrPayload(['organization_id' => 0]))->assertSessionHasErrors('organization_id');
        attrAssertNothingWritten();
    });
});

describe('who is eligible', function () {
    it('lets an operator file only as a member of the organization, exactly like a customer', function () {
        $operator = ticketUser('operator');
        $organization = ticketJoin($operator);

        $this->actingAs($operator)->post(route('tickets.store'), attrPayload())->assertRedirect();
        expect(Ticket::sole()->organization_id)->toBe($organization->id);
    });

    it('refuses an operator who is not a member of any organization, and of the one they name', function () {
        $operator = ticketUser('operator');
        $foreign = Organization::factory()->create();

        $this->actingAs($operator)->post(route('tickets.store'), attrPayload())->assertSessionHasErrors(['organization_id' => ServiceRequestAccess::NOT_LINKED]);
        $this->actingAs($operator)->post(route('tickets.store'), attrPayload(['organization_id' => $foreign->id]))
            ->assertSessionHasErrors(['organization_id' => ServiceRequestAccess::NOT_AVAILABLE]);
        attrAssertNothingWritten();
    });

    it('refuses a capability-only operator (no operator role) the same way', function () {
        $agent = ticketAgent();
        $agent->givePermissionTo('tickets.create');

        $this->actingAs($agent)->post(route('tickets.store'), attrPayload())->assertSessionHasErrors(['organization_id' => ServiceRequestAccess::NOT_LINKED]);
        attrAssertNothingWritten();
    });

    it('does not let a managed-partner relationship stand in for membership', function () {
        [$partner, $customer] = attrManagedPair();
        $partnerUser = ticketUser();
        ticketJoin($partnerUser, $partner, 'admin');

        // The partner's own organization is the only one they can file for; the managed customer is not theirs to name.
        $this->actingAs($partnerUser)->post(route('tickets.store'), attrPayload(['organization_id' => $customer->id]))
            ->assertSessionHasErrors(['organization_id' => ServiceRequestAccess::NOT_AVAILABLE]);
        attrAssertNothingWritten();

        $this->actingAs($partnerUser)->post(route('tickets.store'), attrPayload())->assertRedirect();
        expect(Ticket::sole()->organization_id)->toBe($partner->id);
    });

    it('treats an administrator and a plain member alike: role and approval add and take away nothing', function (string $role, bool $approved) {
        $user = ticketUser();
        $organization = ticketJoin($user, null, $role);

        if ($approved) {
            DB::table('organization_members')->where('user_id', $user->id)->update(['admin_approved_at' => now(), 'admin_approved_by' => ticketUser('operator')->id]);
        }

        $this->actingAs($user)->post(route('tickets.store'), attrPayload())->assertRedirect();
        expect(Ticket::sole()->organization_id)->toBe($organization->id);
    })->with([['member', false], ['admin', false], ['admin', true]]);

    it('refuses a suspended account and an account without tickets.create at the service boundary, before any write', function () {
        $organization = Organization::factory()->create();
        $suspended = ticketUser('user', ['disabled_at' => now()]);
        $guest = User::factory()->create();   // no role, so no tickets.create
        ticketJoin($suspended, $organization);
        ticketJoin($guest, $organization);

        foreach ([$suspended, $guest] as $actor) {
            expect(fn () => app(TicketService::class)->create($actor, attrPayload(), [ticketUpload('a.pdf')]))->toThrow(AuthorizationException::class);
        }

        attrAssertNothingWritten();
    });

    it('reads suspension from the table, not from the model it was handed', function () {
        $user = ticketUser();
        ticketJoin($user);
        $stale = User::find($user->id);
        DB::table('users')->where('id', $user->id)->update(['disabled_at' => now()]);

        expect(fn () => app(TicketService::class)->create($stale, attrPayload()))->toThrow(AuthorizationException::class);
        attrAssertNothingWritten();
    });

    it('reads membership from the table, not from a relation loaded earlier', function () {
        $user = ticketUser();
        $organization = ticketJoin($user);
        $user->load('organizations');
        DB::table('organization_members')->where('user_id', $user->id)->delete();

        expect(fn () => app(TicketService::class)->create($user, attrPayload(), [], $organization->id))->toThrow(ValidationException::class);
        attrAssertNothingWritten();
    });
});

describe('provenance', function () {
    it('takes the requester and submitter from the authenticated user, never from the request', function () {
        $user = ticketUser();
        $organization = ticketJoin($user);
        $other = ticketUser();
        $otherOrganization = ticketJoin($other);

        $this->actingAs($user)->post(route('tickets.store'), attrPayload([
            'user_id' => $other->id,
            'submitted_by_id' => $other->id,
            'organization' => $otherOrganization->id,
            'company_id' => 999,
        ]))->assertRedirect();

        $ticket = Ticket::sole();
        expect($ticket->user_id)->toBe($user->id)
            ->and($ticket->submitted_by_id)->toBe($user->id)
            ->and($ticket->organization_id)->toBe($organization->id);
    });

    it('cannot be handed a submitter, requester or organization through the service data array', function () {
        $user = ticketUser();
        $organization = ticketJoin($user);
        $other = ticketUser();
        ticketJoin($other);

        $ticket = app(TicketService::class)->create($user, attrPayload(['user_id' => $other->id, 'submitted_by_id' => $other->id, 'organization_id' => 999999]));

        expect($ticket->user_id)->toBe($user->id)
            ->and($ticket->submitted_by_id)->toBe($user->id)
            ->and($ticket->organization_id)->toBe($organization->id);
    });

    it('leaves attribution alone when membership, role, approval or the management link later change', function () {
        $user = ticketUser();
        $organization = ticketJoin($user);
        $ticket = app(TicketService::class)->create($user, attrPayload());

        DB::table('organization_members')->where('user_id', $user->id)->update(['role' => 'admin']);
        DB::table('organizations')->where('id', $organization->id)->update(['can_manage_customers' => true]);
        DB::table('organization_members')->where('user_id', $user->id)->delete();

        $fresh = $ticket->fresh();
        expect($fresh->organization_id)->toBe($organization->id)->and($fresh->submitted_by_id)->toBe($user->id);
    });
});

describe('no new visibility', function () {
    it('gives a same-organization peer, an administrator and a partner member no access to a ticket', function () {
        [$partner, $customerOrganization] = attrManagedPair();
        $requester = ticketUser();
        ticketJoin($requester, $customerOrganization);
        $peer = ticketUser();
        ticketJoin($peer, $customerOrganization);
        $admin = ticketUser();
        ticketJoin($admin, $customerOrganization, 'admin');
        DB::table('organization_members')->where('user_id', $admin->id)->update(['admin_approved_at' => now(), 'admin_approved_by' => ticketUser('operator')->id]);
        $partnerAdmin = ticketUser();
        ticketJoin($partnerAdmin, $partner, 'admin');

        $ticket = app(TicketService::class)->create($requester, attrPayload(['title' => 'Private matter']));

        foreach ([$peer, $admin, $partnerAdmin] as $viewer) {
            expect(Gate::forUser($viewer)->allows('view', $ticket))->toBeFalse()
                ->and(Gate::forUser($viewer)->allows('reply', $ticket))->toBeFalse()
                ->and(Gate::forUser($viewer)->allows('viewAsRequesterOrOperator', $ticket))->toBeFalse();
            $this->actingAs($viewer)->get(route('tickets.show', $ticket))->assertForbidden();
            expect(ticketListedTitles($viewer))->toBe([]);
        }

        expect(ticketListedTitles($requester))->toBe(['Private matter']);
    });

    it('keeps requester access for a requester who has since left the organization (E5, recorded risk)', function () {
        $requester = ticketUser();
        ticketJoin($requester);
        $ticket = app(TicketService::class)->create($requester, attrPayload());
        DB::table('organization_members')->where('user_id', $requester->id)->delete();

        expect(Gate::forUser($requester)->allows('view', $ticket))->toBeTrue();
        $this->actingAs($requester)->get(route('tickets.show', $ticket))->assertOk();
    });

    it('leaves operator visibility exactly as it was: every ticket, whatever its organization', function () {
        $operator = ticketUser('operator');
        $ticket = Ticket::factory()->create();

        expect(Gate::forUser($operator)->allows('view', $ticket))->toBeTrue();
    });

    it('registers no global scope on Ticket', function () {
        expect((new Ticket)->getGlobalScopes())->toBe([]);
    });
});
