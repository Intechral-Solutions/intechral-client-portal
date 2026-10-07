@extends('layouts.app', ['title' => $invoice->invoice_number])

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">

    {{-- Breadcrumb + Actions --}}
    <div class="mb-6 flex items-start justify-between">
        <div>
            <x-ui.link variant="quiet" :href="route('billing.invoices.index')" class="text-sm">Invoices</x-ui.link>
            <h1 class="mt-1 text-2xl font-semibold font-mono text-text">{{ $invoice->invoice_number }}</h1>
        </div>
        <div class="flex items-center gap-2 shrink-0 ml-4">
            @can('update', $invoice)
            <x-ui.button :href="route('billing.invoices.edit', $invoice)" variant="secondary">Edit</x-ui.button>
            @endcan
            @can('send', $invoice)
            <form method="POST" action="{{ route('billing.invoices.send', $invoice) }}">
                @csrf
                <x-ui.button type="submit">Mark as Sent</x-ui.button>
            </form>
            @endcan
            @can('delete', $invoice)
            <form method="POST" action="{{ route('billing.invoices.destroy', $invoice) }}"
                  onsubmit="return confirm('Delete this invoice?')">
                @csrf @method('DELETE')
                <x-ui.button type="submit" variant="secondary" tone="danger">Delete</x-ui.button>
            </form>
            @endcan
        </div>
    </div>

    @if (session('success'))
    <x-ui.alert variant="success" class="mb-6">{{ session('success') }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- Invoice --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Header card --}}
            <div class="rounded-lg border p-6 bg-surface border-rule">
                <div class="flex items-start justify-between mb-6">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide mb-1 text-text-muted">Billed to</p>
                        <p class="font-semibold text-text">{{ $invoice->client->name }}</p>
                        <p class="text-sm text-text-secondary">{{ $invoice->client->email }}</p>
                    </div>
                    @include('billing._invoice_status', ['status' => $invoice->status])
                </div>

                <div class="grid grid-cols-3 gap-4 text-sm border-t pt-4 border-rule">
                    <div>
                        <p class="text-xs text-text-muted">Issued</p>
                        <p class="text-text">{{ $invoice->issued_at->format('M j, Y') }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-text-muted">Due</p>
                        <p class="{{ $invoice->isOverdue() ? 'font-medium text-danger' : 'text-text' }}">
                            {{ $invoice->due_at->format('M j, Y') }}
                        </p>
                    </div>
                    @if ($invoice->project)
                    <div>
                        <p class="text-xs text-text-muted">Project</p>
                        <p class="text-text">{{ $invoice->project->name }}</p>
                    </div>
                    @endif
                </div>
            </div>

            {{-- Line items --}}
            <div class="rounded-lg border overflow-hidden bg-surface border-rule">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-rule-control">
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Description</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide w-20 text-text-muted">Qty</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide w-28 text-text-muted">Unit Price</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide w-28 text-text-muted">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-rule">
                        @foreach ($invoice->items as $item)
                        <tr>
                            <td class="px-4 py-3 text-text">{{ $item->description }}</td>
                            <td class="px-4 py-3 text-right text-text-secondary">{{ rtrim(rtrim(number_format((float)$item->quantity, 2), '0'), '.') }}</td>
                            <td class="px-4 py-3 text-right text-text-secondary">{{ number_format((float)$item->unit_price, 2) }}</td>
                            <td class="px-4 py-3 text-right font-medium text-text">{{ number_format((float)$item->amount, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t border-rule">
                        <tr>
                            <td colspan="3" class="px-4 py-2 text-right text-sm text-text-muted">Subtotal</td>
                            <td class="px-4 py-2 text-right text-sm text-text">{{ number_format((float)$invoice->subtotal, 2) }}</td>
                        </tr>
                        @if ((float) $invoice->tax_rate > 0)
                        <tr>
                            <td colspan="3" class="px-4 py-2 text-right text-sm text-text-muted">Tax ({{ $invoice->tax_rate }}%)</td>
                            <td class="px-4 py-2 text-right text-sm text-text">{{ number_format((float)$invoice->tax_amount, 2) }}</td>
                        </tr>
                        @endif
                        <tr class="font-semibold">
                            <td colspan="3" class="px-4 py-3 text-right text-text">Total ({{ $invoice->currency }})</td>
                            <td class="px-4 py-3 text-right text-base {{ $invoice->status === 'cancelled' ? 'text-text-muted line-through' : 'text-text' }}">{{ number_format((float)$invoice->total, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            @if ($invoice->notes)
            <div class="rounded-lg border p-5 bg-surface border-rule">
                <p class="text-xs font-semibold uppercase tracking-wide mb-2 text-text-muted">Notes</p>
                <p class="text-sm whitespace-pre-line text-text-secondary">{{ $invoice->notes }}</p>
            </div>
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="space-y-4">

            {{-- Payment status --}}
            <div class="rounded-lg border p-5 bg-surface border-rule">
                <p class="text-xs font-semibold uppercase tracking-wide mb-3 text-text-muted">Payment</p>

                @if ($invoice->isPaid())
                <p class="text-sm font-medium mb-1 text-success">&#10003; Paid {{ $invoice->paid_at->format('M j, Y') }}</p>
                @elseif ($invoice->isPayable())
                <p class="text-sm mb-3 text-text-secondary">
                    Amount due: <span class="font-semibold text-text">{{ $invoice->currency }} {{ number_format((float)$invoice->total, 2) }}</span>
                </p>
                @can('pay', $invoice)
                <x-ui.button :href="route('billing.invoices.pay', $invoice)" variant="secondary" class="w-full">Pay Now</x-ui.button>
                @endcan
                @else
                <p class="text-sm text-text-muted">No payment due.</p>
                @endif
            </div>

            {{-- Payment history --}}
            @if ($invoice->payments->isNotEmpty())
            <div class="rounded-lg border p-5 bg-surface border-rule">
                <p class="text-xs font-semibold uppercase tracking-wide mb-3 text-text-muted">Payment History</p>
                @foreach ($invoice->payments as $payment)
                <div class="flex justify-between text-sm mb-2">
                    <span class="text-text-secondary">{{ $payment->paid_at->format('M j, Y') }} &mdash; {{ ucfirst($payment->method) }}</span>
                    <span class="font-medium text-success">+{{ number_format((float)$payment->amount, 2) }}</span>
                </div>
                @endforeach
            </div>
            @endif

            {{-- Record manual payment --}}
            @can('recordPayment', \App\Models\Invoice::class)
            @if (! $invoice->isPaid())
            <div class="rounded-lg border p-5 bg-surface border-rule">
                <p class="text-xs font-semibold uppercase tracking-wide mb-3 text-text-muted">Record Manual Payment</p>
                <form method="POST" action="{{ route('billing.invoices.payment.record', $invoice) }}" class="space-y-3">
                    @csrf
                    <div class="space-y-2">
                        <x-ui.label for="amount">Amount</x-ui.label>
                        <x-ui.input type="number" name="amount" min="0.01" step="0.01" required
                                    :value="number_format((float)$invoice->total, 2)" class="w-full" />
                        <x-ui.field-error for="amount" />
                    </div>
                    <div class="space-y-2">
                        <x-ui.label for="notes">Notes</x-ui.label>
                        <x-ui.input type="text" name="notes" placeholder="e.g. Bank transfer" class="w-full" />
                        <x-ui.field-error for="notes" />
                    </div>
                    <x-ui.button type="submit" class="w-full">Record Payment</x-ui.button>
                </form>
            </div>
            @endif
            @endcan
        </div>
    </div>
</div>
@endsection
