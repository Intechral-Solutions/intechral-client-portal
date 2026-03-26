@extends('layouts.app', ['title' => $contact->fullName()])

@section('content')
<div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-start justify-between">
        <div>
            <a href="{{ route('crm.contacts.index') }}" class="text-sm hover:underline" style="color: var(--text-secondary);">&larr; Contacts</a>
            <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">{{ $contact->fullName() }}</h1>
            @if ($contact->job_title)
            <p class="text-sm mt-0.5" style="color: var(--text-secondary);">{{ $contact->job_title }}</p>
            @endif
        </div>
        <a href="{{ route('crm.contacts.edit', $contact) }}"
           class="rounded-lg border px-4 py-2 text-sm font-medium"
           style="border-color: var(--border-base); color: var(--text-secondary);">Edit</a>
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
            <a href="{{ route('crm.companies.show', $contact->company) }}" class="text-sm font-medium hover:underline" style="color: var(--accent);">
                {{ $contact->company->name }}
            </a>
        </div>
        @endif

        @if ($contact->email)
        <div>
            <p class="text-xs font-medium mb-0.5" style="color: var(--text-muted);">Email</p>
            <a href="mailto:{{ $contact->email }}" class="text-sm hover:underline" style="color: var(--text-primary);">{{ $contact->email }}</a>
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
