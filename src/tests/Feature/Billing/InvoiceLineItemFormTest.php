<?php

use App\Models\Invoice;
use App\Models\User;

require_once __DIR__.'/../../Support/UiHtmlHelpers.php';

/*
 * EPIC-016 WP1 §7.5 "Invoice line items" and P8 (owner-approved): deterministic identity for every
 * line-item field, the `<template>` the "+ Add line item" button clones, the `max(keys) + 1` next-index
 * rule, and the regression for the index collision after a failed submission that removed a middle row.
 * Submission names stay `items[{i}][description|quantity|unit_price]`; invoice math is untouched.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create()->assignRole('operator');
});

/** @param array<int|string, array<string, string>> $items */
function lineItemPayload(array $items, array $overrides = []): array
{
    return array_merge([
        'client_id' => User::factory()->create()->id,
        'issued_at' => '2026-04-01',
        'due_at' => '2026-04-30',
        'tax_rate' => '0',
        'currency' => 'USD',
        'items' => $items,
    ], $overrides);
}

/** The next index the page's script will hand to the next added row. */
function lineItemNextIndex(string $html): int
{
    preg_match('/let idx = (-?\d+);/', $html, $match);

    return (int) $match[1];
}

/**
 * What the "+ Add line item" script does: take the template's markup, substitute __INDEX__, and put the
 * row in the form. (Where the row lands in the document does not matter to name and id uniqueness.)
 */
function lineItemAddRow(string $html, int $index): string
{
    preg_match('/<template id="line-item-template">(.*?)<\/template>/s', $html, $template);

    return str_replace($template[0], str_replace('__INDEX__', (string) $index, $template[1]), $html);
}

/** @return list<string> */
function lineItemNames(string $html): array
{
    $names = [];
    foreach (uiDom(preg_replace('/<template.*?<\/template>/s', '', $html))->query('//input[starts-with(@name, "items[")]') as $input) {
        $names[] = $input->getAttribute('name');
    }

    return $names;
}

it('gives every line-item field the deterministic name, id, label and error element the epic specifies', function () {
    $html = $this->actingAs($this->operator)->get(route('billing.invoices.create'))->assertOk()->getContent();
    $xpath = uiDom(preg_replace('/<template.*?<\/template>/s', '', $html));

    foreach (['description', 'quantity', 'unit_price'] as $field) {
        $input = uiOne($xpath, '//input[@name="items[0]['.$field.']"]');

        expect($input->getAttribute('id'))->toBe('items-0-'.$field)
            ->and(uiOne($xpath, '//label[@for="items-0-'.$field.'"]')->textContent)->not->toBe('');
    }

    expect(uiAccessibilityViolations($html))->toBe([]);
});

it('keeps the visible column labels on the first row and sr-only copies on every other row', function () {
    $invoice = Invoice::factory()->draft()->create();
    foreach ([0, 1, 2] as $position) {
        $invoice->items()->create(['description' => "Item {$position}", 'quantity' => 1, 'unit_price' => 10, 'amount' => 10, 'position' => $position]);
    }

    $html = $this->actingAs($this->operator)->get(route('billing.invoices.edit', $invoice))->assertOk()->getContent();
    $xpath = uiDom(preg_replace('/<template.*?<\/template>/s', '', $html));
    $rows = $xpath->query('//*[contains(@class, "line-item-row")]');

    expect($rows->length)->toBe(3);

    foreach ($rows as $position => $row) {
        $labels = (new DOMXPath($row->ownerDocument))->query('.//label', $row);

        expect($labels->length)->toBe(3);
        foreach ($labels as $label) {
            expect(in_array('sr-only', uiClasses($label), true))->toBe($position !== 0);
        }
    }

    expect(lineItemNextIndex($html))->toBe(3);
});

