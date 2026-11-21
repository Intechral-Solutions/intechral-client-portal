@use('App\Services\OrganizationListFacts')
@extends('layouts.app', ['title' => 'Organizations'])

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-text">Organizations</h1>
            <p class="mt-1 text-sm text-text-secondary">Organizations in the Directory. Portal access is enabled per Organization.</p>
        </div>
        <x-ui.button :href="route('crm.companies.create')" class="shrink-0">+ New Organization</x-ui.button>
    </div>

    @if (session('success'))
    <x-ui.alert variant="success" class="mb-6">{{ session('success') }}</x-ui.alert>
    @endif

    {{-- Search --}}
    <form method="GET" class="mb-6 flex gap-2">
        <x-ui.input type="text" name="search" :value="$search" placeholder="Search by name…" class="w-64" />
        <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
        @if ($search)
        <x-ui.button :href="route('crm.companies.index')" variant="secondary">Clear</x-ui.button>
        @endif
    </form>

    {{-- A horizontally scrollable region (DIR-1): the table is wider than a phone, so the card scrolls inside itself, never the page.
         It is a named, focusable region so a keyboard user can reach and scroll it, with the shared Direction D focus ring. --}}
    <div role="region" tabindex="0" aria-label="Organizations list" class="overflow-x-auto rounded-lg border border-rule bg-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-rule-control">
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Website</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Phone</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Portal access</th>
                    @if ($relationships !== null)
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Managed by</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-text-muted">Manages</th>
                    @endif
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-rule">
                @forelse ($companies as $company)
                <tr>
                    <td class="px-4 py-3 font-medium">
                        <x-ui.link variant="row" :href="route('crm.companies.show', $company)">
                            {{ $company->name }}
                        </x-ui.link>
                    </td>
                    <td class="px-4 py-3 text-text-secondary">
                        {{ $company->website ? parse_url($company->website, PHP_URL_HOST) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-text-secondary">{{ $company->phone ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if (! $company->portalState()->isNotEnabled())
                        @php($state = $portalStatus[$company->id] ?? null)
                        <span class="inline-flex items-center gap-2">
                            @if ($state === OrganizationListFacts::DISABLED)
                            <x-ui.status tone="warning">Disabled</x-ui.status>
                            @else
                            <x-ui.status tone="success">Enabled</x-ui.status>
                            @endif
                            <x-ui.link :href="route('organizations.show', $company)" class="text-xs font-medium" aria-label="Manage portal access for {{ $company->name }}">Manage</x-ui.link>
                        </span>
                        @else
                        <x-ui.status glyph="dashed">Not enabled</x-ui.status>
                        @endif
                    </td>
                    @if ($relationships !== null)
                    {{-- Built only for an actor who may manage relationships (DIR-0 S7): $relationships is null otherwise and this never renders. --}}
                    @php($fact = ! $company->portalState()->isNotEnabled() ? ($relationships[$company->id] ?? null) : null)
                    <td class="px-4 py-3 text-text-secondary">{{ $fact['managedBy']['name'] ?? '—' }}</td>
                    <td class="px-4 py-3 text-right text-text-secondary">{{ $fact !== null && $fact['isPartner'] ? $fact['manages'] : '—' }}</td>
                    @endif
                    <td class="px-4 py-3 text-right">
                        <x-ui.link variant="quiet" :href="route('crm.companies.edit', $company)" class="text-xs">Edit</x-ui.link>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $relationships !== null ? 7 : 5 }}" class="px-4 py-12 text-center text-sm text-text-muted">No organizations found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $companies->links() }}</div>
</div>
@endsection
