@extends('layouts.app', ['title' => 'Contacts'])

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">Contacts</h1>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">CRM contact records.</p>
        </div>
        <a href="{{ route('crm.contacts.create') }}"
           class="rounded-lg px-4 py-2 text-sm font-medium"
           style="background-color: var(--accent); color: #fff;">+ New Contact</a>
    </div>

    @if (session('success'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    {{-- Search --}}
    <form method="GET" class="mb-6 flex gap-2">
        <input type="text" name="search" value="{{ $search }}" placeholder="Search by name or email…"
               class="w-64 rounded-lg border px-3 py-2 text-sm outline-none"
               style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
        <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium"
                style="background-color: var(--accent); color: #fff;">Search</button>
        @if ($search)
        <a href="{{ route('crm.contacts.index') }}" class="rounded-lg border px-4 py-2 text-sm"
           style="border-color: var(--border-base); color: var(--text-secondary);">Clear</a>
        @endif
    </form>

    <div class="rounded-xl border overflow-hidden" style="background-color: var(--surface-card); border-color: var(--border-base);">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b" style="border-color: var(--border-base);">
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Company</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Email</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Phone</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y" style="divide-color: var(--border-base);">
                @forelse ($contacts as $contact)
                <tr>
                    <td class="px-4 py-3 font-medium">
                        <a href="{{ route('crm.contacts.show', $contact) }}" class="hover:underline" style="color: var(--accent);">
                            {{ $contact->fullName() }}
                        </a>
                        @if ($contact->job_title)
                        <span class="text-xs block" style="color: var(--text-muted);">{{ $contact->job_title }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3" style="color: var(--text-secondary);">
                        @if ($contact->company)
                        <a href="{{ route('crm.companies.show', $contact->company) }}" class="hover:underline" style="color: var(--text-secondary);">
                            {{ $contact->company->name }}
                        </a>
                        @else —
                        @endif
                    </td>
                    <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $contact->email ?? '—' }}</td>
                    <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $contact->phone ?? '—' }}</td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('crm.contacts.edit', $contact) }}"
                           class="text-xs hover:underline" style="color: var(--text-secondary);">Edit</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No contacts found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $contacts->links() }}</div>
</div>
@endsection
