@extends('layouts.app', ['title' => 'New Invoice'])

@section('content')
<div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-6">
        <a href="{{ route('billing.invoices.index') }}" class="inline-flex items-center gap-1 text-sm" style="color: var(--text-secondary);">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
            </svg>
            Invoices
        </a>
        <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">New Invoice</h1>
    </div>

    <form method="POST" action="{{ route('billing.invoices.store') }}" class="space-y-6">
        @csrf

        @include('billing.invoices._form', ['clients' => $clients, 'projects' => $projects])

        <div class="flex justify-end gap-3">
            <a href="{{ route('billing.invoices.index') }}"
               class="rounded-lg border px-4 py-2 text-sm font-medium"
               style="border-color: var(--border-base); color: var(--text-secondary);">Cancel</a>
            <button type="submit"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    style="background-color: var(--accent); color: #fff;">Create Invoice</button>
        </div>
    </form>
</div>
@endsection
