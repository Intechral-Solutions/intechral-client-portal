# ADR-004: Use Stripe for Online Payment Collection

**Date:** 2024-03-24
**Status:** Accepted

## Context

The billing module needs to collect online payments from client organizations. Requirements:
- No raw card data on our servers (PCI compliance)
- Payment status must update the invoice automatically
- Clients need a full payment history

## Decision

Use **Stripe** as the payment gateway via the official **`stripe/stripe-php`** SDK. Payments are collected via **Stripe Payment Intents** with Stripe-hosted Elements or Checkout. Webhook events update invoice status server-side.

## Rationale

- Stripe handles PCI compliance — card data never touches our servers
- Payment Intents support SCA (Strong Customer Authentication) for EU compliance
- Webhooks provide reliable, server-side confirmation of payment success/failure
- Stripe Dashboard gives operators visibility into all transactions
- Widely supported on cPanel hosts (outbound HTTPS to `api.stripe.com`)

## Consequences

- Requires Stripe API keys in `.env` (`STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`)
- Webhook endpoint must be publicly accessible (use Stripe CLI for local dev: `stripe listen --forward-to localhost:4242/webhooks/stripe`)
- Failed webhook deliveries must be handled (idempotency keys on payment recording)
- Laravel Cashier is available if subscription billing is needed in future
