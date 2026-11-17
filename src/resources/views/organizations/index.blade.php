@use('App\Services\OrganizationListFacts')
@extends('layouts.app', ['title' => 'Organization access'])

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-text">Organization access</h1>
            <p class="mt-1 text-sm text-text-secondary">Organizations with portal access enabled: their members, invitations and administration.</p>
        </div>
        <x-ui.button :href="route('crm.companies.index')" variant="secondary" class="shrink-0">View Organizations</x-ui.button>
    </div>

    @if (session('success'))
    <x-ui.alert variant="success" class="mb-6">{{ session('success') }}</x-ui.alert>
    @endif

    <div class="rounded-lg border overflow-x-auto bg-surface border-rule">
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
                        <x-ui.link variant="row" :href="route('organizations.show', $org)">
                            {{ $org->name }}
                        </x-ui.link>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-text-muted">{{ $org->slug }}</td>
                    <td class="px-4 py-3">
                        @if (($portalStatus[$org->id] ?? null) === OrganizationListFacts::DISABLED)
                        <x-ui.status tone="warning">Disabled</x-ui.status>
                        @else
                        <x-ui.status tone="success">Enabled</x-ui.status>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-text-secondary">{{ $org->owner?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-right text-text-secondary">{{ $org->members_count }}</td>
                    @if ($relationships !== null)
                    {{-- Built only for an actor who may manage relationships (DIR-0 S7): $relationships is null otherwise and this never renders. --}}
                    @php($fact = $relationships[$org->id] ?? null)
                    <td class="px-4 py-3 text-text-secondary">{{ $fact['managedBy']['name'] ?? '—' }}</td>
                    <td class="px-4 py-3 text-right text-text-secondary">{{ $fact !== null && $fact['isPartner'] ? $fact['manages'] : '—' }}</td>
                    @endif
                    <td class="px-4 py-3 text-right">
                        <x-ui.link variant="quiet" :href="route('organizations.show', $org)" class="text-xs" aria-label="Manage {{ $org->name }}">Manage</x-ui.link>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $relationships !== null ? 8 : 6 }}" class="px-4 py-12 text-center text-sm text-text-muted">
                        No organization has portal access yet.
                        <x-ui.link :href="route('crm.companies.index')">Open an Organization in the Directory</x-ui.link>
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
