@extends('layouts.app', ['title' => $invoice->invoice_number])

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">

    {{-- Breadcrumb + Actions --}}
    <div class="mb-6 flex items-start justify-between">
        <div>
            <x-ui.link variant="quiet" :href="route('billing.invoices.index')" class="text-sm">Invoices</x-ui.link>
            <h1 class="mt-1 text-2xl font-semibold font-mono" style="color: var(--text-primary);">{{ $invoice->invoice_number }}</h1>
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
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- Invoice --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Header card --}}
            <div class="rounded-xl border p-6"
                 style="background-color: var(--surface-card); border-color: var(--border-base);">
                <div class="flex items-start justify-between mb-6">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide mb-1" style="color: var(--text-muted);">Billed to</p>
                        <p class="font-semibold" style="color: var(--text-primary);">{{ $invoice->client->name }}</p>
                        <p class="text-sm" style="color: var(--text-secondary);">{{ $invoice->client->email }}</p>
                    </div>
                    @php
                        $statusStyles = [
                            'draft'     => ['bg' => 'var(--surface-muted)',   'text' => 'var(--text-muted)',   'border' => 'var(--border-muted)'],
                            'sent'      => ['bg' => 'var(--surface-info)',    'text' => 'var(--text-info)',    'border' => 'var(--border-info)'],
                            'paid'      => ['bg' => 'var(--surface-success)', 'text' => 'var(--text-success)', 'border' => 'var(--border-success)'],
                            'overdue'   => ['bg' => 'var(--surface-danger)',  'text' => 'var(--text-danger)',  'border' => 'var(--border-danger)'],
                            'cancelled' => ['bg' => 'var(--surface-muted)',   'text' => 'var(--text-muted)',   'border' => 'var(--border-muted)'],
                        ];
                        $ss = $statusStyles[$invoice->status] ?? $statusStyles['draft'];
                    @endphp
                    <span class="rounded-full border px-3 py-1 text-sm font-medium capitalize"
                          style="background-color: {{ $ss['bg'] }}; color: {{ $ss['text'] }}; border-color: {{ $ss['border'] }};">
                        {{ $invoice->status }}
                    </span>
                </div>

                <div class="grid grid-cols-3 gap-4 text-sm border-t pt-4" style="border-color: var(--border-base);">
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
                    @if ($invoice->project)
                    <div>
                        <p class="text-xs" style="color: var(--text-muted);">Project</p>
                        <p style="color: var(--text-primary);">{{ $invoice->project->name }}</p>
                    </div>
                    @endif
                </div>
            </div>

            {{-- Line items --}}
            <div class="rounded-xl border overflow-hidden"
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

        {{-- Sidebar --}}
        <div class="space-y-4">

            {{-- Payment status --}}
            <div class="rounded-xl border p-5"
                 style="background-color: var(--surface-card); border-color: var(--border-base);">
                <p class="text-xs font-semibold uppercase tracking-wide mb-3" style="color: var(--text-muted);">Payment</p>

                @if ($invoice->isPaid())
                <p class="text-sm font-medium mb-1" style="color: var(--text-success);">&#10003; Paid {{ $invoice->paid_at->format('M j, Y') }}</p>
                @elseif ($invoice->isPayable())
                <p class="text-sm mb-3" style="color: var(--text-secondary);">
                    Amount due: <span class="font-semibold" style="color: var(--text-primary);">{{ $invoice->currency }} {{ number_format((float)$invoice->total, 2) }}</span>
                </p>
                @can('pay', $invoice)
                <x-ui.button :href="route('billing.invoices.pay', $invoice)" variant="secondary" class="w-full">Pay Now</x-ui.button>
                @endcan
                @else
                <p class="text-sm" style="color: var(--text-muted);">No payment due.</p>
                @endif
            </div>

            {{-- Payment history --}}
            @if ($invoice->payments->isNotEmpty())
            <div class="rounded-xl border p-5"
                 style="background-color: var(--surface-card); border-color: var(--border-base);">
                <p class="text-xs font-semibold uppercase tracking-wide mb-3" style="color: var(--text-muted);">Payment History</p>
                @foreach ($invoice->payments as $payment)
                <div class="flex justify-between text-sm mb-2">
                    <span style="color: var(--text-secondary);">{{ $payment->paid_at->format('M j, Y') }} &mdash; {{ ucfirst($payment->method) }}</span>
                    <span class="font-medium" style="color: var(--text-success);">+{{ number_format((float)$payment->amount, 2) }}</span>
                </div>
                @endforeach
            </div>
            @endif

            {{-- Record manual payment --}}
            @can('recordPayment', \App\Models\Invoice::class)
            @if (! $invoice->isPaid())
            <div class="rounded-xl border p-5"
                 style="background-color: var(--surface-card); border-color: var(--border-base);">
                <p class="text-xs font-semibold uppercase tracking-wide mb-3" style="color: var(--text-muted);">Record Manual Payment</p>
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
