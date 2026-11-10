<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    /** Operator-only: list all invoices */
    public function viewAny(User $user): bool
    {
        return $user->can('billing.manage');
    }

    /** Operators (billing.manage) see any; clients (billing.view) see their own only */
    public function view(User $user, Invoice $invoice): bool
    {
        if ($user->can('billing.manage')) {
            return true;
        }

        return $user->can('billing.view') && $invoice->client_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('billing.create');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        if (! $user->can('billing.manage')) {
            return false;
        }

        return $invoice->status === 'draft';
    }

    public function send(User $user, Invoice $invoice): bool
    {
        return $user->can('billing.manage') && $invoice->status === 'draft';
    }

    public function recordPayment(User $user): bool
    {
        return $user->can('billing.manage');
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->can('billing.admin') && $invoice->status === 'draft';
    }

    /**
     * A client can pay their own payable invoice, and only with an explicit `billing.view` (R1-1): ownership and the
     * payable state are necessary but no longer sufficient, so a customer holding no billing capability (the built-in
     * `user` role carries none) cannot start a payment for an invoice that happens to name them.
     */
    public function pay(User $user, Invoice $invoice): bool
    {
        return $user->can('billing.view') && $invoice->client_id === $user->id && $invoice->isPayable();
    }

    /** A client may list their own invoices only with an explicit `billing.view` (R1-1); the list itself is scoped to the caller. */
    public function viewOwn(User $user): bool
    {
        return $user->can('billing.view');
    }
}
