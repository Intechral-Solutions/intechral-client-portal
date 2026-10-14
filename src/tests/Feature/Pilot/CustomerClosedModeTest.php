<?php

/*
 * R0.9-CS1: closed mode, the default. A customer keeps authentication, account management and sign-out and sees a
 * dashboard that says the portal is not open yet. No business module, API response or serialized prop is reachable.
 */

use App\Models\CmsPage;
use App\Models\Invitation;
use App\Models\Ticket;
use App\Shared\Pilot\CustomerPilotGate;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/PilotTestHelpers.php';
require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function closedFixture(): array
{
    $customer = pilotCustomer(['password' => bcrypt('Password1!')]);
    $fx = pilotProjectFor($customer, 'Closed Visible Project');
    $invoice = pilotInvoiceFor($customer);
    $ticket = Ticket::factory()->open()->for($customer, 'user')->create(['title' => 'CLOSED-SECRET ticket']);
    $page = CmsPage::factory()->create(['title' => 'CLOSED-SECRET page', 'slug' => 'closed-secret-page', 'status' => 'published', 'published_at' => now()]);

    return [$customer, $fx, $invoice, $ticket, $page];
}

it('shows a closed customer a dashboard with no business data in its response', function () {
    [$customer, $fx, $invoice, $ticket] = closedFixture();
    pilotMode('closed');

    $response = $this->actingAs($customer)->get(route('dashboard'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('dashboard/customer-unavailable')
        ->where('shell.presentation', 'focused')
        ->where('auth.permissions', [])
        ->where('navigation.workspaces', fn ($workspaces) => collect($workspaces)->pluck('key')->all() === ['home']));

    $props = json_decode(json_encode($response->viewData('page')), true)['props'];
    expect(array_keys($props))->toEqualCanonicalizing(['errors', 'app', 'auth', 'shell', 'navigation', 'flash']);

    foreach (['Closed Visible Project', 'CLOSED-SECRET', $invoice->invoice_number, $ticket->ticket_number, 'PILOT-SECRET', 'Go-live', '/my/invoices', '/tickets', '/projects'] as $needle) {
        expect($response->getContent())->not->toContain($needle, "[{$needle}] reached a closed customer");
    }
});

it('never gives a customer with operational capabilities but no eligibility the operator dashboard', function () {
    [, $fx] = closedFixture();
    $staff = pilotCustomRoleUser('closed-staff', ['settings.view', 'tasks.view_all', 'time.view_all', 'billing.view', 'tickets.view', 'projects.view', 'crm.manage']);
    $fx['project']->members()->attach($staff->id, ['role' => 'member']);

    foreach (['closed' => 'dashboard/customer-unavailable', 'pilot' => 'dashboard/customer-home'] as $mode => $component) {
        pilotMode($mode);

        $response = $this->actingAs($staff)->get(route('dashboard'));
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
        expect($response->getContent())->not->toContain('crmSummary')->not->toContain('recentTickets')->not->toContain('metrics');
    }
});

it('refuses a closed customer every business route, verb and JSON request, disclosing and changing nothing', function () {
    [$customer, $fx, $invoice, $ticket, $page] = closedFixture();
    pilotMode('closed');

    $p = $fx['project']->id;
    $requests = [
        ['GET', '/tickets'], ['GET', '/tickets/create'], ['GET', "/tickets/{$ticket->id}"],
        ['POST', '/tickets', ['title' => 'x', 'description' => 'x', 'category' => 'General', 'priority' => 'low']],
        ['POST', "/tickets/{$ticket->id}/replies", ['body' => 'closed reply']],
        ['GET', '/projects'], ['GET', "/projects/{$p}"], ['GET', "/projects/{$p}/board"], ['GET', "/projects/{$p}/milestones"],
        ['GET', '/my/invoices'], ['GET', "/my/invoices/{$invoice->id}"], ['GET', "/billing/invoices/{$invoice->id}/pay"],
        ['GET', '/time'], ['GET', '/tasks'], ['GET', '/pages'], ['GET', "/pages/{$page->slug}"],
        ['GET', '/operator/tickets'], ['GET', '/crm/companies'], ['GET', '/admin/users'],
    ];

    $tickets = Ticket::count();
    $replies = $ticket->replies()->count();

    foreach ($requests as $request) {
        foreach ([false, true] as $json) {
            $response = $json
                ? $this->actingAs($customer)->json($request[0], $request[1], $request[2] ?? [])
                : $this->actingAs($customer)->call($request[0], $request[1], $request[2] ?? []);

            expect($response->getStatusCode())->toBe(404, "{$request[0]} {$request[1]}".($json ? ' (JSON)' : ''));

            foreach (['CLOSED-SECRET', 'PILOT-SECRET', 'Closed Visible Project', $invoice->invoice_number] as $needle) {
                expect($response->getContent())->not->toContain($needle);
            }
        }
    }

    expect(Ticket::count())->toBe($tickets)->and($ticket->replies()->count())->toBe($replies);
});

it('shows the same customer the Helpdesk and projects in pilot mode, and everything in legacy mode (the two proofs)', function () {
    [$customer, $fx, , $ticket] = closedFixture();

    pilotMode('pilot');
    $this->actingAs($customer)->get('/tickets')->assertOk();
    $this->actingAs($customer)->get("/projects/{$fx['project']->id}")->assertOk();

    pilotMode('legacy');
    $this->actingAs($customer)->get('/my/invoices')->assertOk();

    pilotMode('closed');
    $this->actingAs($customer)->get('/tickets')->assertNotFound();
});

it('keeps authentication, account management and sign-out working for a closed customer', function () {
    [$customer] = closedFixture();
    pilotMode('closed');

    $this->post('/login', ['email' => $customer->email, 'password' => 'Password1!'])->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertOk();
    $this->get(route('profile.show'))->assertOk();
    $this->get(route('password.confirm'))->assertOk();
    $this->get('/')->assertRedirect(route('dashboard'));
    $this->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

it('still accepts a password invitation and offers no SSO, in closed mode', function () {
    Notification::fake();
    $invitation = Invitation::factory()->pending()->create(['email' => 'closed-new@example.com']);
    pilotMode('closed');

    $this->get(route('invitation.show', $invitation->token))->assertInertia(fn (Assert $page) => $page->where('providersAvailable', false));

    $this->post(route('invitation.register', $invitation->token), [
        'name' => 'Closed New', 'password' => 'Str0ng!Passw0rd-xyz', 'password_confirmation' => 'Str0ng!Passw0rd-xyz',
    ])->assertRedirect(route('dashboard'));
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('dashboard/customer-unavailable'));
});

it('leaves operators entirely unaffected in closed mode', function () {
    [, $fx, $invoice, , $page] = closedFixture();
    $operator = pilotOperator();
    pilotMode('closed');

    foreach (['/dashboard', '/billing/invoices', "/billing/invoices/{$invoice->id}", '/time', '/tasks', "/projects/{$fx['project']->id}", '/projects', '/operator/tickets', '/crm/companies', '/admin/users', '/pages'] as $uri) {
        $this->actingAs($operator)->get($uri)->assertOk();
    }

    $this->actingAs($operator)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->component('dashboard/index'));
});

it('treats a missing mode like closed end to end', function () {
    [$customer] = closedFixture();
    config(['portal' => []]);

    $this->actingAs($customer)->get('/tickets')->assertNotFound();
    $this->actingAs($customer)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('dashboard/customer-unavailable'));
});

it('refuses legacy in production, end to end, and keeps pilot', function () {
    [$customer] = closedFixture();
    app()->detectEnvironment(fn () => 'production');
    CustomerPilotGate::forgetWarnings();

    config(['portal.customer_surface_mode' => 'legacy']);
    $this->actingAs($customer)->get('/my/invoices')->assertNotFound();
    $this->actingAs($customer)->get('/tickets')->assertNotFound();

    config(['portal.customer_surface_mode' => 'pilot']);
    $this->actingAs($customer)->get('/tickets')->assertOk();
    $this->actingAs($customer)->get('/my/invoices')->assertNotFound();
});
