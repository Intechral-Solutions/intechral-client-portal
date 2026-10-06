<?php

use App\Models\CmsPage;
use App\Models\CrmContact;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;

require_once __DIR__.'/../../Support/UiHtmlHelpers.php';

/*
 * EPIC-016 WP1 §7.2 rule 4 / §10.2: accessible names on the target pages. Existing authored names stay;
 * previously unnamed controls gain the names the epic specifies; the three approved Finance placeholder
 * names become their visible label. The name is computed the way a browser does for these shapes:
 * aria-labelledby, then aria-label, then an associated or wrapping label, then the placeholder.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

/** The accessible name of an element, whitespace-normalised. */
function uiAccessibleName(DOMXPath $xpath, DOMElement $control): string
{
    $clean = fn (string $text): string => trim(preg_replace('/\s+/', ' ', $text) ?? '');

    $parts = [];
    foreach (uiTokens($control, 'aria-labelledby') as $token) {
        $target = $xpath->query('//*[@id="'.$token.'"]')->item(0);
        $parts[] = $target === null ? '' : $clean($target->textContent);
    }
    if (($labelled = $clean(implode(' ', $parts))) !== '') {
        return $labelled;
    }

    if ($clean($control->getAttribute('aria-label')) !== '') {
        return $clean($control->getAttribute('aria-label'));
    }

    $id = $control->getAttribute('id');
    if ($id !== '') {
        foreach ($xpath->query('//label[@for="'.$id.'"]') as $label) {
            return $clean($label->textContent);
        }
    }

    for ($ancestor = $control->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
        if ($ancestor->tagName === 'label') {
            return $clean($ancestor->textContent);
        }
    }

    return $clean($control->getAttribute('placeholder'));
}

/** @return list<string> the names of the controls matching the XPath, in document order */
function uiNames(string $html, string $query): array
{
    $xpath = uiDom($html);
    $names = [];

    foreach ($xpath->query($query) as $control) {
        $names[] = uiAccessibleName($xpath, $control);
    }

    return $names;
}

function uiPage($test, string $role, string $url): string
{
    $actor = User::factory()->create()->assignRole($role);

    return $test->actingAs($actor)->get($url)->assertOk()->getContent();
}

it('names the operator queue selection checkboxes, filters and bulk controls', function () {
    $operator = User::factory()->create()->assignRole('operator');
    $ticket = Ticket::factory()->open()->for($operator, 'user')->create();
    $html = $this->actingAs($operator)->get(route('operator.tickets.index'))->assertOk()->getContent();
    $xpath = uiDom($html);

    expect(uiAccessibleName($xpath, uiOne($xpath, '//input[@id="select-all"]')))->toBe('Select all tickets on this page')
        ->and(uiAccessibleName($xpath, uiOne($xpath, '//input[@name="ticket_ids[]"]')))->toBe('Select ticket '.$ticket->ticket_number)
        ->and(uiOne($xpath, '//input[@name="ticket_ids[]"]')->getAttribute('id'))->toBe('ticket-cb-'.$ticket->id)
        ->and(uiAccessibleName($xpath, uiOne($xpath, '//select[@name="status"]')))->toBe('Status')
        ->and(uiAccessibleName($xpath, uiOne($xpath, '//select[@name="priority"]')))->toBe('Priority')
        ->and(uiAccessibleName($xpath, uiOne($xpath, '//select[@name="assignee"]')))->toBe('Assignee')
        ->and(uiAccessibleName($xpath, uiOne($xpath, '//input[@name="date_from"]')))->toBe('Submitted from')
        ->and(uiAccessibleName($xpath, uiOne($xpath, '//input[@name="date_to"]')))->toBe('Submitted to')
        ->and(uiAccessibleName($xpath, uiOne($xpath, '//select[@name="action"]')))->toBe('Bulk action')
        ->and(uiAccessibleName($xpath, uiOne($xpath, '//select[@name="assignee_id"]')))->toBe('Assign to')
        // The search field keeps the name its placeholder always gave it.
        ->and(uiAccessibleName($xpath, uiOne($xpath, '//input[@name="search"]')))->toBe('Search…')
        // The id the queue script depends on is unchanged.
        ->and($xpath->query('//input[@id="select-all"]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="bulk-bar"]')->length)->toBe(1);
});

it('names the status filters on the member ticket list and the invoice list', function () {
    $member = User::factory()->create()->assignRole('user');
    $tickets = $this->actingAs($member)->get(route('tickets.index'))->assertOk()->getContent();
    $invoices = uiPage($this, 'operator', route('billing.invoices.index'));

    expect(uiNames($tickets, '//select[@name="status"]'))->toBe(['Status'])
        ->and(uiNames($tickets, '//input[@name="search"]'))->toBe(['Search tickets…'])
        ->and(uiNames($invoices, '//select[@name="status"]'))->toBe(['Status'])
        ->and(uiNames($invoices, '//input[@name="search"]'))->toBe(['Search invoices or clients…']);
});

