@extends('layouts.app', ['title' => 'Contacts'])

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-text">Contacts</h1>
            <p class="mt-1 text-sm text-text-secondary">CRM contact records.</p>
        </div>
        <x-ui.button :href="route('crm.contacts.create')">+ New Contact</x-ui.button>
    </div>

    @if (session('success'))
    <x-ui.alert variant="success" class="mb-6">{{ session('success') }}</x-ui.alert>
    @endif

    {{-- Search --}}
    <form method="GET" class="mb-6 flex gap-2">
        <x-ui.input type="text" name="search" :value="$search" placeholder="Search by name or email…" class="w-64" />
        <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
        @if ($search)
        <x-ui.button :href="route('crm.contacts.index')" variant="secondary">Clear</x-ui.button>
        @endif
    </form>

    <div class="rounded-lg border overflow-hidden bg-surface border-rule">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-rule-control">
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Company</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Email</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Phone</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-rule">
                @forelse ($contacts as $contact)
                <tr>
                    <td class="px-4 py-3 font-medium">
                        <x-ui.link variant="row" :href="route('crm.contacts.show', $contact)">
                            {{ $contact->fullName() }}
                        </x-ui.link>
                        @if ($contact->job_title)
                        <span class="text-xs block text-text-muted">{{ $contact->job_title }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-text-secondary">
                        @if ($contact->company)
                        <x-ui.link variant="quiet" :href="route('crm.companies.show', $contact->company)">
                            {{ $contact->company->name }}
                        </x-ui.link>
                        @else —
                        @endif
                    </td>
                    <td class="px-4 py-3 text-text-secondary">{{ $contact->email ?? '—' }}</td>
                    <td class="px-4 py-3 text-text-secondary">{{ $contact->phone ?? '—' }}</td>
                    <td class="px-4 py-3 text-right">
                        <x-ui.link variant="quiet" :href="route('crm.contacts.edit', $contact)" class="text-xs">Edit</x-ui.link>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-12 text-center text-sm text-text-muted">No contacts found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $contacts->links() }}</div>
</div>
@endsection
