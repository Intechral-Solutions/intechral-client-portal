<?php

use App\Models\CmsPage;
use App\Models\CrmCompany;
use App\Models\CrmContact;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../../Support/UiHtmlHelpers.php';

/*
 * EPIC-016 WP1 §10.1 / §18.3 "Target forms": every target Blade page, rendered through the real routes,
 * satisfies the accessibility contract in tests/Support/UiHtmlHelpers.php (uiAccessibilityViolations):
 *
 *   - every user-facing labelable control has an accessible name;
 *   - no DOM id is rendered twice;
 *   - no label[for], aria-labelledby or aria-describedby points at nothing;
 *   - every field error is associated with its field, and that field is aria-invalid.
 *
 * The detector itself is proven non-vacuous in tests/Unit/Ui/AccessibilityContractDetectorTest.php. Each
 * page is checked clean AND, for the forms that validate, again after a failed submission so the error
 * paths are exercised for real. Excluded by definition: hidden/submit/button/reset/image inputs, and the
 * Stripe Payment Element's iframe on billing/payment/show (Stripe owns it; that page has no field of its
 * own).
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function uiActor(string $role): User
{
    return User::factory()->create()->assignRole($role);
}

/** GET a page as an actor and return its HTML. */
function uiGet($test, User $actor, string $url): string
{
    $response = $test->actingAs($actor)->get($url)->assertOk();

    return $response->getContent();
}

/** Submit an invalid form from its page and return the HTML of the page the failure redirects back to. */
function uiFailedSubmission($test, User $actor, string $from, string $method, string $url, array $payload): string
{
    $response = $test->actingAs($actor)->followingRedirects()->from($from)->{$method}($url, $payload);
    $response->assertOk();

    return $response->getContent();
}

function uiOpenTicketFor(User $owner): Ticket
{
    return Ticket::factory()->open()->for($owner, 'user')->create();
}

function uiInvoiceWithItems(array $state = []): Invoice
{
    $invoice = Invoice::factory()->sent()->create($state);
    $invoice->items()->create(['description' => 'Design', 'quantity' => 2, 'unit_price' => 50, 'amount' => 100, 'position' => 0]);

    return $invoice;
}

/**
 * Every target page (GET, no errors): name => closure returning [html, minimum labelable controls].
 *
 * @return array<string, Closure>
 */
