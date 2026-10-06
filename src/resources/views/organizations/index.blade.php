@extends('layouts.app', ['title' => 'Organizations'])

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">Organizations</h1>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">Client organizations promoted from the CRM.</p>
        </div>
        <x-ui.button :href="route('crm.companies.index')" variant="secondary">View Companies</x-ui.button>
    </div>

    @if (session('success'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    <div class="rounded-xl border overflow-hidden" style="background-color: var(--surface-card); border-color: var(--border-base);">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b" style="border-color: var(--border-base);">
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Slug</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Owner</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Members</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y" style="divide-color: var(--border-base);">
                @forelse ($organizations as $org)
                <tr>
                    <td class="px-4 py-3 font-medium">
                        <x-ui.link variant="row" :href="route('organizations.show', $org)">
                            {{ $org->name }}
                        </x-ui.link>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs" style="color: var(--text-muted);">{{ $org->slug }}</td>
                    <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $org->owner->name }}</td>
                    <td class="px-4 py-3 text-right" style="color: var(--text-secondary);">{{ $org->members_count }}</td>
                    <td class="px-4 py-3 text-right">
                        <x-ui.link variant="quiet" :href="route('organizations.show', $org)" class="text-xs">Manage</x-ui.link>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                        No organizations yet.
                        <x-ui.link :href="route('crm.companies.index')">Promote a company</x-ui.link>
                        to get started.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $organizations->links() }}</div>
</div>
@endsection
