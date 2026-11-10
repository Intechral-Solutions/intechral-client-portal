<?php

use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ServiceRequestAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Pilot/PilotTestHelpers.php';
require_once __DIR__.'/TicketTestHelpers.php';

/*
 * Release 1.0 (R1-2B): the creation matrix of the R1-2 design record (section G), run through the REAL route in BOTH customer-surface modes
 * the customer can create in: legacy (the suite baseline) and pilot (Release 0.9 customer pilot). Every row is an actor and an organization
 * case with the outcome the server must give; a refusal also proves nothing was written, stored or mailed. The page the same actor sees is
 * checked too, so what the form offers and what the server accepts are shown to agree for every honest case.
 *
 * Closed mode is not in the matrix: a customer cannot reach `/tickets` at all there (CustomerPilotGateTest owns that).
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    Storage::fake('local');
});

/** @return array<string, Closure(): array{0: ?User, 1: array<string, mixed>, 2: array{0: string, 1: mixed}}> */
function matrixScenarios(): array
{
    $form = ['title' => 'Matrix request', 'description' => 'x', 'category' => 'General', 'priority' => 'low'];
    $created = fn (Organization $organization) => ['created', $organization->id];
    $refused = fn (string $message) => ['refused', $message];

    return [
        'one membership, no id sent' => function () use ($form, $created) {
            $user = pilotCustomer();
            $org = ticketJoin($user);

            return [$user, $form, $created($org)];
        },
        'one membership, its own id named' => function () use ($form, $created) {
            $user = pilotCustomer();
            $org = ticketJoin($user);

            return [$user, [...$form, 'organization_id' => $org->id], $created($org)];
        },
        'several memberships, none named' => function () use ($form, $refused) {
            $user = pilotCustomer();
            ticketJoin($user);
            ticketJoin($user);

            return [$user, $form, $refused(ServiceRequestAccess::CHOOSE)];
        },
        'several memberships, the first named' => function () use ($form, $created) {
            $user = pilotCustomer();
            $first = ticketJoin($user);
            ticketJoin($user);

            return [$user, [...$form, 'organization_id' => $first->id], $created($first)];
        },
        'several memberships, the second named' => function () use ($form, $created) {
            $user = pilotCustomer();
            ticketJoin($user);
            $second = ticketJoin($user);

            return [$user, [...$form, 'organization_id' => $second->id], $created($second)];
        },
        'several memberships, a foreign organization named' => function () use ($form, $refused) {
            $user = pilotCustomer();
            ticketJoin($user);
            ticketJoin($user);

            return [$user, [...$form, 'organization_id' => Organization::factory()->create()->id], $refused(ServiceRequestAccess::NOT_AVAILABLE)];
        },
        'no membership, none named' => function () use ($form, $refused) {
            return [pilotCustomer(), $form, $refused(ServiceRequestAccess::NOT_LINKED)];
        },
        'no membership, an organization named' => function () use ($form, $refused) {
            return [pilotCustomer(), [...$form, 'organization_id' => Organization::factory()->create()->id], $refused(ServiceRequestAccess::NOT_AVAILABLE)];
        },
        'an organization that does not exist' => function () use ($form, $refused) {
            $user = pilotCustomer();
            ticketJoin($user);

            return [$user, [...$form, 'organization_id' => Organization::max('id') + 1000], $refused(ServiceRequestAccess::NOT_AVAILABLE)];
        },
        'an operator who is a member' => function () use ($form, $created) {
            $operator = pilotOperator();
            $org = ticketJoin($operator);

            return [$operator, $form, $created($org)];
        },
        'an operator who is not a member of anything' => function () use ($form, $refused) {
            return [pilotOperator(), $form, $refused(ServiceRequestAccess::NOT_LINKED)];
        },
        'a membership of an organization with no CRM company' => function () use ($form, $created) {
            $user = pilotCustomer();
            $org = ticketJoin($user, Organization::factory()->create());

            return [$user, $form, $created($org)];
        },
        'a member of a managed customer organization' => function () use ($form, $created) {
            $partner = Organization::factory()->create();
            $customer = Organization::factory()->create();
            DB::table('organizations')->where('id', $partner->id)->update(['can_manage_customers' => true]);
            DB::table('organizations')->where('id', $customer->id)->update(['managed_by_organization_id' => $partner->id]);
            $user = pilotCustomer();
            ticketJoin($user, $customer);

            return [$user, $form, $created($customer)];
        },
        'a partner member naming a managed customer organization' => function () use ($form, $refused) {
            $partner = Organization::factory()->create();
            $customer = Organization::factory()->create();
            DB::table('organizations')->where('id', $partner->id)->update(['can_manage_customers' => true]);
            DB::table('organizations')->where('id', $customer->id)->update(['managed_by_organization_id' => $partner->id]);
            $user = pilotCustomer();
            ticketJoin($user, $partner, 'admin');

            return [$user, [...$form, 'organization_id' => $customer->id], $refused(ServiceRequestAccess::NOT_AVAILABLE)];
        },
        'an unapproved administrator' => function () use ($form, $created) {
            $user = pilotCustomer();
            $org = ticketJoin($user, null, 'admin');

            return [$user, $form, $created($org)];
        },
        'an approved administrator' => function () use ($form, $created) {
            $user = pilotCustomer();
            $org = ticketJoin($user, null, 'admin');
            DB::table('organization_members')->where('user_id', $user->id)->update(['admin_approved_at' => now(), 'admin_approved_by' => pilotOperator()->id]);

            return [$user, $form, $created($org)];
        },
        'a suspended account' => function () use ($form) {
            $user = pilotCustomer(['disabled_at' => now()]);
            ticketJoin($user);

            return [$user, $form, ['blocked', null]];
        },
        'a guest' => function () use ($form) {
            return [null, $form, ['guest', null]];
        },
    ];
}

