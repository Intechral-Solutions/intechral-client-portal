<?php

use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ServiceRequestAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/TicketTestHelpers.php';

/*
 * Release 1.0 (R1-2B): where the organization a ticket belongs to is shown, and to whom. Attribution is provenance and widens no read, so
 * every case here also proves who still does NOT see what. The creation rules themselves are TicketAttributionTest and
 * TicketCreationMatrixTest.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Storage::fake('local');
});

function orgPage(TestResponse $response): array
{
    return ticketOwnProps($response);
}

describe('the create page', function () {
    it('offers the actor\'s own current memberships, by id and name, and no other organization', function () {
        $user = ticketUser();
        $mine = ticketJoin($user, Organization::factory()->create(['name' => 'Beta Mine']));
        $alsoMine = ticketJoin($user, Organization::factory()->create(['name' => 'Alpha Mine']));
        $other = Organization::factory()->create(['name' => 'Foreign-Tenant-SENTINEL']);
        // A managed customer of the user's own (partner) organization: management grants nothing, so it is not offered either.
        $managed = Organization::factory()->create(['name' => 'Managed-Customer-SENTINEL']);
        DB::table('organizations')->where('id', $mine->id)->update(['can_manage_customers' => true]);
        DB::table('organizations')->where('id', $managed->id)->update(['managed_by_organization_id' => $mine->id]);

        $response = $this->actingAs($user)->get(route('tickets.create'))->assertOk();

        expect(orgPage($response)['organizations'])->toBe([['id' => $alsoMine->id, 'name' => 'Alpha Mine'], ['id' => $mine->id, 'name' => 'Beta Mine']])
            ->and($response->getContent())->not->toContain('Foreign-Tenant-SENTINEL')->not->toContain('Managed-Customer-SENTINEL');
        expect($other->id)->not->toBe($mine->id);
    });

    it('offers one organization for one membership, and none for none (the page then explains instead of offering a form)', function () {
        $one = ticketUser();
        $organization = ticketJoin($one, Organization::factory()->create(['name' => 'Only One']));
        $none = ticketUser();

        expect(orgPage($this->actingAs($one)->get(route('tickets.create'))->assertOk())['organizations'])->toBe([['id' => $organization->id, 'name' => 'Only One']]);
        $this->actingAs($none)->get(route('tickets.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('tickets/create')->where('organizations', []));
    });

    it('reads membership now: a removed membership is no longer offered', function () {
        $user = ticketUser();
        $organization = ticketJoin($user);
        DB::table('organization_members')->where('user_id', $user->id)->delete();

        expect(orgPage($this->actingAs($user)->get(route('tickets.create'))->assertOk())['organizations'])->toBe([]);
        expect($organization)->not->toBeNull();
    });

    it('offers an operator only their own memberships, exactly as it does a customer', function () {
        $operator = ticketUser('operator');
        $none = $this->actingAs($operator)->get(route('tickets.create'))->assertOk();
        expect(orgPage($none)['organizations'])->toBe([]);

        ticketJoin($operator, Organization::factory()->create(['name' => 'Operator Org']));
        expect(array_column(orgPage($this->actingAs($operator)->get(route('tickets.create'))->assertOk())['organizations'], 'name'))->toBe(['Operator Org']);
    });

    it('has the page the same way a refusal does: the server decides, the page only reflects', function () {
        // What the page offered is not what the server accepts: a membership removed after the page loaded is refused with the same
        // answer as an unknown organization, and nothing is written.
        $user = ticketUser();
        $organization = ticketJoin($user);
        $offered = orgPage($this->actingAs($user)->get(route('tickets.create'))->assertOk())['organizations'];
        DB::table('organization_members')->where('user_id', $user->id)->delete();

        $this->actingAs($user)->post(route('tickets.store'), ['title' => 't', 'description' => 'd', 'category' => 'General', 'priority' => 'low', 'organization_id' => $offered[0]['id']])
            ->assertSessionHasErrors(['organization_id' => ServiceRequestAccess::NOT_AVAILABLE]);
        expect(Ticket::count())->toBe(0)->and($organization->id)->toBe($offered[0]['id']);
    });

    it('is not carried by any other page', function () {
        $user = ticketUser();
        $organization = ticketJoin($user);
        $ticket = ticketFor($user, ['organization_id' => $organization->id]);

        foreach ([route('tickets.index'), route('tickets.show', $ticket), route('dashboard')] as $url) {
            $props = $this->actingAs($user)->get($url);
            $payload = $props->headers->get('Content-Type') !== null ? $props->getContent() : '';

            expect($payload)->not->toContain('&quot;organizations&quot;')->not->toContain('"organizations"');
        }
    });
});

describe('the operator surfaces', function () {
    beforeEach(function () {
        $this->operator = ticketUser('operator');
    });

    it('shows the organization on the queue and on the ticket, and the submitter only when it is not the requester', function () {
        $acme = Organization::factory()->create(['name' => 'Acme Ltd']);
        $requester = ticketUser('user', ['name' => 'Rita Requester']);
        $own = ticketFor($requester, ['organization_id' => $acme->id, 'title' => 'Own submission']);
        $filedFor = ticketFor($requester, ['organization_id' => $acme->id, 'title' => 'Filed for them', 'submitted_by_id' => ticketUser('user', ['name' => 'Sam Submitter'])->id]);

        // In the ROW, not merely in the filter's option list (which comes first in the page): the organization appears AFTER this ticket's title.
        $this->actingAs($this->operator)->get(route('operator.tickets.index'))->assertOk()->assertSeeInOrder(['Own submission', $own->ticket_number, 'Acme Ltd']);

        $this->actingAs($this->operator)->get(route('operator.tickets.show', $own))->assertOk()
            ->assertSee('Organization:')->assertSee('Acme Ltd')->assertDontSee('Submitted by:');
        $this->actingAs($this->operator)->get(route('operator.tickets.show', $filedFor))->assertOk()
            ->assertSee('Submitted by:')->assertSee('Sam Submitter');
    });

    it('filters the queue by organization, and ignores a value that is not a whole positive number', function () {
        $acme = Organization::factory()->create(['name' => 'Acme Ltd']);
        $globex = Organization::factory()->create(['name' => 'Globex Corp']);
        $unused = Organization::factory()->create(['name' => 'No-Tickets-Org']);
        ticketFor(ticketUser(), ['organization_id' => $acme->id, 'title' => 'In acme']);
        ticketFor(ticketUser(), ['organization_id' => $globex->id, 'title' => 'In globex']);

        $filtered = $this->actingAs($this->operator)->get(route('operator.tickets.index', ['organization' => $acme->id]))->assertOk();
        $filtered->assertSee('In acme')->assertDontSee('In globex');

        foreach (['0', '-1', 'abc', '1 OR 1=1', '1.5', ''] as $junk) {
            $this->actingAs($this->operator)->get(route('operator.tickets.index', ['organization' => $junk]))->assertOk()
                ->assertSee('In acme')->assertSee('In globex');
        }
        // A well-formed id that names no organization is a filter that matches nothing, not an error and not an oracle.
        $this->actingAs($this->operator)->get(route('operator.tickets.index', ['organization' => '999999999999']))->assertOk()->assertDontSee('In acme')->assertDontSee('In globex');
        $this->actingAs($this->operator)->get(route('operator.tickets.index').'?organization[]=1')->assertOk()->assertSee('In acme')->assertSee('In globex');

        // The filter offers the organizations that HAVE tickets, and no other.
        $this->actingAs($this->operator)->get(route('operator.tickets.index'))->assertOk()
            ->assertSee('Acme Ltd')->assertSee('Globex Corp')->assertDontSee('No-Tickets-Org');
        expect($unused->id)->not->toBe($acme->id);
    });

    it('appends Organization and Submitted by to the CSV, neutralizing formulas in the name, and keeps every earlier column where it was', function () {
        $organization = Organization::factory()->create(['name' => '=HYPERLINK("https://evil.invalid","x")']);
        $requester = ticketUser('user', ['name' => 'Rita Requester']);
        $submitter = ticketUser('user', ['name' => '+SUM(1+1)']);
        $ticket = ticketFor($requester, ['organization_id' => $organization->id, 'submitted_by_id' => $submitter->id]);

        $response = $this->actingAs($this->operator)->get(route('operator.tickets.export'));
        $rows = ticketParseCsv($response->streamedContent());
        $header = array_shift($rows);
        $row = collect($rows)->firstWhere(0, $ticket->ticket_number);

        expect($header)->toBe(['Request #', 'Title', 'Category', 'Priority', 'Status', 'Requester', 'Assignee', 'Created', 'Resolved', 'SLA Due', 'Effective Due', 'Organization', 'Submitted by'])
            ->and($row[5])->toBe('Rita Requester')
            ->and($row[11])->toBe("'=HYPERLINK(\"https://evil.invalid\",\"x\")")
            ->and($row[12])->toBe("'+SUM(1+1)");
    });

    it('escapes an organization name wherever the operator pages print it', function () {
        $hostile = Organization::factory()->create(['name' => '<script>alert(1)</script> & "Co"']);
        $ticket = ticketFor(ticketUser(), ['organization_id' => $hostile->id]);

        foreach ([route('operator.tickets.index'), route('operator.tickets.show', $ticket)] as $url) {
            $this->actingAs($this->operator)->get($url)->assertOk()
                ->assertDontSee('<script>alert(1)</script>', false)
                ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        }
    });

    it('gives a customer none of it: the operator routes stay closed to anyone who does not work tickets', function () {
        $customer = ticketUser();
        $organization = ticketJoin($customer);
        $ticket = ticketFor($customer, ['organization_id' => $organization->id]);

        foreach ([route('operator.tickets.index'), route('operator.tickets.show', $ticket), route('operator.tickets.export')] as $url) {
            $this->actingAs($customer)->get($url)->assertForbidden();
        }
    });
});