it('names the remove control "Remove line item" on every row and in the template', function () {
    $html = $this->actingAs($this->operator)->get(route('billing.invoices.create'))->assertOk()->getContent();
    $xpath = uiDom($html);
    $buttons = $xpath->query('//button[@data-remove-line-item]');

    expect($buttons->length)->toBe(2); // the first row and the template

    foreach ($buttons as $button) {
        expect($button->getAttribute('aria-label'))->toBe('Remove line item')
            ->and($button->getAttribute('type'))->toBe('button')
            ->and(uiClasses($button))->toContain('text-danger', 'size-9')
            ->and(uiClasses($button))->not->toContain('bg-ink');
    }
});

it('renders the template with __INDEX__ in every name, id and label for, and never an error', function () {
    $html = $this->actingAs($this->operator)->get(route('billing.invoices.create'))->assertOk()->getContent();
    $xpath = uiDom($html);
    $template = uiOne($xpath, '//template[@id="line-item-template"]');
    $inner = $template->ownerDocument->saveHTML($template);
    $templateXpath = uiDom($inner);

    foreach (['description', 'quantity', 'unit_price'] as $field) {
        $input = uiOne($templateXpath, '//input[contains(@name, "['.$field.']")]');

        expect($input->getAttribute('name'))->toBe('items[__INDEX__]['.$field.']')
            ->and($input->getAttribute('id'))->toBe('items-__INDEX__-'.$field)
            ->and(uiOne($templateXpath, '//label[@for="items-__INDEX__-'.$field.'"]')->textContent)->not->toBe('')
            ->and($input->hasAttribute('aria-invalid'))->toBeFalse()
            ->and($input->hasAttribute('aria-describedby'))->toBeFalse();
    }

    expect($templateXpath->query('//*[@role="alert"]')->length)->toBe(0)
        // Every added row's labels are the sr-only copies of the visible column labels.
        ->and($templateXpath->query('//label[not(contains(@class, "sr-only"))]')->length)->toBe(0)
        ->and(trim(preg_replace('/\s+/', ' ', $templateXpath->query('//label')->item(0)->textContent)))->toBe('Description');
});

it('lines errors up with the row that caused them, by array key, not by position', function () {
    $response = $this->actingAs($this->operator)->followingRedirects()->from(route('billing.invoices.create'))
        ->post(route('billing.invoices.store'), lineItemPayload([
            0 => ['description' => 'Kept row', 'quantity' => '2', 'unit_price' => '10'],
            2 => ['description' => '', 'quantity' => '1', 'unit_price' => ''],
        ]));
    $html = $response->getContent();
    $xpath = uiDom(preg_replace('/<template.*?<\/template>/s', '', $html));

    // Row key 2 is invalid in description and unit price, and only there.
    foreach (['description', 'unit_price'] as $field) {
        expect(uiOne($xpath, '//input[@id="items-2-'.$field.'"]')->getAttribute('aria-invalid'))->toBe('true')
            ->and(uiOne($xpath, '//input[@id="items-2-'.$field.'"]')->getAttribute('aria-describedby'))->toBe('items-2-'.$field.'-error')
            ->and($xpath->query('//*[@id="items-2-'.$field.'-error"]')->length)->toBe(1);
    }
    foreach (['items-0-description', 'items-0-quantity', 'items-0-unit_price', 'items-2-quantity'] as $id) {
        expect(uiOne($xpath, '//input[@id="'.$id.'"]')->hasAttribute('aria-invalid'))->toBeFalse();
    }

    // Old values survive the failure, in the row they were submitted in.
    expect(uiOne($xpath, '//input[@id="items-0-description"]')->getAttribute('value'))->toBe('Kept row')
        ->and(uiOne($xpath, '//input[@id="items-0-quantity"]')->getAttribute('value'))->toBe('2')
        ->and(uiOne($xpath, '//input[@id="items-0-unit_price"]')->getAttribute('value'))->toBe('10')
        ->and(uiOne($xpath, '//input[@id="items-2-quantity"]')->getAttribute('value'))->toBe('1')
        ->and(uiAccessibilityViolations($html))->toBe([]);
});

