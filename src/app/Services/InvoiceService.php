<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(private StripeService $stripe) {}

    /**
     * Create a new draft invoice with line items.
     *
     * $data keys: client_id, project_id?, issued_at, due_at, tax_rate?, currency?, notes?, items[]
     * Each item: description, quantity, unit_price
     */
    public function create(User $creator, array $data): Invoice
    {
        return DB::transaction(function () use ($creator, $data) {
            $invoice = Invoice::create([
                'invoice_number' => $this->nextInvoiceNumber(),
                'client_id' => $data['client_id'],
                'created_by' => $creator->id,
                'project_id' => $data['project_id'] ?? null,
                'issued_at' => $data['issued_at'],
                'due_at' => $data['due_at'],
                'tax_rate' => $data['tax_rate'] ?? 0,
                'currency' => $data['currency'] ?? 'USD',
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'subtotal' => 0,
                'tax_amount' => 0,
                'total' => 0,
            ]);

            foreach ($data['items'] ?? [] as $i => $item) {
                $this->addItem($invoice, $item, $i);
            }

            $this->recalculate($invoice);

            return $invoice->fresh(['items']);
        });
    }

    /**
     * Update invoice details (draft only).
     */
    public function update(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            $invoice->update([
                'client_id' => $data['client_id'] ?? $invoice->client_id,
                'project_id' => $data['project_id'] ?? $invoice->project_id,
                'issued_at' => $data['issued_at'] ?? $invoice->issued_at,
                'due_at' => $data['due_at'] ?? $invoice->due_at,
                'tax_rate' => $data['tax_rate'] ?? $invoice->tax_rate,
                'currency' => $data['currency'] ?? $invoice->currency,
                'notes' => $data['notes'] ?? $invoice->notes,
            ]);

            if (isset($data['items'])) {
                $invoice->items()->delete();
                foreach ($data['items'] as $i => $item) {
                    $this->addItem($invoice, $item, $i);
                }
            }

            $this->recalculate($invoice);

            return $invoice->fresh(['items']);
        });
    }

    /**
     * Mark invoice as sent and record the sent timestamp.
     */
    public function send(Invoice $invoice): void
    {
        abort_unless($invoice->status === 'draft', 422, 'Only draft invoices can be sent.');

        $invoice->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    /**
     * Create a Stripe PaymentIntent for the invoice and return the client secret.
     */
    public function createPaymentIntent(Invoice $invoice): string
    {
        abort_unless($invoice->isPayable(), 422, 'This invoice is not payable.');

        if ($invoice->stripe_payment_intent_id) {
            // Retrieve existing intent in case user re-visits payment page
            $intent = $this->stripe->retrievePaymentIntent($invoice->stripe_payment_intent_id);

            return $intent->client_secret;
        }

        $intent = $this->stripe->createPaymentIntent(
            amount: (int) round((float) $invoice->total * 100), // convert to cents
            currency: strtolower($invoice->currency),
            metadata: [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ],
        );

        $invoice->update(['stripe_payment_intent_id' => $intent->id]);

        return $intent->client_secret;
    }

    /**
     * Handle Stripe webhook: payment_intent.succeeded
     */
    public function handlePaymentSucceeded(string $paymentIntentId, array $chargeData): void
    {
        $invoice = Invoice::where('stripe_payment_intent_id', $paymentIntentId)->first();
        if (! $invoice || $invoice->isPaid()) {
            return;
        }

        InvoicePayment::create([
            'invoice_id' => $invoice->id,
            'amount' => $chargeData['amount'] / 100,
            'currency' => strtoupper($chargeData['currency']),
            'stripe_payment_intent_id' => $paymentIntentId,
            'stripe_charge_id' => $chargeData['charge_id'] ?? null,
            'method' => 'stripe',
            'paid_at' => now(),
        ]);

        $invoice->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }

    /**
     * Record a manual (off-platform) payment.
     */
    public function recordManualPayment(Invoice $invoice, float $amount, string $notes = ''): InvoicePayment
    {
        $payment = InvoicePayment::create([
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'currency' => $invoice->currency,
            'method' => 'manual',
            'notes' => $notes,
            'paid_at' => now(),
        ]);

        if ((float) $invoice->total <= $invoice->amountPaidSoFar()) {
            $invoice->update(['status' => 'paid', 'paid_at' => now()]);
        }

        return $payment;
    }

    /**
     * Mark overdue invoices (cron-ready helper).
     */
    public function markOverdue(): int
    {
        return Invoice::where('status', 'sent')
            ->where('due_at', '<', today())
            ->update(['status' => 'overdue']);
    }

    // ── Private helpers ──────────────────────────────────────

    private function addItem(Invoice $invoice, array $item, int $position): InvoiceItem
    {
        $amount = round((float) $item['quantity'] * (float) $item['unit_price'], 2);

        return $invoice->items()->create([
            'description' => $item['description'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'amount' => $amount,
            'position' => $position,
        ]);
    }

    private function recalculate(Invoice $invoice): void
    {
        $subtotal = (float) $invoice->items()->sum('amount');
        $taxAmount = round($subtotal * ((float) $invoice->tax_rate / 100), 2);

        $invoice->update([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => round($subtotal + $taxAmount, 2),
        ]);
    }

    private function nextInvoiceNumber(): string
    {
        $last = Invoice::max('id') ?? 0;

        return 'INV-'.str_pad($last + 1, 4, '0', STR_PAD_LEFT);
    }
}