it('names the ticket-detail selects by their existing headings and the file input, hint associated', function () {
    $operator = User::factory()->create()->assignRole('operator');
    $ticket = Ticket::factory()->open()->for($operator, 'user')->create();
    $html = $this->actingAs($operator)->get(route('operator.tickets.show', $ticket))->assertOk()->getContent();
    $xpath = uiDom($html);
    $file = uiOne($xpath, '//input[@type="file"]');

    expect(uiAccessibleName($xpath, uiOne($xpath, '//select[@name="status"]')))->toBe('Status')
        ->and(uiOne($xpath, '//select[@name="status"]')->getAttribute('aria-labelledby'))->toBe('ticket-status-heading')
        ->and(uiAccessibleName($xpath, uiOne($xpath, '//select[@name="assignee_id"]')))->toBe('Assignee')
        ->and(uiAccessibleName($xpath, uiOne($xpath, '//textarea[@name="body"]')))->toBe('Write your reply…')
        ->and(uiAccessibleName($xpath, uiOne($xpath, '//input[@name="is_internal"]')))->toBe('Internal note (not visible to submitter)')
        ->and(uiAccessibleName($xpath, $file))->toBe('Attachments')
        ->and($file->getAttribute('name'))->toBe('attachments[]')
        ->and($file->getAttribute('aria-describedby'))->toBe('attachments-hint')
        ->and(trim(uiOne($xpath, '//*[@id="attachments-hint"]')->textContent))->toBe('Up to 10 files, 20 MB each.');

    $member = User::factory()->create()->assignRole('user');
    $own = Ticket::factory()->open()->for($member, 'user')->create();
    $memberHtml = $this->actingAs($member)->get(route('tickets.show', $own))->assertOk()->getContent();

    expect(uiNames($memberHtml, '//textarea[@name="body"]'))->toBe(['Your reply…'])
        ->and(uiNames($memberHtml, '//input[@type="file"]'))->toBe(['Attachments']);
});

it('names the new-ticket form fields, including the attachments input', function () {
    $html = uiPage($this, 'user', route('tickets.create'));

    expect(uiNames($html, '//input[@name="title"]'))->toBe(['Subject *'])
        ->and(uiNames($html, '//select[@name="category"]'))->toBe(['Category *'])
        ->and(uiNames($html, '//select[@name="priority"]'))->toBe(['Priority *'])
        ->and(uiNames($html, '//textarea[@name="description"]'))->toBe(['Description *'])
        ->and(uiNames($html, '//input[@type="file"]'))->toBe(['Attachments']);
});

it('leaves the embedded ticket time tracker, and its Start Timer name, untouched', function () {
    $owner = User::factory()->create()->assignRole('user');
    $ticket = Ticket::factory()->open()->for($owner, 'user')->create();
    $html = $this->actingAs($owner)->get(route('tickets.show', $ticket))->assertOk()->getContent();

    // tests/Browser/time-migration.spec.ts addresses the tracker by /Start Timer/; the markup is untouched.
    expect($html)->toContain('time-tracker-start-btn')
        ->and(preg_replace('/\s+/', ' ', $html))->toContain('&#9654; Start Timer');
});

it('names the report date range by its existing From and To labels', function () {
    $html = uiPage($this, 'operator', route('operator.tickets.reports'));

    expect(uiNames($html, '//input[@name="date_from"]'))->toBe(['From'])
        ->and(uiNames($html, '//input[@name="date_to"]'))->toBe(['To']);
});

it('associates the CRM, CMS and System form labels with their fields, keeping the visible wording', function () {
    $contact = uiPage($this, 'operator', route('crm.contacts.create'));
    $company = uiPage($this, 'operator', route('crm.companies.create'));
    $cms = uiPage($this, 'operator', route('operator.cms.create'));
    $role = uiPage($this, 'operator', route('roles.create'));

    expect(uiNames($contact, '//form[@action]//input[@type="text" or @type="email"] | //form//select | //form//textarea'))
        ->toBe(['First Name *', 'Last Name *', 'Company', 'Email', 'Phone', 'Job Title', 'Notes'])
        ->and(uiNames($company, '//form//input[not(@type="hidden")] | //form//textarea'))
        ->toBe(['Company Name *', 'Website', 'Phone', 'Address', 'Notes'])
        ->and(uiNames($cms, '//form//input[not(@type="hidden")] | //form//textarea'))
        ->toBe(['Title *', 'Slug (leave blank to auto-generate)', 'Content (HTML)'])
        ->and(uiNames($role, '//input[@name="name"]'))->toBe(['Role name *']);
});

