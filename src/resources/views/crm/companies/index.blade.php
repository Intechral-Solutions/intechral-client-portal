@extends('layouts.app', ['title' => 'Companies'])

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">Companies</h1>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">CRM company records.</p>
        </div>
        <x-ui.button :href="route('crm.companies.create')">+ New Company</x-ui.button>
    </div>

    @if (session('success'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    {{-- Search --}}
    <form method="GET" class="mb-6 flex gap-2">
        <x-ui.input type="text" name="search" :value="$search" placeholder="Search by name…" class="w-64" />
        <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
        @if ($search)
        <x-ui.button :href="route('crm.companies.index')" variant="secondary">Clear</x-ui.button>
        @endif
    </form>

    <div class="rounded-xl border overflow-hidden" style="background-color: var(--surface-card); border-color: var(--border-base);">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b" style="border-color: var(--border-base);">
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Website</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Phone</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Org</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y" style="divide-color: var(--border-base);">
                @forelse ($companies as $company)
                <tr>
                    <td class="px-4 py-3 font-medium">
                        <x-ui.link variant="row" :href="route('crm.companies.show', $company)">
                            {{ $company->name }}
                        </x-ui.link>
                    </td>
                    <td class="px-4 py-3" style="color: var(--text-secondary);">
                        {{ $company->website ? parse_url($company->website, PHP_URL_HOST) : '—' }}
                    </td>
                    <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $company->phone ?? '—' }}</td>
                    <td class="px-4 py-3 text-center">
                        @if ($company->isPromoted())
                        <x-ui.link :href="route('organizations.show', $company->organization)" class="text-xs font-medium">Org ↗</x-ui.link>
                        @else
                        <span class="text-xs" style="color: var(--text-muted);">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <x-ui.link variant="quiet" :href="route('crm.companies.edit', $company)" class="text-xs">Edit</x-ui.link>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No companies found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $companies->links() }}</div>
</div>
@endsection
