@extends('layouts.app', ['title' => $contact->fullName()])

@section('content')
<div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-start justify-between">
        <div>
            <x-ui.link variant="quiet" :href="route('crm.contacts.index')" class="text-sm">&larr; Contacts</x-ui.link>
            <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">{{ $contact->fullName() }}</h1>
            @if ($contact->job_title)
            <p class="text-sm mt-0.5" style="color: var(--text-secondary);">{{ $contact->job_title }}</p>
            @endif
        </div>
        <x-ui.button :href="route('crm.contacts.edit', $contact)" variant="secondary">Edit</x-ui.button>
    </div>

    @if (session('success'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    <div class="rounded-xl border p-5 space-y-4" style="background-color: var(--surface-card); border-color: var(--border-base);">
        @if ($contact->company)
        <div>
            <p class="text-xs font-medium mb-0.5" style="color: var(--text-muted);">Company</p>
            <x-ui.link :href="route('crm.companies.show', $contact->company)" class="text-sm font-medium">
                {{ $contact->company->name }}
            </x-ui.link>
        </div>
        @endif

        @if ($contact->email)
        <div>
            <p class="text-xs font-medium mb-0.5" style="color: var(--text-muted);">Email</p>
            <x-ui.link variant="row" href="mailto:{{ $contact->email }}" class="text-sm">{{ $contact->email }}</x-ui.link>
        </div>
        @endif

        @if ($contact->phone)
        <div>
            <p class="text-xs font-medium mb-0.5" style="color: var(--text-muted);">Phone</p>
            <p class="text-sm" style="color: var(--text-primary);">{{ $contact->phone }}</p>
        </div>
        @endif

        @if ($contact->notes)
        <div>
            <p class="text-xs font-medium mb-0.5" style="color: var(--text-muted);">Notes</p>
            <p class="text-sm whitespace-pre-line" style="color: var(--text-secondary);">{{ $contact->notes }}</p>
        </div>
        @endif
    </div>
</div>
@endsection
