@extends('layouts.app', ['title' => 'Time Reports'])

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">Time Reports</h1>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">All logged time across users and projects.</p>
        </div>
        <a href="{{ route('operator.time.export') . '?' . http_build_query(request()->only(['user_id','project_id','from','to','billable'])) }}"
           class="rounded-lg border px-4 py-2 text-sm font-medium transition-colors"
           style="border-color: var(--border-base); color: var(--text-secondary);">
            &#8615; Export CSV
        </a>
    </div>

    {{-- Filters --}}
    <form method="GET" class="mb-6 flex flex-wrap gap-3">
        <select name="user_id"
                class="rounded-lg border px-3 py-2 text-sm outline-none"
                style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
            <option value="">All users</option>
            @foreach ($users as $user)
            <option value="{{ $user->id }}" @selected(($filters['user_id'] ?? '') == $user->id)>{{ $user->name }}</option>
            @endforeach
        </select>

        <select name="project_id"
                class="rounded-lg border px-3 py-2 text-sm outline-none"
                style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
            <option value="">All projects</option>
            @foreach ($projects as $project)
            <option value="{{ $project->id }}" @selected(($filters['project_id'] ?? '') == $project->id)>{{ $project->name }}</option>
            @endforeach
        </select>

        <input type="date" name="from" value="{{ $filters['from'] ?? '' }}"
               class="rounded-lg border px-3 py-2 text-sm outline-none"
               style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
        <input type="date" name="to" value="{{ $filters['to'] ?? '' }}"
               class="rounded-lg border px-3 py-2 text-sm outline-none"
               style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">

        <select name="billable"
                class="rounded-lg border px-3 py-2 text-sm outline-none"
                style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
            <option value="">All entries</option>
            <option value="1" @selected(($filters['billable'] ?? '') === '1')>Billable only</option>
            <option value="0" @selected(($filters['billable'] ?? '') === '0')>Non-billable only</option>
        </select>

        <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium"
                style="background-color: var(--accent); color: #fff;">Filter</button>
    </form>

    {{-- Summary cards --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border p-4" style="background-color: var(--surface-card); border-color: var(--border-base);">
            <p class="text-xs font-semibold uppercase tracking-wide mb-1" style="color: var(--text-muted);">Total Hours</p>
            <p class="text-2xl font-bold" style="color: var(--text-primary);">{{ number_format($totalMinutes / 60, 1) }}h</p>
        </div>
        <div class="rounded-xl border p-4" style="background-color: var(--surface-card); border-color: var(--border-base);">
            <p class="text-xs font-semibold uppercase tracking-wide mb-1" style="color: var(--text-muted);">Users</p>
            <p class="text-2xl font-bold" style="color: var(--text-primary);">{{ $byUser->count() }}</p>
        </div>
        <div class="rounded-xl border p-4" style="background-color: var(--surface-card); border-color: var(--border-base);">
            <p class="text-xs font-semibold uppercase tracking-wide mb-1" style="color: var(--text-muted);">Projects</p>
            <p class="text-2xl font-bold" style="color: var(--text-primary);">{{ $byProject->count() }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 mb-6">
        {{-- By project --}}
        <div class="rounded-xl border p-5" style="background-color: var(--surface-card); border-color: var(--border-base);">
            <h2 class="mb-3 text-sm font-semibold" style="color: var(--text-primary);">By Project</h2>
            @forelse ($byProject as $row)
            @php $pct = $totalMinutes > 0 ? round($row->total_minutes / $totalMinutes * 100) : 0; @endphp
            <div class="mb-3">
                <div class="flex justify-between text-sm mb-1">
                    <span style="color: var(--text-secondary);">{{ $row->project?->name ?? 'No project' }}</span>
                    <span class="font-medium" style="color: var(--text-primary);">{{ number_format($row->total_minutes / 60, 1) }}h</span>
                </div>
                <div class="h-1.5 w-full overflow-hidden rounded-full" style="background-color: var(--border-base);">
                    <div class="h-full rounded-full" style="width: {{ $pct }}%; background-color: var(--accent);"></div>
                </div>
            </div>
            @empty
            <p class="text-sm" style="color: var(--text-muted);">No data.</p>
            @endforelse
        </div>

        {{-- By user --}}
        <div class="rounded-xl border p-5" style="background-color: var(--surface-card); border-color: var(--border-base);">
            <h2 class="mb-3 text-sm font-semibold" style="color: var(--text-primary);">By User</h2>
            @forelse ($byUser as $row)
            @php $pct = $totalMinutes > 0 ? round($row->total_minutes / $totalMinutes * 100) : 0; @endphp
            <div class="mb-3">
                <div class="flex justify-between text-sm mb-1">
                    <span style="color: var(--text-secondary);">{{ $row->user?->name ?? 'Unknown' }}</span>
                    <span class="font-medium" style="color: var(--text-primary);">{{ number_format($row->total_minutes / 60, 1) }}h</span>
                </div>
                <div class="h-1.5 w-full overflow-hidden rounded-full" style="background-color: var(--border-base);">
                    <div class="h-full rounded-full" style="width: {{ $pct }}%; background-color: var(--accent);"></div>
                </div>
            </div>
            @empty
            <p class="text-sm" style="color: var(--text-muted);">No data.</p>
            @endforelse
        </div>
    </div>

    {{-- Entries table --}}
    <div class="rounded-xl border overflow-hidden" style="background-color: var(--surface-card); border-color: var(--border-base);">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b" style="border-color: var(--border-base);">
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">User</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Project</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Description</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Hours</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Bill.</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Billed</th>
                </tr>
            </thead>
            <tbody class="divide-y" style="divide-color: var(--border-base);">
                @forelse ($entries as $entry)
                <tr>
                    <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-secondary);">{{ $entry->date->format('M j, Y') }}</td>
                    <td class="px-4 py-3" style="color: var(--text-primary);">{{ $entry->user->name }}</td>
                    <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $entry->project?->name ?? '—' }}</td>
                    <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $entry->description ?? '—' }}</td>
                    <td class="px-4 py-3 text-right font-mono" style="color: var(--text-primary);">{{ number_format($entry->duration_minutes / 60, 2) }}</td>
                    <td class="px-4 py-3 text-center text-xs" style="color: {{ $entry->billable ? 'var(--text-success)' : 'var(--text-muted)' }};">
                        {{ $entry->billable ? '✓' : '—' }}
                    </td>
                    <td class="px-4 py-3 text-center text-xs" style="color: {{ $entry->billed ? 'var(--text-success)' : 'var(--text-muted)' }};">
                        {{ $entry->billed ? '✓' : '—' }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No entries found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $entries->links() }}</div>
</div>
@endsection