it('keeps wrapped checkboxes named by their own label and groups them with role group', function () {
    $html = uiPage($this, 'operator', route('roles.create'));
    $xpath = uiDom($html);
    $group = uiOne($xpath, '//*[@id="permissions-group"]');
    $boxes = $xpath->query('//input[@name="permissions[]"]');

    expect($group->getAttribute('role'))->toBe('group')
        ->and($group->getAttribute('aria-labelledby'))->toBe('permissions-heading')
        ->and(trim(uiOne($xpath, '//*[@id="permissions-heading"]')->textContent))->toBe('Permissions')
        ->and($boxes->length)->toBeGreaterThan(20);

    foreach ($boxes as $box) {
        expect(uiAccessibleName($xpath, $box))->not->toBe('')
            ->and(uiClasses($box))->toContain('accent-accent')
            ->and(uiClasses($box))->not->toContain('accent-legacy-accent');
    }
});

it('names the organization member controls and the user-role group', function () {
    $operator = User::factory()->create()->assignRole('operator');
    $organization = Organization::factory()->create(['owner_id' => $operator->id]);
    User::factory()->create();
    $org = $this->actingAs($operator)->get(route('organizations.show', $organization))->assertOk()->getContent();

    $user = User::factory()->create();
    $show = $this->actingAs($operator)->get(route('users.show', $user))->assertOk()->getContent();
    $xpath = uiDom($show);

    expect(uiNames($org, '//select[@name="user_id"]'))->toBe(['Person to add'])
        ->and(uiNames($org, '//select[@name="role"]'))->toBe(['Organization role'])
        ->and(uiOne($xpath, '//*[@id="user-roles-group"]')->getAttribute('role'))->toBe('group')
        ->and(uiOne($xpath, '//*[@id="user-roles-group"]')->getAttribute('aria-labelledby'))->toBe('user-roles-heading')
        ->and(uiNames($show, '//input[@name="roles[]"]'))->each->not->toBe('');
});

it('names the invite modal email field by its existing label and keeps its id', function () {
    $html = uiPage($this, 'operator', route('users.index'));
    $xpath = uiDom($html);

    expect(uiOne($xpath, '//input[@id="invite-email"]')->getAttribute('name'))->toBe('email')
        ->and(uiAccessibleName($xpath, uiOne($xpath, '//input[@id="invite-email"]')))->toBe('Email address')
        ->and(uiNames($html, '//input[@name="search"]'))->toBe(['Search by name or email…']);
});

it('applies the three approved Finance name changes and keeps the placeholders as hints', function () {
    $operator = User::factory()->create()->assignRole('operator');
    $create = $this->actingAs($operator)->get(route('billing.invoices.create'))->assertOk()->getContent();
    $xpath = uiDom($create);

    // 1. Line-item description: "Service or product description" -> "Description".
    $description = uiOne($xpath, '//input[@id="items-0-description"]');
    expect(uiAccessibleName($xpath, $description))->toBe('Description')
        ->and($description->getAttribute('placeholder'))->toBe('Service or product description');

    // 2. Unit price on a JavaScript-added row: "0.00" -> "Unit Price" (the template every added row clones).
    $template = uiDom('<div id="t">'.uiOne($xpath, '//template[@id="line-item-template"]')->ownerDocument->saveHTML(uiOne($xpath, '//template[@id="line-item-template"]')).'</div>');
    $price = uiOne($template, '//input[contains(@id, "unit_price")]');
    expect(uiAccessibleName($template, $price))->toBe('Unit Price')
        ->and($price->getAttribute('placeholder'))->toBe('0.00');

    // 3. Payment notes on the invoice page: "e.g. Bank transfer" -> "Notes".
    $invoice = Invoice::factory()->sent()->create();
    $show = $this->actingAs($operator)->get(route('billing.invoices.show', $invoice))->assertOk()->getContent();
    $notes = uiOne(uiDom($show), '//form[contains(@action, "payments")]//input[@name="notes"]');
    expect(uiAccessibleName(uiDom($show), $notes))->toBe('Notes')
        ->and($notes->getAttribute('placeholder'))->toBe('e.g. Bank transfer')
        ->and(uiNames($show, '//form[contains(@action, "payments")]//input[@name="amount"]'))->toBe(['Amount']);
});

it('leaves a control that already had an authored name with exactly that name', function () {
    $operator = User::factory()->create()->assignRole('operator');
    $contact = CrmContact::factory()->create(['crm_company_id' => null]);
    $page = CmsPage::factory()->create();

    expect(uiNames(uiPage($this, 'operator', route('crm.contacts.index')), '//input[@name="search"]'))->toBe(['Search by name or email…'])
        ->and(uiNames(uiPage($this, 'operator', route('crm.companies.index')), '//input[@name="search"]'))->toBe(['Search by name…'])
        ->and(uiNames($this->actingAs($operator)->get(route('crm.contacts.edit', $contact))->getContent(), '//input[@name="first_name"]'))->toBe(['First Name *'])
        ->and(uiNames($this->actingAs($operator)->get(route('operator.cms.edit', $page))->getContent(), '//input[@name="title"]'))->toBe(['Title *']);
});
