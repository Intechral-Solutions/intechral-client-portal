<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\InvoiceService;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;

class StripeWebhookController extends Controller
{
    public function __construct(
        private StripeService $stripe,
        private InvoiceService $invoiceService,
    ) {}

    public function handle(Request $request): Response
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature', '');

        try {
            $event = $this->stripe->constructEvent($payload, $sigHeader);
        } catch (SignatureVerificationException) {
            return response('Invalid signature', 400);
        }

        match ($event->type) {
            'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event->data->object),
            default => null,
        };

        return response('OK', 200);
    }

    private function handlePaymentIntentSucceeded(object $paymentIntent): void
    {
        $this->invoiceService->handlePaymentSucceeded(
            paymentIntentId: $paymentIntent->id,
            chargeData: [
                'amount' => $paymentIntent->amount_received,
                'currency' => $paymentIntent->currency,
                'charge_id' => $paymentIntent->latest_charge,
            ],
        );
    }
}
