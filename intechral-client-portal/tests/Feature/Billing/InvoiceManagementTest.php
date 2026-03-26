<?php

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\InvoiceService;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

// ── Guests ───────────────────────────────────────────────────────────────────

it('redirects guests from billing index to login', function () {
    $this->get(route('billing.invoices.index'))->assertRedirect('/login');
});

// ── Access control ───────────────────────────────────────────────────────────

it('returns 403 for users without billing.manage (operator index)', function () {
    $user = User::factory()->create();
    $user->assignRole('user'); // user role has billing.view but NOT billing.manage

    $this->actingAs($user)->get(route('billing.invoices.index'))->assertForbidden();
});

it('allows operators to list invoices', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->get(route('billing.invoices.index'))->assertOk();
});

it('returns 403 creating invoice without billing.create', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('billing.view');

    $this->actingAs($user)->get(route('billing.invoices.create'))->assertForbidden();
});

// ── Invoice creation ─────────────────────────────────────────────────────────

it('creates an invoice with line items and computes totals', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $client   = User::factory()->create();

    $this->actingAs($operator)->post(route('billing.invoices.store'), [
        'client_id' => $client->id,
        'issued_at' => '2026-04-01',
        'due_at'    => '2026-04-30',
        'tax_rate'  => '10',
        'currency'  => 'USD',
        'items' => [
            ['description' => 'Web Design', 'quantity' => '1', 'unit_price' => '1000.00'],
            ['description' => 'Hosting',    'quantity' => '12', 'unit_price' => '10.00'],
        ],
    ])->assertRedirect();

    $invoice = Invoice::where('client_id', $client->id)->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->invoice_number)->toStartWith('INV-');
    expect($invoice->status)->toBe('draft');
    expect((float) $invoice->subtotal)->toBe(1120.0);
    expect((float) $invoice->tax_amount)->toBe(112.0);
    expect((float) $invoice->total)->toBe(1232.0);
    expect($invoice->items()->count())->toBe(2);
});

it('validates required fields on invoice store', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->post(route('billing.invoices.store'), [])->assertSessionHasErrors(['client_id', 'issued_at', 'due_at', 'items']);
});

it('validates due_at must be after issued_at', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $client   = User::factory()->create();

    $this->actingAs($operator)->post(route('billing.invoices.store'), [
        'client_id' => $client->id,
        'issued_at' => '2026-04-30',
        'due_at'    => '2026-04-01',
        'items'     => [['description' => 'x', 'quantity' => 1, 'unit_price' => 10]],
    ])->assertSessionHasErrors('due_at');
});

// ── Invoice viewing ───────────────────────────────────────────────────────────

it('allows billing.view users to view an invoice', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $client   = User::factory()->create();

    $invoice = app(InvoiceService::class)->create($operator, [
        'client_id' => $client->id,
        'issued_at' => '2026-04-01',
        'due_at'    => '2026-04-30',
        'items'     => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 500]],
    ]);

    $this->actingAs($operator)->get(route('billing.invoices.show', $invoice))->assertOk();
});

it('allows the invoice client to view their own invoice', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $client   = User::factory()->create();
    $client->assignRole('user');

    $invoice = app(InvoiceService::class)->create($operator, [
        'client_id' => $client->id,
        'issued_at' => '2026-04-01',
        'due_at'    => '2026-04-30',
        'items'     => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 500]],
    ]);

    $this->actingAs($client)->get(route('billing.client.invoices.show', $invoice))->assertOk();
});

it('returns 403 when another user tries to view a different client invoice', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $client   = User::factory()->create();
    $other    = User::factory()->create();
    $other->assignRole('user');

    $invoice = app(InvoiceService::class)->create($operator, [
        'client_id' => $client->id,
        'issued_at' => '2026-04-01',
        'due_at'    => '2026-04-30',
        'items'     => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 500]],
    ]);

    $this->actingAs($other)->get(route('billing.client.invoices.show', $invoice))->assertForbidden();
});

// ── Invoice update ────────────────────────────────────────────────────────────

it('allows billing.manage to update a draft invoice', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $client   = User::factory()->create();

    $invoice = app(InvoiceService::class)->create($operator, [
        'client_id' => $client->id,
        'issued_at' => '2026-04-01',
        'due_at'    => '2026-04-30',
        'items'     => [['description' => 'Original', 'quantity' => 1, 'unit_price' => 100]],
    ]);

    $this->actingAs($operator)->put(route('billing.invoices.update', $invoice), [
        'client_id' => $client->id,
        'issued_at' => '2026-04-01',
        'due_at'    => '2026-04-30',
        'items'     => [['description' => 'Updated service', 'quantity' => 2, 'unit_price' => '200.00']],
    ])->assertRedirect();

    $invoice->refresh();
    expect((float) $invoice->subtotal)->toBe(400.0);
    expect($invoice->items()->count())->toBe(1);
});

