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
                @php
                    $ss = [
                        'draft'     => ['bg' => 'var(--surface-muted)',   'text' => 'var(--text-muted)'],
                        'sent'      => ['bg' => 'var(--surface-info)',    'text' => 'var(--text-info)'],
                        'paid'      => ['bg' => 'var(--surface-success)', 'text' => 'var(--text-success)'],
                        'overdue'   => ['bg' => 'var(--surface-danger)',  'text' => 'var(--text-danger)'],
                        'cancelled' => ['bg' => 'var(--surface-muted)',   'text' => 'var(--text-muted)'],
                    ][$invoice->status] ?? ['bg' => 'var(--surface-muted)', 'text' => 'var(--text-muted)'];
                @endphp
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('billing.client.invoices.show', $invoice) }}"
                           class="font-mono font-medium hover:underline" style="color: var(--accent);">
                            {{ $invoice->invoice_number }}
                        </a>
                    </td>
                    <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $invoice->issued_at->format('M j, Y') }}</td>
                    <td class="px-4 py-3 {{ $invoice->isOverdue() ? 'font-medium' : '' }}"
                        style="color: {{ $invoice->isOverdue() ? 'var(--text-danger)' : 'var(--text-secondary)' }};">
                        {{ $invoice->due_at->format('M j, Y') }}
                    </td>
                    <td class="px-4 py-3 text-right font-medium" style="color: var(--text-primary);">
                        {{ $invoice->currency }} {{ number_format((float)$invoice->total, 2) }}
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-medium capitalize"
                              style="background-color: {{ $ss['bg'] }}; color: {{ $ss['text'] }};">
                            {{ $invoice->status }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if ($invoice->isPayable())
                        <a href="{{ route('billing.invoices.pay', $invoice) }}"
                           class="rounded-lg px-3 py-1 text-xs font-medium"
                           style="background-color: var(--accent); color: #fff;">Pay Now</a>
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
