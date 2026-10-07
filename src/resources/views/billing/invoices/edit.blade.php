@extends('layouts.app', ['title' => 'Edit ' . $invoice->invoice_number])

@section('content')
<div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-6">
        <x-ui.link variant="quiet" :href="route('billing.invoices.show', $invoice)" class="inline-flex items-center gap-1 text-sm">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
            </svg>
            {{ $invoice->invoice_number }}
        </x-ui.link>
        <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">Edit Invoice</h1>
    </div>

    @if (session('success'))
    <x-ui.alert variant="success" class="mb-6">{{ session('success') }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('billing.invoices.update', $invoice) }}" class="space-y-6">
        @csrf @method('PUT')

        @include('billing.invoices._form', ['clients' => $clients, 'projects' => $projects, 'invoice' => $invoice])

        <div class="flex justify-end gap-3">
            <x-ui.button :href="route('billing.invoices.show', $invoice)" variant="secondary">Cancel</x-ui.button>
            <x-ui.button type="submit">Save Changes</x-ui.button>
        </div>
    </form>
</div>
@endsection
