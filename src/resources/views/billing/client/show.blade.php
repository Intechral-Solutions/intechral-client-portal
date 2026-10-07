@extends('layouts.app', ['title' => $invoice->invoice_number])

@section('content')
<div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-6 flex items-start justify-between">
        <div>
            <x-ui.link variant="quiet" :href="route('billing.client.invoices.index')" class="inline-flex items-center gap-1 text-sm">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
                </svg>
                My Invoices
            </x-ui.link>
            <h1 class="mt-1 text-2xl font-semibold font-mono text-text">{{ $invoice->invoice_number }}</h1>
        </div>
        @if ($invoice->isPayable())
        <x-ui.button :href="route('billing.invoices.pay', $invoice)" class="mt-6 shrink-0">Pay Now</x-ui.button>
        @endif
    </div>

    @if (session('success'))
    <x-ui.alert variant="success" class="mb-6">{{ session('success') }}</x-ui.alert>
    @endif

    {{-- Status & dates --}}
    <div class="mb-6 rounded-lg border p-5 bg-surface border-rule">
        <div class="flex items-center justify-between mb-4">
            @include('billing._invoice_status', ['status' => $invoice->status])
            @if ($invoice->project)
            <span class="text-sm text-text-secondary">{{ $invoice->project->name }}</span>
            @endif
        </div>
        <div class="grid grid-cols-2 gap-4 text-sm">
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
        </div>
    </div>

    {{-- Line items --}}
    <div class="mb-6 rounded-lg border overflow-hidden bg-surface border-rule">
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
@endsection
