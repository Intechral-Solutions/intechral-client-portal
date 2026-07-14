@extends('layouts.app', ['title' => $project->name . ' — Board'])

@section('content')
<div class="flex h-full flex-col" style="min-height: calc(100vh - 4rem);">

    {{-- Board Header --}}
    <div class="border-b px-6 py-4 flex items-center justify-between"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <div class="flex items-center gap-3 min-w-0">
            <a href="{{ route('projects.index') }}" class="shrink-0 text-sm" style="color: var(--text-secondary);">Projects</a>
            <span style="color: var(--border-base);">/</span>
            <h1 class="truncate text-base font-semibold" style="color: var(--text-primary);">{{ $project->name }}</h1>
            @php
                $statusLabels = ['active' => 'Active', 'on_hold' => 'On Hold', 'completed' => 'Completed', 'archived' => 'Archived'];
                $statusBg = ['active' => 'var(--surface-success)', 'on_hold' => 'var(--surface-warning)', 'completed' => 'var(--surface-info)', 'archived' => 'var(--surface-muted)'];
                $statusText = ['active' => 'var(--text-success)', 'on_hold' => 'var(--text-warning)', 'completed' => 'var(--text-info)', 'archived' => 'var(--text-muted)'];
            @endphp
            <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium"
                  style="background-color: {{ $statusBg[$project->status] ?? 'var(--surface-muted)' }}; color: {{ $statusText[$project->status] ?? 'var(--text-muted)' }};">
                {{ $statusLabels[$project->status] ?? $project->status }}
            </span>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <a href="{{ route('projects.milestones.index', $project) }}"
               class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors"
               style="border-color: var(--border-base); color: var(--text-secondary);">Milestones</a>
            @can('manage', $project)
            <a href="{{ route('projects.edit', $project) }}"
               class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors"
               style="border-color: var(--border-base); color: var(--text-secondary);">Settings</a>
            @endcan
        </div>
    </div>

    @if (session('success'))
    <div class="mx-6 mt-4 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    {{-- Kanban Board --}}
    <div class="flex flex-1 gap-4 overflow-x-auto px-6 py-6" id="kanban-board">
        @foreach ($project->columns as $column)
        <div class="flex w-72 shrink-0 flex-col rounded-xl border"
             style="background-color: var(--surface-muted); border-color: var(--border-base);"
             data-column-id="{{ $column->id }}">

            {{-- Column Header --}}
            <div class="flex items-center justify-between rounded-t-xl px-3 py-2.5 border-b"
                 style="border-color: var(--border-base);">
                <div class="flex items-center gap-2">
                    @if ($column->is_done_column)
                    <span class="inline-block h-2 w-2 rounded-full" style="background-color: var(--accent-success, #22c55e);"></span>
                    @else
                    <span class="inline-block h-2 w-2 rounded-full" style="background-color: var(--border-strong);"></span>
                    @endif
                    <span class="text-sm font-medium" style="color: var(--text-primary);">{{ $column->name }}</span>
                    <span class="text-xs" style="color: var(--text-muted);">{{ $column->tasks->count() }}</span>
                </div>
                <button type="button"
                        class="add-task-btn rounded p-1 transition-colors hover:opacity-80"
                        style="color: var(--text-muted);"
                        data-column="{{ $column->id }}"
                        title="Add task">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
                    </svg>
                </button>
            </div>

            {{-- Task Cards --}}
            <div class="task-list flex-1 space-y-2 overflow-y-auto p-2" data-column-id="{{ $column->id }}">
                @foreach ($column->tasks as $task)
                @php
                    $priorityColors = [
                        'critical' => ['bg' => 'var(--surface-danger)', 'text' => 'var(--text-danger)'],
                        'high'     => ['bg' => 'var(--surface-warning)', 'text' => 'var(--text-warning)'],
                        'medium'   => ['bg' => 'var(--surface-info)', 'text' => 'var(--text-info)'],
                        'low'      => ['bg' => 'var(--surface-muted)', 'text' => 'var(--text-muted)'],
                    ];
                    $pc = $priorityColors[$task->priority] ?? $priorityColors['medium'];
                @endphp
                <div class="task-card group cursor-pointer rounded-lg border p-3 transition-shadow hover:shadow-sm"
                     style="background-color: var(--surface-card); border-color: var(--border-base);"
                     data-task-id="{{ $task->id }}"
                     draggable="true">

                    {{-- Priority badge --}}
                    <div class="mb-2 flex items-start justify-between">
                        <span class="rounded px-1.5 py-0.5 text-xs font-medium capitalize"
                              style="background-color: {{ $pc['bg'] }}; color: {{ $pc['text'] }};">
                            {{ $task->priority }}
                        </span>
                        @if ($task->milestone)
                        <span class="rounded px-1.5 py-0.5 text-xs" style="color: var(--text-muted);">
                            &#127937; {{ Str::limit($task->milestone->name, 20) }}
                        </span>
                        @endif
                    </div>

                    <a href="{{ route('projects.tasks.show', [$project, $task]) }}"
                       class="block text-sm font-medium leading-snug hover:underline"
                       style="color: var(--text-primary);">
                        {{ $task->title }}
                    </a>

                    {{-- Footer --}}
                    <div class="mt-2.5 flex items-center justify-between">
                        <div class="flex items-center gap-2 text-xs" style="color: var(--text-muted);">
                            @if ($task->checklistItems->count() > 0)
                            <span>&#9744; {{ $task->checklistItems->where('completed', true)->count() }}/{{ $task->checklistItems->count() }}</span>
                            @endif
                            @if ($task->due_date)
                            <span class="{{ $task->isOverdue() ? 'font-medium' : '' }}"
                                  style="{{ $task->isOverdue() ? 'color: var(--text-danger);' : '' }}">
                                {{ $task->due_date->format('M j') }}
                            </span>
                            @endif
                        </div>
                        @if ($task->assignee)
                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-medium"
                              style="background-color: var(--accent); color: #fff;"
                              title="{{ $task->assignee->name }}">
                            {{ strtoupper(substr($task->assignee->name, 0, 1)) }}
                        </span>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>

            {{-- Quick-add form (hidden by default) --}}
            <div class="add-task-form hidden p-2 pt-0" data-column="{{ $column->id }}">
                <form method="POST" action="{{ route('projects.tasks.store', $project) }}">
                    @csrf
                    <input type="hidden" name="column_id" value="{{ $column->id }}">
                    <input type="hidden" name="priority" value="medium">
                    <input type="text" name="title" placeholder="Task title…" required
                           class="mb-2 block w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-1"
                           style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                    <div class="flex gap-1">
                        <button type="submit"
                                class="rounded-lg px-3 py-1.5 text-xs font-medium"
                                style="background-color: var(--accent); color: #fff;">Add</button>
                        <button type="button"
                                class="cancel-add-task rounded-lg px-3 py-1.5 text-xs"
                                style="color: var(--text-secondary);">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
        @endforeach
    </div>
