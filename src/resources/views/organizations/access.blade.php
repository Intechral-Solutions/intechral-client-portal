@extends('layouts.app', ['title' => 'Organization access'])

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-text">Organization access</h1>
            <p class="mt-1 text-sm text-text-secondary">Organizations with portal access enabled: their members, invitations and administration. A disabled Organization stays listed; one that has never had portal access is in the Directory only.</p>
        </div>
        <x-ui.button :href="route('organizations.index')" variant="secondary" class="shrink-0">View Organizations</x-ui.button>
    </div>

    @if (session('success'))
    <x-ui.alert variant="success" class="mb-6">{{ session('success') }}</x-ui.alert>
    @endif

    {{-- ORG-3b: every row opens the Portal access page of the Organization workspace (Directory → Organizations); this page is a filtered list and never an editor.
         A horizontally scrollable region (DIR-1): the table is wider than a phone, so the card scrolls inside itself, never the page.
         It is a named, focusable region so a keyboard user can reach and scroll it, with the shared Direction D focus ring. --}}
    <div role="region" tabindex="0" aria-label="Organizations with portal access" class="overflow-x-auto rounded-lg border border-rule bg-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-rule-control">
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Slug</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Portal access</th>
                    {{-- The operator who enabled portal access. Provenance only: it confers no ownership and no authority. --}}
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Enabled by</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-text-muted">Members</th>
                    @if ($relationships !== null)
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-muted">Managed by</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-text-muted">Manages</th>
                    @endif
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-rule">
                @forelse ($organizations as $org)
                <tr>
                    <td class="px-4 py-3 font-medium">
                        <x-ui.link variant="row" :href="route('organizations.portal', $org)">
                            {{ $org->name }}
                        </x-ui.link>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-text-muted">{{ $org->slug }}</td>
                    <td class="px-4 py-3">
                        <x-organization.portal-state :state="$org->portalState()" />
                    </td>
                    <td class="px-4 py-3 text-text-secondary">{{ $org->portalEnabledBy?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-right text-text-secondary">{{ $org->members_count }}</td>
                    @if ($relationships !== null)
                    {{-- Built only for an actor who may manage relationships (DIR-0 S7): $relationships is null otherwise and this never renders. --}}
                    @php($fact = $relationships[$org->id] ?? null)
                    <td class="px-4 py-3 text-text-secondary">{{ $fact['managedBy']['name'] ?? '—' }}</td>
                    <td class="px-4 py-3 text-right text-text-secondary">{{ $fact !== null && $fact['isPartner'] ? $fact['manages'] : '—' }}</td>
                    @endif
                    <td class="px-4 py-3 text-right">
                        <x-ui.link variant="quiet" :href="route('organizations.portal', $org)" class="text-xs" aria-label="Manage {{ $org->name }}">Manage</x-ui.link>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $relationships !== null ? 8 : 6 }}" class="px-4 py-12 text-center text-sm text-text-muted">
                        No organization has portal access yet.
                        <x-ui.link :href="route('organizations.index')">Open an Organization in the Directory</x-ui.link>
                        and enable portal access to get started.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $organizations->links() }}</div>
</div>
@endsection
