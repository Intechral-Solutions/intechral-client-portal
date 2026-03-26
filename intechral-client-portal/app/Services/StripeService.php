<?php

namespace App\Services;

use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\Webhook;

class StripeService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    public function createPaymentIntent(int $amount, string $currency, array $metadata = []): PaymentIntent
    {
        return PaymentIntent::create([
            'amount'               => $amount,
            'currency'             => $currency,
            'automatic_payment_methods' => ['enabled' => true],
            'metadata'             => $metadata,
        ]);
    }

    public function retrievePaymentIntent(string $id): PaymentIntent
    {
        return PaymentIntent::retrieve($id);
    }

    /**
     * Verify and parse an incoming Stripe webhook payload.
     * Returns the Event object or throws a SignatureVerificationException.
     */
    public function constructEvent(string $payload, string $sigHeader): \Stripe\Event
    {
        return Webhook::constructEvent(
            $payload,
            $sigHeader,
            config('services.stripe.webhook_secret'),
        );
    }
}