function uiTargetPages(): array
{
    return [
        // ── Helpdesk ─────────────────────────────────────────────────────────────
        'tickets.index (member)' => function ($t) {
            $member = uiActor('user');
            uiOpenTicketFor($member);

            return [uiGet($t, $member, route('tickets.index')), 2];
        },
        'tickets.create (member)' => fn ($t) => [uiGet($t, uiActor('user'), route('tickets.create')), 5],
        'tickets.show (member)' => function ($t) {
            $member = uiActor('user');

            return [uiGet($t, $member, route('tickets.show', uiOpenTicketFor($member))), 2];
        },
        'operator.tickets.index' => function ($t) {
            $operator = uiActor('operator');
            uiOpenTicketFor($operator);
            Ticket::factory()->open()->overdue()->for($operator, 'user')->create();

            return [uiGet($t, $operator, route('operator.tickets.index')), 11];
        },
        'operator.tickets.show' => function ($t) {
            $operator = uiActor('operator');

            return [uiGet($t, $operator, route('operator.tickets.show', uiOpenTicketFor($operator))), 4];
        },
        'operator.tickets.reports' => fn ($t) => [uiGet($t, uiActor('operator'), route('operator.tickets.reports')), 2],

        // ── Directory ────────────────────────────────────────────────────────────
        'crm.contacts.index' => function ($t) {
            CrmContact::factory()->create();

            return [uiGet($t, uiActor('operator'), route('crm.contacts.index')), 1];
        },
        'crm.contacts.create' => fn ($t) => [uiGet($t, uiActor('operator'), route('crm.contacts.create')), 7],
        'crm.contacts.edit' => fn ($t) => [uiGet($t, uiActor('operator'), route('crm.contacts.edit', CrmContact::factory()->create())), 7],
        'crm.contacts.show' => fn ($t) => [uiGet($t, uiActor('operator'), route('crm.contacts.show', CrmContact::factory()->create())), 0],
        'crm.companies.index' => function ($t) {
            CrmCompany::factory()->create();

            return [uiGet($t, uiActor('operator'), route('crm.companies.index')), 1];
        },
        'crm.companies.create' => fn ($t) => [uiGet($t, uiActor('operator'), route('crm.companies.create')), 5],
        'crm.companies.edit' => fn ($t) => [uiGet($t, uiActor('operator'), route('crm.companies.edit', CrmCompany::factory()->create())), 5],
        'crm.companies.show' => fn ($t) => [uiGet($t, uiActor('operator'), route('crm.companies.show', CrmCompany::factory()->create())), 0],
        'organizations.index' => function ($t) {
            Organization::factory()->create();

            return [uiGet($t, uiActor('operator'), route('organizations.index')), 0];
        },
        'organizations.show' => function ($t) {
            $operator = uiActor('operator');
            $organization = Organization::factory()->create(['owner_id' => $operator->id]);
            $organization->members()->attach(uiActor('user')->id, ['role' => 'member']);

            return [uiGet($t, $operator, route('organizations.show', $organization)), 2];
        },

        // ── Finance ──────────────────────────────────────────────────────────────
        'billing.invoices.index' => function ($t) {
            uiInvoiceWithItems();

            return [uiGet($t, uiActor('operator'), route('billing.invoices.index')), 2];
        },
        'billing.invoices.create' => fn ($t) => [uiGet($t, uiActor('operator'), route('billing.invoices.create')), 11],
        'billing.invoices.edit' => fn ($t) => [uiGet($t, uiActor('operator'), route('billing.invoices.edit', uiInvoiceWithItems(['status' => 'draft']))), 11],
        'billing.invoices.show' => fn ($t) => [uiGet($t, uiActor('operator'), route('billing.invoices.show', uiInvoiceWithItems())), 2],
        'billing.client.invoices.index (member)' => function ($t) {
            $member = uiActor('user');
            uiInvoiceWithItems(['client_id' => $member->id]);

            return [uiGet($t, $member, route('billing.client.invoices.index')), 0];
        },
        'billing.client.invoices.show (member)' => function ($t) {
            $member = uiActor('user');

            return [uiGet($t, $member, route('billing.client.invoices.show', uiInvoiceWithItems(['client_id' => $member->id]))), 0];
        },

        // ── System ───────────────────────────────────────────────────────────────
        'users.index' => fn ($t) => [uiGet($t, uiActor('operator'), route('users.index')), 2],
        'users.show' => fn ($t) => [uiGet($t, uiActor('operator'), route('users.show', uiActor('user'))), 2],
        'roles.index' => fn ($t) => [uiGet($t, uiActor('operator'), route('roles.index')), 0],
        'roles.create' => fn ($t) => [uiGet($t, uiActor('operator'), route('roles.create')), 40],
        'roles.edit' => fn ($t) => [uiGet($t, uiActor('operator'), route('roles.edit', Role::findByName('user'))), 40],
        'operator.cms.index' => function ($t) {
            CmsPage::factory()->create();

            return [uiGet($t, uiActor('operator'), route('operator.cms.index')), 0];
        },
        'operator.cms.create' => fn ($t) => [uiGet($t, uiActor('operator'), route('operator.cms.create')), 3],
        'operator.cms.edit' => fn ($t) => [uiGet($t, uiActor('operator'), route('operator.cms.edit', CmsPage::factory()->create())), 3],
    ];
}

it('satisfies the accessibility contract on every target page', function (string $page) {
    [$html, $minimumControls] = uiTargetPages()[$page]($this);

    // Non-vacuity: the page really rendered the controls the contract is about.
    expect(uiLabelableCount($html))->toBeGreaterThanOrEqual($minimumControls)
        ->and(uiAccessibilityViolations($html))->toBe([]);
})->with(array_keys(uiTargetPages()));

it('renders errors/403 inside the shell with its actions named and no duplicate id', function () {
    $response = $this->actingAs(uiActor('user'))->get(route('users.index'))->assertForbidden();
    $html = $response->getContent();

    expect(uiAccessibilityViolations($html))->toBe([])
        ->and($html)->toContain('Access Denied');
});

// ── Error paths: the forms that validate, after a failed submission ──────────────

/**
 * Failed-submission scenarios: name => closure returning the HTML of the redirected-back page.
 *
 * @return array<string, Closure>
 */