it('gives every actor and organization case the outcome the server owes it, in legacy and in pilot mode', function (string $mode, string $name) {
    pilotMode($mode);
    [$actor, $payload, [$kind, $expected]] = matrixScenarios()[$name]();

    $request = $actor === null ? $this : $this->actingAs($actor);
    $response = $request->post(route('tickets.store'), $payload);

    match ($kind) {
        'created' => (function () use ($response, $actor, $expected) {
            $response->assertRedirect();
            $ticket = Ticket::sole();
            expect($ticket->organization_id)->toBe($expected)
                ->and($ticket->submitted_by_id)->toBe($actor->id)
                ->and($ticket->user_id)->toBe($actor->id);
        })(),
        'refused' => (function () use ($response, $expected) {
            $response->assertSessionHasErrors(['organization_id' => $expected]);
            expect(Ticket::count())->toBe(0);
            Notification::assertNothingSent();
        })(),
        'blocked' => (function () use ($response) {
            // The account never reaches the controller; whatever the gate answers, nothing was created.
            expect($response->status())->not->toBe(500)->and(Ticket::count())->toBe(0);
            Notification::assertNothingSent();
        })(),
        'guest' => (function () use ($response) {
            $response->assertRedirect('/login');
            expect(Ticket::count())->toBe(0);
        })(),
    };
})->with(['legacy', 'pilot'])->with(matrixScenarioNames());

it('shows the actor on the create page exactly the organizations the server would accept, in both modes', function (string $mode) {
    pilotMode($mode);
    $many = pilotCustomer();
    $a = ticketJoin($many, Organization::factory()->create(['name' => 'Alpha']));
    $b = ticketJoin($many, Organization::factory()->create(['name' => 'Beta']));
    $none = pilotCustomer();
    Organization::factory()->create(['name' => 'Foreign-SENTINEL']);

    $offered = ticketOwnProps($this->actingAs($many)->get(route('tickets.create'))->assertOk())['organizations'];
    expect($offered)->toBe([['id' => $a->id, 'name' => 'Alpha'], ['id' => $b->id, 'name' => 'Beta']]);

    // Each offered option is accepted by the server; the one the page does not offer is not.
    foreach ($offered as $option) {
        $this->actingAs($many)->post(route('tickets.store'), ['title' => 'T '.$option['name'], 'description' => 'x', 'category' => 'General', 'priority' => 'low', 'organization_id' => $option['id']])->assertRedirect();
        expect(Ticket::where('title', 'T '.$option['name'])->sole()->organization_id)->toBe($option['id']);
    }

    // ...and the organization the page did NOT offer is refused, with the answer an unknown one gets.
    $foreign = Organization::factory()->create();
    $this->actingAs($many)->post(route('tickets.store'), ['title' => 'T foreign', 'description' => 'x', 'category' => 'General', 'priority' => 'low', 'organization_id' => $foreign->id])
        ->assertSessionHasErrors(['organization_id' => ServiceRequestAccess::NOT_AVAILABLE]);
    expect(Ticket::where('title', 'T foreign')->exists())->toBeFalse();

    $response = $this->actingAs($none)->get(route('tickets.create'))->assertOk();
    expect(ticketOwnProps($response)['organizations'])->toBe([])->and($response->getContent())->not->toContain('Foreign-SENTINEL');
})->with(['legacy', 'pilot']);

/** The scenario names, without building any fixture: the dataset is declared before the application boots. */
function matrixScenarioNames(): array
{
    return [
        'one membership, no id sent', 'one membership, its own id named', 'several memberships, none named', 'several memberships, the first named',
        'several memberships, the second named', 'several memberships, a foreign organization named', 'no membership, none named',
        'no membership, an organization named', 'an organization that does not exist', 'an operator who is a member',
        'an operator who is not a member of anything', 'a membership of an organization with no CRM company', 'a member of a managed customer organization',
        'a partner member naming a managed customer organization', 'an unapproved administrator', 'an approved administrator', 'a suspended account', 'a guest',
    ];
}
