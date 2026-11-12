<?php

use App\Models\CrmCompany;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\OrganizationRelationships;
use App\Services\PartnerSupportGrants;
use Illuminate\Support\Facades\DB;

/*
 * Shared fixtures for the Release 1.0 R1-4 partner support suites. Loaded with require_once; the file name does not end in "Test" so Pest does not
 * run it. Every function carries the `ps` prefix (Pest loads every test file into one process). Mode is stated by each test: the suite default is
 * legacy (phpunit.xml), so the boundary tests call `pilotMode('pilot')` (PilotTestHelpers).
 */

require_once __DIR__.'/../Pilot/PilotTestHelpers.php';
require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

/** An organization with exactly one CRM company (the pair a managed relationship needs). */
function psPaired(string $name): Organization
{
    $owner = User::factory()->create();
    $organization = Organization::factory()->create(['name' => $name, 'owner_id' => $owner->id]);
    CrmCompany::factory()->create(['name' => $name, 'organization_id' => $organization->id, 'created_by' => $owner->id]);

    return $organization;
}

/** Make the user a plain member of the organization (written as an operator act would). */
function psJoin(User $user, Organization $organization, string $role = 'member'): void
{
    DB::table('organization_members')->insertOrIgnore([
        'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * The whole world of one scenario, every relationship created through the real services:
 *
 *   partner  `UNISA`, designated; customer `Charter`, managed by it
 *   staff    a customer-role member of the partner, DESIGNATED for support by the operator
 *   requester a customer-role member of the customer, owner of `ticket`
 *   ticket   an open request of the customer organization
 *
 * @return array{operator: User, partner: Organization, customer: Organization, staff: User, requester: User, ticket: Ticket}
 */
function psWorld(array $ticket = []): array
{
    $operator = User::factory()->create(['name' => 'Olive Operator'])->assignRole('operator');
    $relationships = app(OrganizationRelationships::class);

    $partner = psPaired('UNISA');
    $relationships->designatePartner($partner, $operator);
    $customer = psPaired('Charter');
    $relationships->assignCustomer($customer, $partner, $operator);

    $staff = pilotCustomer(['name' => 'Pat Partner']);
    psJoin($staff, $partner);
    app(PartnerSupportGrants::class)->grant($partner, $staff, $operator);

    $requester = pilotCustomer(['name' => 'Rae Requester']);
    psJoin($requester, $customer);

    $ticket = Ticket::factory()->open()->for($requester, 'user')->create($ticket + [
        'organization_id' => $customer->id, 'title' => 'Printer offline', 'description' => 'It prints nothing.',
    ]);

    return ['operator' => $operator, 'partner' => $partner->refresh(), 'customer' => $customer->refresh(), 'staff' => $staff, 'requester' => $requester, 'ticket' => $ticket];
}

function psReply(Ticket $ticket, User $author, bool $internal, string $body): TicketReply
{
    return $ticket->replies()->create(['user_id' => $author->id, 'body' => $body, 'is_internal' => $internal]);
}

/** Every ticket-related row count, to prove a refused action wrote nothing. */
function psWrites(): array
{
    return [
        'replies' => DB::table('ticket_replies')->count(),
        'attachments' => DB::table('ticket_attachments')->count(),
        'history' => DB::table('ticket_status_histories')->count(),
        'dueChanges' => DB::table('ticket_due_date_changes')->count(),
    ];
}
