@extends('layouts.app', ['title' => 'Companies'])

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">Companies</h1>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">CRM company records.</p>
        </div>
        <a href="{{ route('crm.companies.create') }}"
           class="rounded-lg px-4 py-2 text-sm font-medium"
           style="background-color: var(--accent); color: #fff;">+ New Company</a>
    </div>

    @if (session('success'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    {{-- Search --}}
    <form method="GET" class="mb-6 flex gap-2">
        <input type="text" name="search" value="{{ $search }}" placeholder="Search by name…"
               class="w-64 rounded-lg border px-3 py-2 text-sm outline-none"
               style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
        <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium"
                style="background-color: var(--accent); color: #fff;">Search</button>
        @if ($search)
        <a href="{{ route('crm.companies.index') }}" class="rounded-lg border px-4 py-2 text-sm"
           style="border-color: var(--border-base); color: var(--text-secondary);">Clear</a>
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
                        <a href="{{ route('crm.companies.show', $company) }}" class="hover:underline" style="color: var(--accent);">
                            {{ $company->name }}
                        </a>
                    </td>
                    <td class="px-4 py-3" style="color: var(--text-secondary);">
                        {{ $company->website ? parse_url($company->website, PHP_URL_HOST) : '—' }}
                    </td>
                    <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $company->phone ?? '—' }}</td>
                    <td class="px-4 py-3 text-center">
                        @if ($company->isPromoted())
                        <a href="{{ route('organizations.show', $company->organization) }}"
                           class="text-xs font-medium hover:underline" style="color: var(--text-success);">Org ↗</a>
                        @else
                        <span class="text-xs" style="color: var(--text-muted);">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('crm.companies.edit', $company) }}"
                           class="text-xs hover:underline" style="color: var(--text-secondary);">Edit</a>
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
