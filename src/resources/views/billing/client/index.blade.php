@extends('layouts.app', ['title' => 'My Invoices'])

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8">
        <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">My Invoices</h1>
        <p class="mt-1 text-sm" style="color: var(--text-secondary);">View and pay your invoices.</p>
    </div>

    @if ($invoices->isEmpty())
    <div class="rounded-xl border py-16 text-center"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <p class="text-sm" style="color: var(--text-muted);">No invoices yet.</p>
    </div>
    @else
    <div class="rounded-xl border overflow-hidden"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b" style="border-color: var(--border-base);">
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Invoice #</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Issued</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Due</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Amount</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y" style="divide-color: var(--border-base);">
                @foreach ($invoices as $invoice)
                <tr>
                    <td class="px-4 py-3">
                        <x-ui.link variant="row" :href="route('billing.client.invoices.show', $invoice)" class="font-mono">
                            {{ $invoice->invoice_number }}
                        </x-ui.link>
                    </td>
                    <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $invoice->issued_at->format('M j, Y') }}</td>
                    <td class="px-4 py-3 {{ $invoice->isOverdue() ? 'font-medium text-danger' : '' }}"
                        @unless ($invoice->isOverdue()) style="color: var(--text-secondary);" @endunless>
                        {{ $invoice->due_at->format('M j, Y') }}
                    </td>
                    <td class="px-4 py-3 text-right font-medium {{ $invoice->status === 'cancelled' ? 'line-through' : '' }}" style="color: var(--text-primary);">
                        {{ $invoice->currency }} {{ number_format((float)$invoice->total, 2) }}
                    </td>
                    <td class="px-4 py-3 text-center">
                        @include('billing._invoice_status', ['status' => $invoice->status])
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if ($invoice->isPayable())
                        <x-ui.button :href="route('billing.invoices.pay', $invoice)" variant="secondary" size="sm">Pay Now</x-ui.button>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $invoices->links() }}</div>
    @endif
</div>
@endsection
