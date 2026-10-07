@extends('layouts.app', ['title' => $company->name])

@section('content')
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-start justify-between">
        <div>
            <x-ui.link variant="quiet" :href="route('crm.companies.index')" class="text-sm">&larr; Companies</x-ui.link>
            <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">{{ $company->name }}</h1>
            @if ($company->website)
            <x-ui.link :href="$company->website" target="_blank" rel="noopener" class="mt-0.5 text-sm">
                {{ parse_url($company->website, PHP_URL_HOST) }} ↗
            </x-ui.link>
            @endif
        </div>
        <div class="flex gap-2">
            @if (! $company->isPromoted())
            <form method="POST" action="{{ route('crm.companies.promote', $company) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary"
                             onclick="return confirm('Promote {{ addslashes($company->name) }} to an Organization?')">
                    Promote to Org
                </x-ui.button>
            </form>
            @else
            <x-ui.button :href="route('organizations.show', $company->organization)" variant="secondary">View Organization ↗</x-ui.button>
            @endif
            <x-ui.button :href="route('crm.companies.edit', $company)" variant="secondary">Edit</x-ui.button>
        </div>
    </div>

    @if (session('success'))
    <x-ui.alert variant="success" class="mb-6">{{ session('success') }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Details --}}
        <div class="lg:col-span-1 rounded-xl border p-5 space-y-3" style="background-color: var(--surface-card); border-color: var(--border-base);">
            <h2 class="text-xs font-semibold uppercase tracking-wide mb-2" style="color: var(--text-muted);">Details</h2>
            @if ($company->phone)
            <div><p class="text-xs" style="color: var(--text-muted);">Phone</p><p class="text-sm" style="color: var(--text-primary);">{{ $company->phone }}</p></div>
            @endif
            @if ($company->address)
            <div><p class="text-xs" style="color: var(--text-muted);">Address</p><p class="text-sm whitespace-pre-line" style="color: var(--text-primary);">{{ $company->address }}</p></div>
            @endif
            @if ($company->notes)
            <div><p class="text-xs" style="color: var(--text-muted);">Notes</p><p class="text-sm whitespace-pre-line" style="color: var(--text-secondary);">{{ $company->notes }}</p></div>
            @endif
        </div>

        {{-- Contacts --}}
        <div class="lg:col-span-2 rounded-xl border overflow-hidden" style="background-color: var(--surface-card); border-color: var(--border-base);">
            <div class="flex items-center justify-between px-5 py-4 border-b" style="border-color: var(--border-base);">
                <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Contacts</h2>
                <x-ui.link :href="route('crm.contacts.create', ['company_id' => $company->id])" class="text-xs font-medium">+ Add Contact</x-ui.link>
            </div>
            <table class="w-full text-sm">
                <tbody class="divide-y" style="divide-color: var(--border-base);">
                    @forelse ($company->contacts as $contact)
                    <tr>
                        <td class="px-5 py-3">
                            <x-ui.link variant="row" :href="route('crm.contacts.show', $contact)">
                                {{ $contact->fullName() }}
                            </x-ui.link>
                            @if ($contact->job_title)
                            <span class="text-xs ml-1" style="color: var(--text-muted);">— {{ $contact->job_title }}</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-xs" style="color: var(--text-secondary);">{{ $contact->email ?? '' }}</td>
                    </tr>
                    @empty
                    <tr><td class="px-5 py-8 text-center text-sm" style="color: var(--text-muted);">No contacts yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