it('REGRESSION: after a failed submit that removed a middle row, the next index is the highest key + 1, so an added row never duplicates a name or id', function () {
    // Submitted keys 0 and 2 (row 1 was removed), invalid so the form is re-rendered from old input.
    $html = $this->actingAs($this->operator)->followingRedirects()->from(route('billing.invoices.create'))
        ->post(route('billing.invoices.store'), lineItemPayload([
            0 => ['description' => 'First', 'quantity' => '1', 'unit_price' => '5'],
            2 => ['description' => 'Third', 'quantity' => '1', 'unit_price' => '-1'],
        ]))->getContent();

    $rowCount = uiDom(preg_replace('/<template.*?<\/template>/s', '', $html))->query('//*[contains(@class, "line-item-row")]')->length;
    $next = lineItemNextIndex($html);

    // Two rows, but the next index is 3: the highest existing key (2) + 1, never the row count (2).
    expect($rowCount)->toBe(2)
        ->and($next)->toBe(3)
        ->and(lineItemNames($html))->toBe([
            'items[0][description]', 'items[0][quantity]', 'items[0][unit_price]',
            'items[2][description]', 'items[2][quantity]', 'items[2][unit_price]',
        ]);

    // The row the script appends has no name or id in common with an existing row.
    $added = lineItemAddRow($html, $next);
    $names = lineItemNames($added);

    expect($names)->toHaveCount(9)
        ->and(array_unique($names))->toHaveCount(9)
        ->and($names)->toContain('items[3][description]', 'items[3][quantity]', 'items[3][unit_price]')
        ->and(uiAccessibilityViolations($added))->toBe([]);

    // Non-vacuity: seeding the index from the row count (the pre-EPIC-016 behaviour) really collides.
    $collided = lineItemAddRow($html, $rowCount);
    expect(array_unique(lineItemNames($collided)))->toHaveCount(6)
        ->and(uiAccessibilityViolations($collided))->toHaveViolation('duplicate id "items-2-description"');
});

it('seeds the next index from the highest key whatever the gaps', function (array $keys, int $expected) {
    $items = [];
    foreach ($keys as $key) {
        $items[$key] = ['description' => '', 'quantity' => '1', 'unit_price' => ''];
    }

    $html = $this->actingAs($this->operator)->followingRedirects()->from(route('billing.invoices.create'))
        ->post(route('billing.invoices.store'), lineItemPayload($items))->getContent();

    expect(lineItemNextIndex($html))->toBe($expected);
})->with([
    'only row 0' => [[0], 1],
    'middle row removed' => [[0, 2], 3],
    'first row removed' => [[1, 2], 3],
    'only a late key' => [[5], 6],
]);

it('submits the same names as before and saves the added row', function () {
    $client = User::factory()->create();
    $html = $this->actingAs($this->operator)->get(route('billing.invoices.create'))->assertOk()->getContent();
    $next = lineItemNextIndex($html);

    $this->actingAs($this->operator)->post(route('billing.invoices.store'), lineItemPayload([
        0 => ['description' => 'Design', 'quantity' => '2', 'unit_price' => '50.00'],
        $next => ['description' => 'Hosting', 'quantity' => '1', 'unit_price' => '20.00'],
    ], ['client_id' => $client->id]))->assertRedirect();

    $invoice = Invoice::where('client_id', $client->id)->firstOrFail();

    expect($next)->toBe(1)
        ->and($invoice->items()->count())->toBe(2)
        ->and((float) $invoice->subtotal)->toBe(120.0);
});

it('moves the add-line-item script off inline styles and the row count', function () {
    $html = $this->actingAs($this->operator)->get(route('billing.invoices.create'))->assertOk()->getContent();
    preg_match_all('/<script>(.*?)<\/script>/s', $html, $scripts);
    $script = [collect($scripts[1])->first(fn ($body) => str_contains($body, 'line-item-template'))];

    expect($script[0])->toContain('__INDEX__')
        ->and($script[0])->toContain('data-remove-line-item')
        ->and($script[0])->not->toContain('style=')
        ->and($script[0])->not->toContain('var(--')
        ->and($script[0])->not->toContain('.length');
});
