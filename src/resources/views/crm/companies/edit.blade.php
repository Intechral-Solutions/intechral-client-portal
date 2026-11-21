@extends('layouts.app', ['title' => 'Edit Organization'])

@section('content')
<div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8">
        <x-ui.link variant="quiet" :href="route('crm.companies.show', $company)" class="text-sm">&larr; {{ $company->name }}</x-ui.link>
        <h1 class="mt-2 text-2xl font-semibold text-text">Edit Organization</h1>
    </div>

    @error('company')
    <x-ui.alert variant="danger" class="mb-6">{{ $message }}</x-ui.alert>
    @enderror

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
        <h2 class="mb-2 text-sm font-semibold text-danger">Delete Organization</h2>
        {{-- DIR-1: what the existing delete does, no more. It removes the Directory record only; its People are kept and lose their affiliation.
             The refusals are made by OrganizationDirectory::delete. --}}
        <p class="mb-2 text-xs text-text-muted">This permanently removes this Organization's Directory record. Its People are kept: they stay in the Directory and lose their affiliation with it.</p>
        <p class="mb-4 text-xs text-text-muted">Only an Organization that never had portal access can be deleted, and not while projects are linked to it. Those checks are made when you confirm.</p>
        <form method="POST" action="{{ route('crm.companies.destroy', $company) }}"
              onsubmit="return confirm('Delete {{ addslashes($company->name) }}? This cannot be undone.')">
            @csrf @method('DELETE')
            <x-ui.button type="submit" variant="secondary" tone="danger" size="sm">Delete Organization</x-ui.button>
        </form>
    </div>
</div>
@endsection
