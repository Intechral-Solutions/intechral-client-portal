@extends('layouts.app', ['title' => $invoice->invoice_number])

@section('content')
<div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-6 flex items-start justify-between">
        <div>
            <a href="{{ route('billing.client.invoices.index') }}" class="inline-flex items-center gap-1 text-sm" style="color: var(--text-secondary);">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
                </svg>
                My Invoices
            </a>
            <h1 class="mt-1 text-2xl font-semibold font-mono" style="color: var(--text-primary);">{{ $invoice->invoice_number }}</h1>
        </div>
        @if ($invoice->isPayable())
        <a href="{{ route('billing.invoices.pay', $invoice) }}"
           class="shrink-0 rounded-lg px-4 py-2 text-sm font-medium mt-6"
           style="background-color: var(--accent); color: #fff;">Pay Now</a>
        @endif
    </div>

    @if (session('success'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    {{-- Status & dates --}}
    <div class="mb-6 rounded-xl border p-5"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        @php
            $ss = [
                'sent'      => ['bg' => 'var(--surface-info)',    'text' => 'var(--text-info)'],
                'paid'      => ['bg' => 'var(--surface-success)', 'text' => 'var(--text-success)'],
                'overdue'   => ['bg' => 'var(--surface-danger)',  'text' => 'var(--text-danger)'],
                'cancelled' => ['bg' => 'var(--surface-muted)',   'text' => 'var(--text-muted)'],
                'draft'     => ['bg' => 'var(--surface-muted)',   'text' => 'var(--text-muted)'],
            ][$invoice->status] ?? ['bg' => 'var(--surface-muted)', 'text' => 'var(--text-muted)'];
        @endphp
        <div class="flex items-center justify-between mb-4">
            <span class="rounded-full px-3 py-1 text-sm font-medium capitalize"
                  style="background-color: {{ $ss['bg'] }}; color: {{ $ss['text'] }};">
                {{ $invoice->status }}
            </span>
            @if ($invoice->project)
            <span class="text-sm" style="color: var(--text-secondary);">{{ $invoice->project->name }}</span>
            @endif
        </div>
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <p class="text-xs" style="color: var(--text-muted);">Issued</p>
                <p style="color: var(--text-primary);">{{ $invoice->issued_at->format('M j, Y') }}</p>
            </div>
            <div>
                <p class="text-xs" style="color: var(--text-muted);">Due</p>
                <p class="{{ $invoice->isOverdue() ? 'font-medium' : '' }}"
                   style="color: {{ $invoice->isOverdue() ? 'var(--text-danger)' : 'var(--text-primary)' }};">
                    {{ $invoice->due_at->format('M j, Y') }}
                </p>
            </div>
        </div>
    </div>

    {{-- Line items --}}
    <div class="mb-6 rounded-xl border overflow-hidden"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b" style="border-color: var(--border-base);">
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Description</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide w-20" style="color: var(--text-muted);">Qty</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide w-28" style="color: var(--text-muted);">Unit Price</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide w-28" style="color: var(--text-muted);">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y" style="divide-color: var(--border-base);">
                @foreach ($invoice->items as $item)
                <tr>
                    <td class="px-4 py-3" style="color: var(--text-primary);">{{ $item->description }}</td>
                    <td class="px-4 py-3 text-right" style="color: var(--text-secondary);">{{ rtrim(rtrim(number_format((float)$item->quantity, 2), '0'), '.') }}</td>
                    <td class="px-4 py-3 text-right" style="color: var(--text-secondary);">{{ number_format((float)$item->unit_price, 2) }}</td>
                    <td class="px-4 py-3 text-right font-medium" style="color: var(--text-primary);">{{ number_format((float)$item->amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t" style="border-color: var(--border-base);">
                <tr>
                    <td colspan="3" class="px-4 py-2 text-right text-sm" style="color: var(--text-muted);">Subtotal</td>
                    <td class="px-4 py-2 text-right text-sm" style="color: var(--text-primary);">{{ number_format((float)$invoice->subtotal, 2) }}</td>
                </tr>
                @if ((float) $invoice->tax_rate > 0)
                <tr>
                    <td colspan="3" class="px-4 py-2 text-right text-sm" style="color: var(--text-muted);">Tax ({{ $invoice->tax_rate }}%)</td>
                    <td class="px-4 py-2 text-right text-sm" style="color: var(--text-primary);">{{ number_format((float)$invoice->tax_amount, 2) }}</td>
                </tr>
                @endif
                <tr class="font-semibold">
                    <td colspan="3" class="px-4 py-3 text-right" style="color: var(--text-primary);">Total ({{ $invoice->currency }})</td>
                    <td class="px-4 py-3 text-right text-base" style="color: var(--text-primary);">{{ number_format((float)$invoice->total, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if ($invoice->notes)
    <div class="rounded-xl border p-5"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <p class="text-xs font-semibold uppercase tracking-wide mb-2" style="color: var(--text-muted);">Notes</p>
        <p class="text-sm whitespace-pre-line" style="color: var(--text-secondary);">{{ $invoice->notes }}</p>
    </div>
    @endif
</div>
@endsection