it('returns 403 updating a non-draft invoice', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $client   = User::factory()->create();

    $invoice = app(InvoiceService::class)->create($operator, [
        'client_id' => $client->id,
        'issued_at' => '2026-04-01',
        'due_at'    => '2026-04-30',
        'items'     => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100]],
    ]);
    app(InvoiceService::class)->send($invoice);

    $this->actingAs($operator)->put(route('billing.invoices.update', $invoice), [
        'client_id' => $client->id,
        'issued_at' => '2026-04-01',
        'due_at'    => '2026-04-30',
        'items'     => [['description' => 'Attempt', 'quantity' => 1, 'unit_price' => 999]],
    ])->assertForbidden();
});

// ── Sending invoices ──────────────────────────────────────────────────────────

it('allows billing.manage to send a draft invoice', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $client   = User::factory()->create();

    $invoice = app(InvoiceService::class)->create($operator, [
        'client_id' => $client->id,
        'issued_at' => '2026-04-01',
        'due_at'    => '2026-04-30',
        'items'     => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100]],
    ]);

    $this->actingAs($operator)->post(route('billing.invoices.send', $invoice))->assertRedirect();

    expect($invoice->fresh()->status)->toBe('sent');
    expect($invoice->fresh()->sent_at)->not->toBeNull();
});

it('cannot send an already-sent invoice', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $client   = User::factory()->create();

    $invoice = app(InvoiceService::class)->create($operator, [
        'client_id' => $client->id,
        'issued_at' => '2026-04-01',
        'due_at'    => '2026-04-30',
        'items'     => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100]],
    ]);
    app(InvoiceService::class)->send($invoice);

    // Policy denies send on non-draft invoices → 403
    $this->actingAs($operator)->post(route('billing.invoices.send', $invoice))->assertForbidden();
});

// ── Manual payment ────────────────────────────────────────────────────────────

it('records a manual payment and marks invoice as paid when fully covered', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $client   = User::factory()->create();

    $invoice = app(InvoiceService::class)->create($operator, [
        'client_id' => $client->id,
        'issued_at' => '2026-04-01',
        'due_at'    => '2026-04-30',
        'items'     => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 500]],
    ]);
    app(InvoiceService::class)->send($invoice);

    $this->actingAs($operator)->post(route('billing.invoices.payment.record', $invoice), [
        'amount' => '500.00',
        'notes'  => 'Bank transfer',
    ])->assertRedirect();

    $invoice->refresh();
    expect($invoice->status)->toBe('paid');
    expect($invoice->payments()->count())->toBe(1);
    expect($invoice->payments()->first()->method)->toBe('manual');
});

// ── Invoice deletion ──────────────────────────────────────────────────────────

it('allows billing.admin to delete a draft invoice', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $client   = User::factory()->create();

    $invoice = app(InvoiceService::class)->create($operator, [
        'client_id' => $client->id,
        'issued_at' => '2026-04-01',
        'due_at'    => '2026-04-30',
        'items'     => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100]],
    ]);

    $this->actingAs($operator)->delete(route('billing.invoices.destroy', $invoice))
        ->assertRedirect(route('billing.invoices.index'));

    expect(Invoice::find($invoice->id))->toBeNull();
});

it('returns 403 deleting a non-draft invoice', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $client   = User::factory()->create();

    $invoice = app(InvoiceService::class)->create($operator, [
        'client_id' => $client->id,
        'issued_at' => '2026-04-01',
        'due_at'    => '2026-04-30',
        'items'     => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100]],
    ]);
    app(InvoiceService::class)->send($invoice);

    $this->actingAs($operator)->delete(route('billing.invoices.destroy', $invoice))->assertForbidden();
});

// ── Mark overdue ──────────────────────────────────────────────────────────────

it('marks overdue invoices correctly', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $client   = User::factory()->create();

    $invoice = app(InvoiceService::class)->create($operator, [
        'client_id' => $client->id,
        'issued_at' => '2026-03-01',
        'due_at'    => '2026-03-10',
        'items'     => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100]],
    ]);
    app(InvoiceService::class)->send($invoice);

    $count = app(InvoiceService::class)->markOverdue();
    expect($count)->toBe(1);
    expect($invoice->fresh()->status)->toBe('overdue');
});

// ── Client invoice list ───────────────────────────────────────────────────────

it('shows a client only their own invoices', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $client1  = User::factory()->create();
    $client1->assignRole('user');
    $client2  = User::factory()->create();

    $inv1 = app(InvoiceService::class)->create($operator, [
        'client_id' => $client1->id,
        'issued_at' => '2026-04-01',
        'due_at'    => '2026-04-30',
        'items'     => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100]],
    ]);
    $inv2 = app(InvoiceService::class)->create($operator, [
        'client_id' => $client2->id,
        'issued_at' => '2026-04-01',
        'due_at'    => '2026-04-30',
        'items'     => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 200]],
    ]);

    $response = $this->actingAs($client1)->get(route('billing.client.invoices.index'));
    $response->assertOk();
    $response->assertSee($inv1->invoice_number);
    $response->assertDontSee($inv2->invoice_number);
});
