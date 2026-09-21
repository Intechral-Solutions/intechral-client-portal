@extends('layouts.app', ['title' => 'Projects'])

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">Projects</h1>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">Manage and track your projects.</p>
        </div>
        @can('create', \App\Models\Project::class)
        <a href="{{ route('projects.create') }}"
           class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-colors"
           style="background-color: var(--accent); color: #fff;">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
            </svg>
            New Project
        </a>
        @endcan
    </div>

    @if (session('success'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    @if ($projects->isEmpty())
    <div class="rounded-xl border py-16 text-center"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <svg class="mx-auto h-12 w-12 mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color: var(--text-muted);">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v8.25A2.25 2.25 0 0 0 4.5 16.5h15a2.25 2.25 0 0 0 2.25-2.25V8.25A2.25 2.25 0 0 0 19.5 6h-5.379a1.5 1.5 0 0 1-1.06-.44Z" />
        </svg>
        <p class="text-sm font-medium" style="color: var(--text-secondary);">No projects found.</p>
        @can('create', \App\Models\Project::class)
        <a href="{{ route('projects.create') }}" class="mt-4 inline-block text-sm font-medium" style="color: var(--accent);">Create your first project &rarr;</a>
        @endcan
    </div>
    @else
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($projects as $project)
        @php
            $completion = $project->completionFromCounts();
            $overdueCount = $project->overdue_tasks_count;
            $statusColors = [
                'active'    => ['bg' => 'var(--surface-success)', 'text' => 'var(--text-success)', 'border' => 'var(--border-success)'],
                'on_hold'   => ['bg' => 'var(--surface-warning)', 'text' => 'var(--text-warning)', 'border' => 'var(--border-warning)'],
                'completed' => ['bg' => 'var(--surface-info)', 'text' => 'var(--text-info)', 'border' => 'var(--border-info)'],
                'archived'  => ['bg' => 'var(--surface-muted)', 'text' => 'var(--text-muted)', 'border' => 'var(--border-muted)'],
            ];
            $sc = $statusColors[$project->status] ?? $statusColors['active'];
        @endphp
        <a href="{{ route('projects.board', $project) }}"
           class="block rounded-xl border p-5 transition-shadow hover:shadow-md"
           style="background-color: var(--surface-card); border-color: var(--border-base);">

            <div class="mb-3 flex items-start justify-between">
                <h2 class="text-base font-semibold leading-snug" style="color: var(--text-primary);">
                    {{ $project->name }}
                </h2>
                <span class="ml-2 shrink-0 rounded-full border px-2 py-0.5 text-xs font-medium capitalize"
                      style="background-color: {{ $sc['bg'] }}; color: {{ $sc['text'] }}; border-color: {{ $sc['border'] }};">
                    {{ str_replace('_', ' ', $project->status) }}
                </span>
            </div>

            @if ($project->description)
            <p class="mb-4 line-clamp-2 text-sm" style="color: var(--text-secondary);">{{ $project->description }}</p>
            @endif

            {{-- Progress bar --}}
            <div class="mb-3">
                <div class="mb-1 flex justify-between text-xs" style="color: var(--text-muted);">
                    <span>Completion</span>
                    <span>{{ $completion }}%</span>
                </div>
                <div class="h-1.5 w-full overflow-hidden rounded-full" style="background-color: var(--border-base);">
                    <div class="h-full rounded-full transition-all" style="width: {{ $completion }}%; background-color: var(--accent);"></div>
                </div>
            </div>

            <div class="flex items-center justify-between text-xs" style="color: var(--text-muted);">
                <span>{{ $project->members_count }} member{{ $project->members_count !== 1 ? 's' : '' }}</span>
                @if ($overdueCount > 0)
                <span class="font-medium" style="color: var(--text-danger);">{{ $overdueCount }} overdue</span>
                @elseif ($project->target_date)
                <span>Due {{ $project->target_date->format('M j, Y') }}</span>
                @endif
            </div>
        </a>
        @endforeach
    </div>

    <div class="mt-6">{{ $projects->links() }}</div>
    @endif

</div>
@endsection
