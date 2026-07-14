@extends('layouts.app', ['title' => 'Edit Contact'])

@section('content')
<div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8">
        <a href="{{ route('crm.contacts.show', $contact) }}" class="text-sm hover:underline" style="color: var(--text-secondary);">&larr; {{ $contact->fullName() }}</a>
        <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">Edit Contact</h1>
    </div>

    <div class="rounded-xl border p-6" style="background-color: var(--surface-card); border-color: var(--border-base);">
        <form method="POST" action="{{ route('crm.contacts.update', $contact) }}" class="space-y-4">
            @csrf @method('PUT')
            @include('crm.contacts._form', ['companyId' => null])
            <div class="flex gap-3 pt-2">
                <button type="submit" class="rounded-lg px-5 py-2 text-sm font-medium"
                        style="background-color: var(--accent); color: #fff;">Save Changes</button>
                <a href="{{ route('crm.contacts.show', $contact) }}" class="rounded-lg border px-5 py-2 text-sm font-medium"
                   style="border-color: var(--border-base); color: var(--text-secondary);">Cancel</a>
            </div>
        </form>
    </div>

    <div class="mt-8 rounded-xl border p-5" style="border-color: var(--border-danger); background-color: var(--surface-card);">
        <h2 class="mb-2 text-sm font-semibold" style="color: var(--text-danger);">Delete Contact</h2>
        <form method="POST" action="{{ route('crm.contacts.destroy', $contact) }}"
              onsubmit="return confirm('Delete {{ addslashes($contact->fullName()) }}?')">
            @csrf @method('DELETE')
            <button type="submit" class="rounded-lg px-4 py-2 text-xs font-medium"
                    style="background-color: var(--surface-danger); color: var(--text-danger);">Delete Contact</button>
        </form>
    </div>
</div>
@endsection