function uiFailedForms(): array
{
    return [
        'tickets.create' => fn ($t) => uiFailedSubmission($t, uiActor('user'), route('tickets.create'), 'post', route('tickets.store'), []),
        'tickets.show reply' => function ($t) {
            $member = uiActor('user');
            $ticket = uiOpenTicketFor($member);

            return uiFailedSubmission($t, $member, route('tickets.show', $ticket), 'post', route('tickets.replies.store', $ticket), ['body' => '']);
        },
        'operator.tickets.show reply' => function ($t) {
            $operator = uiActor('operator');
            $ticket = uiOpenTicketFor($operator);

            return uiFailedSubmission($t, $operator, route('operator.tickets.show', $ticket), 'post', route('operator.tickets.replies.store', $ticket), ['body' => '']);
        },
        'operator.tickets.show status' => function ($t) {
            $operator = uiActor('operator');
            $ticket = uiOpenTicketFor($operator);

            return uiFailedSubmission($t, $operator, route('operator.tickets.show', $ticket), 'put', route('operator.tickets.status', $ticket), ['status' => 'not-a-status']);
        },
        'crm.contacts.create' => fn ($t) => uiFailedSubmission($t, uiActor('operator'), route('crm.contacts.create'), 'post', route('crm.contacts.store'), ['email' => 'not-an-email']),
        'crm.companies.create' => fn ($t) => uiFailedSubmission($t, uiActor('operator'), route('crm.companies.create'), 'post', route('crm.companies.store'), ['website' => 'x']),
        'billing.invoices.create (row 2 invalid)' => fn ($t) => uiFailedSubmission($t, uiActor('operator'), route('billing.invoices.create'), 'post', route('billing.invoices.store'), [
            'client_id' => '', 'issued_at' => '2026-04-01', 'due_at' => '2026-03-01', 'currency' => 'USD',
            'items' => [
                0 => ['description' => 'Fine', 'quantity' => '1', 'unit_price' => '10'],
                2 => ['description' => '', 'quantity' => '0', 'unit_price' => ''],
            ],
        ]),
        'billing.invoices.show payment' => function ($t) {
            $invoice = uiInvoiceWithItems();

            return uiFailedSubmission($t, uiActor('operator'), route('billing.invoices.show', $invoice), 'post', route('billing.invoices.payment.record', $invoice), ['amount' => '']);
        },
        'roles.create' => fn ($t) => uiFailedSubmission($t, uiActor('operator'), route('roles.create'), 'post', route('roles.store'), ['name' => '', 'permissions' => ['no.such.permission']]),
        'roles.edit' => function ($t) {
            $role = Role::findByName('user');

            return uiFailedSubmission($t, uiActor('operator'), route('roles.edit', $role), 'put', route('roles.update', $role), ['permissions' => ['no.such.permission']]);
        },
        'users.show roles' => function ($t) {
            $user = uiActor('user');

            return uiFailedSubmission($t, uiActor('operator'), route('users.show', $user), 'put', route('users.roles.update', $user), ['roles' => ['no-such-role']]);
        },
        'operator.cms.create' => fn ($t) => uiFailedSubmission($t, uiActor('operator'), route('operator.cms.create'), 'post', route('operator.cms.store'), ['title' => '']),
        'organizations.show add member' => function ($t) {
            $operator = uiActor('operator');
            $organization = Organization::factory()->create(['owner_id' => $operator->id]);
            uiActor('user');

            return uiFailedSubmission($t, $operator, route('organizations.show', $organization), 'post', route('organizations.members.store', $organization), ['user_id' => '', 'role' => 'nonsense']);
        },
    ];
}

it('keeps every error associated with its field, and the field aria-invalid, after a failed submission', function (string $form) {
    $html = uiFailedForms()[$form]($this);
    $xpath = uiDom($html);

    // Non-vacuity: this really is the failure page, with an error element and a truthful invalid marker.
    $errors = $xpath->query('//*[@role="alert"][substring(@id, string-length(@id) - 5) = "-error"]');
    expect($errors->length)->toBeGreaterThan(0)
        ->and($xpath->query('//*[@aria-invalid="true"] | //*[@role="group"][@aria-describedby]')->length)->toBeGreaterThan(0)
        ->and(uiAccessibilityViolations($html))->toBe([]);
})->with(array_keys(uiFailedForms()));