</div>

@push('scripts')
<script>
(function () {
    // ── Quick-add task forms ─────────────────────────────────
    document.querySelectorAll('.add-task-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const col = btn.dataset.column;
            document.querySelector(`.add-task-form[data-column="${col}"]`).classList.remove('hidden');
            btn.closest('[data-column-id]')?.querySelector('input[name="title"]')?.focus();
        });
    });
    document.querySelectorAll('.cancel-add-task').forEach(btn => {
        btn.addEventListener('click', () => btn.closest('.add-task-form').classList.add('hidden'));
    });

    // ── Drag-and-drop ────────────────────────────────────────
    let draggedTask = null;

    document.querySelectorAll('.task-card').forEach(card => registerCard(card));

    function registerCard(card) {
        card.addEventListener('dragstart', e => {
            draggedTask = card;
            card.style.opacity = '0.4';
            e.dataTransfer.effectAllowed = 'move';
        });
        card.addEventListener('dragend', () => {
            if (draggedTask) draggedTask.style.opacity = '';
            draggedTask = null;
            document.querySelectorAll('.task-list').forEach(l => l.classList.remove('drag-over'));
        });
    }

    document.querySelectorAll('.task-list').forEach(list => {
        list.addEventListener('dragover', e => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            list.classList.add('drag-over');
        });
        list.addEventListener('dragleave', () => list.classList.remove('drag-over'));
        list.addEventListener('drop', e => {
            e.preventDefault();
            list.classList.remove('drag-over');
            if (!draggedTask) return;

            const targetColumnId = parseInt(list.dataset.columnId);
            list.appendChild(draggedTask);

            // Re-compute position from DOM order
            const cards = [...list.querySelectorAll('.task-card')];
            const position = cards.indexOf(draggedTask);
            const taskId = draggedTask.dataset.taskId;

            fetch(`/projects/{{ $project->id }}/tasks/${taskId}/move`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ column_id: targetColumnId, position }),
            });
        });
    });
})();
</script>
<style>
.task-list.drag-over {
    outline: 2px dashed var(--accent);
    outline-offset: -4px;
    border-radius: 8px;
}
</style>
@endpush
@endsection
