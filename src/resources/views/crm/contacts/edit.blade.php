@extends('layouts.app', ['title' => 'Edit Contact'])

@section('content')
<div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8">
        <x-ui.link variant="quiet" :href="route('crm.contacts.show', $contact)" class="text-sm">&larr; {{ $contact->fullName() }}</x-ui.link>
        <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">Edit Contact</h1>
    </div>

    <div class="rounded-xl border p-6" style="background-color: var(--surface-card); border-color: var(--border-base);">
        <form method="POST" action="{{ route('crm.contacts.update', $contact) }}" class="space-y-4">
            @csrf @method('PUT')
            @include('crm.contacts._form', ['companyId' => null])
            <div class="flex gap-3 pt-2">
                <x-ui.button type="submit">Save Changes</x-ui.button>
                <x-ui.button :href="route('crm.contacts.show', $contact)" variant="secondary">Cancel</x-ui.button>
            </div>
        </form>
    </div>

    <div class="mt-8 rounded-xl border border-danger p-5" style="background-color: var(--surface-card);">
        <h2 class="mb-2 text-sm font-semibold" style="color: var(--text-danger);">Delete Contact</h2>
        <form method="POST" action="{{ route('crm.contacts.destroy', $contact) }}"
              onsubmit="return confirm('Delete {{ addslashes($contact->fullName()) }}?')">
            @csrf @method('DELETE')
            <x-ui.button type="submit" variant="secondary" tone="danger" size="sm">Delete Contact</x-ui.button>
        </form>
    </div>
</div>
@endsection
