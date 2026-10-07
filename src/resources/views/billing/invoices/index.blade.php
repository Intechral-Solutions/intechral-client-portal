@extends('layouts.app', ['title' => 'Invoices'])

@section('content')
<div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">Invoices</h1>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">Manage client invoices and payments.</p>
        </div>
        @can('create', \App\Models\Invoice::class)
        <x-ui.button :href="route('billing.invoices.create')" class="shrink-0">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
            </svg>
            New Invoice
        </x-ui.button>
        @endcan
    </div>

    @if (session('success'))
    <x-ui.alert variant="success" class="mb-6">{{ session('success') }}</x-ui.alert>
    @endif

    {{-- Filters --}}
    <form method="GET" class="mb-6 flex flex-wrap gap-3">
        <x-ui.input type="search" name="search" :value="request('search')" placeholder="Search invoices or clients…"
                    class="min-w-48 flex-1" />
        <x-ui.select name="status" aria-label="Status">
            <option value="">All statuses</option>
            @foreach (['draft', 'sent', 'paid', 'overdue', 'cancelled'] as $s)
            <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.button type="submit" variant="secondary">Filter</x-ui.button>
    </form>

    <div class="rounded-xl border overflow-hidden"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b" style="border-color: var(--border-base);">
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Invoice #</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Client</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Issued</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Due</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Total</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y" style="divide-color: var(--border-base);">
                @forelse ($invoices as $invoice)
                <tr class="hover:opacity-90 transition-opacity">
                    <td class="px-4 py-3">
                        <x-ui.link variant="row" :href="route('billing.invoices.show', $invoice)" class="font-mono">{{ $invoice->invoice_number }}</x-ui.link>
                    </td>
                    <td class="px-4 py-3" style="color: var(--text-primary);">{{ $invoice->client->name }}</td>
                    <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $invoice->issued_at->format('M j, Y') }}</td>
                    <td class="px-4 py-3 {{ $invoice->isOverdue() ? 'font-medium text-danger' : '' }}"
                        @unless ($invoice->isOverdue()) style="color: var(--text-secondary);" @endunless>
                        {{ $invoice->due_at->format('M j, Y') }}
                    </td>
                    <td class="px-4 py-3 text-right font-medium {{ $invoice->status === 'cancelled' ? 'line-through' : '' }}" style="color: var(--text-primary);">
                        {{ $invoice->currency }} {{ number_format((float) $invoice->total, 2) }}
                    </td>
                    <td class="px-4 py-3 text-center">
                        @include('billing._invoice_status', ['status' => $invoice->status])
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No invoices found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $invoices->links() }}</div>
</div>
@endsection
