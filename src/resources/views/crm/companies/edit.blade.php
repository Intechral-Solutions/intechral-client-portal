@extends('layouts.app', ['title' => 'Edit Company'])

@section('content')
<div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8">
        <x-ui.link variant="quiet" :href="route('crm.companies.show', $company)" class="text-sm">&larr; {{ $company->name }}</x-ui.link>
        <h1 class="mt-2 text-2xl font-semibold text-text">Edit Company</h1>
    </div>

    <div class="rounded-lg border p-6 bg-surface border-rule">
        <form method="POST" action="{{ route('crm.companies.update', $company) }}" class="space-y-4">
            @csrf @method('PUT')
            @include('crm.companies._form')
            <div class="flex gap-3 pt-2">
                <x-ui.button type="submit">Save Changes</x-ui.button>
                <x-ui.button :href="route('crm.companies.show', $company)" variant="secondary">Cancel</x-ui.button>
            </div>
        </form>
    </div>

    {{-- Danger zone --}}
    <div class="mt-8 rounded-lg border border-danger p-5 bg-surface">
        <h2 class="mb-2 text-sm font-semibold text-danger">Delete Company</h2>
        <p class="mb-4 text-xs text-text-muted">This will permanently remove the company and all its contacts.</p>
        <form method="POST" action="{{ route('crm.companies.destroy', $company) }}"
              onsubmit="return confirm('Delete {{ addslashes($company->name) }}? This cannot be undone.')">
            @csrf @method('DELETE')
            <x-ui.button type="submit" variant="secondary" tone="danger" size="sm">Delete Company</x-ui.button>
        </form>
    </div>
</div>
@endsection
