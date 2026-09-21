@extends('layouts.app', ['title' => 'My Tasks'])

@section('content')
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">

    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">Tasks</h1>
            <p class="mt-0.5 text-sm" style="color: var(--text-secondary);">Tasks assigned to you or your organization.</p>
        </div>
        <button type="button" id="new-task-toggle"
                class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
                style="background-color: var(--accent); color: #fff;">
            + New Task
        </button>
    </div>

    {{-- Flash --}}
    @if (session('success'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    {{-- Inline new task form --}}
    <div id="new-task-form" class="mb-6 hidden rounded-xl border p-5"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <form method="POST" action="{{ route('tasks.store') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Title <span style="color: var(--text-danger);">*</span></label>
                    <input type="text" name="title" value="{{ old('title') }}" required maxlength="255"
                           class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                           style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                    @error('title')<p class="mt-1 text-xs" style="color: var(--text-danger);">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Assignee</label>
                    <select name="assignee_id"
                            class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                            style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                        <option value="">Unassigned</option>
                        {{-- Populated by JS if needed; for now show current user --}}
                        <option value="{{ auth()->id() }}" selected>Me</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Priority</label>
                    <select name="priority"
                            class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                            style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                        @foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'] as $val => $label)
                        <option value="{{ $val }}" {{ old('priority', 'medium') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Status</label>
                    <select name="status"
                            class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                            style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                        @foreach (['todo' => 'To Do', 'in_progress' => 'In Progress', 'done' => 'Done'] as $val => $label)
                        <option value="{{ $val }}" {{ old('status', 'todo') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Due Date</label>
                    <input type="date" name="due_date" value="{{ old('due_date') }}"
                           class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                           style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Description</label>
                    <textarea name="description" rows="2"
                              class="block w-full rounded-lg border px-3 py-2 text-sm outline-none resize-y"
                              style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">{{ old('description') }}</textarea>
                </div>
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" id="new-task-cancel"
                        class="rounded-lg border px-4 py-2 text-sm font-medium"
                        style="border-color: var(--border-base); color: var(--text-secondary);">Cancel</button>
                <button type="submit"
                        class="rounded-lg px-4 py-2 text-sm font-medium"
                        style="background-color: var(--accent); color: #fff;">Create Task</button>
            </div>
        </form>
    </div>

    {{-- View tabs --}}
    <div class="mb-4 flex gap-1 border-b" style="border-color: var(--border-base);">
        <a href="{{ route('tasks.index', ['view' => 'mine']) }}"
           class="px-4 py-2 text-sm font-medium border-b-2 transition-colors -mb-px"
           style="{{ $view === 'mine' ? 'border-color: var(--accent); color: var(--accent);' : 'border-color: transparent; color: var(--text-secondary);' }}">
            Assigned to Me
        </a>
        @if (!empty($companyIds))
        <a href="{{ route('tasks.index', ['view' => 'org']) }}"
           class="px-4 py-2 text-sm font-medium border-b-2 transition-colors -mb-px"
           style="{{ $view === 'org' ? 'border-color: var(--accent); color: var(--accent);' : 'border-color: transparent; color: var(--text-secondary);' }}">
            My Organization
        </a>
        @endif
    </div>

    {{-- Task list --}}
    <div class="rounded-xl border overflow-hidden" style="background-color: var(--surface-card); border-color: var(--border-base);">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b" style="border-color: var(--border-base);">
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Task</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Priority</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Context</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Assignee</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Due</th>
                </tr>
            </thead>
            <tbody class="divide-y" style="divide-color: var(--border-base);">
                @forelse ($tasks as $task)
                <tr class="hover:bg-surface transition-colors">
                    <td class="px-4 py-3">
                        @if ($task->project_id && isset($openableProjects[$task->project_id]))
                        <a href="{{ route('projects.tasks.show', [$task->project, $task]) }}"
                           class="font-medium hover:underline {{ $task->isDone() ? 'line-through opacity-60' : '' }}"
                           style="color: var(--accent);">
                            {{ $task->title }}
                        </a>
                        @else
                        <span class="font-medium {{ $task->isDone() ? 'line-through opacity-60' : '' }}"
                              style="color: var(--text-primary);">
                            {{ $task->title }}
                        </span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                              style="background-color: var(--surface-input); color: var(--text-secondary);">
                            {{ $task->effectiveStatus() }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $priorityColors = [
                                'critical' => ['bg' => 'var(--surface-danger)', 'text' => 'var(--text-danger)'],
                                'high'     => ['bg' => 'var(--surface-warning)', 'text' => 'var(--text-warning)'],
                                'medium'   => ['bg' => 'var(--surface-input)', 'text' => 'var(--text-secondary)'],
                                'low'      => ['bg' => 'var(--surface-input)', 'text' => 'var(--text-muted)'],
                            ];
                            $pc = $priorityColors[$task->priority] ?? $priorityColors['medium'];
                        @endphp
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                              style="background-color: {{ $pc['bg'] }}; color: {{ $pc['text'] }};">
                            {{ $task->priority }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
                        @if ($task->project)
                            @if (isset($openableProjects[$task->project_id]))
                            <a href="{{ route('projects.board', $task->project) }}" class="hover:underline">{{ $task->project->name }}</a>
                            @else
                            {{ $task->project->name }}
                            @endif
                        @elseif ($task->ticket)
                            @if (isset($openableTickets[$task->ticket_id]))
                            <a href="{{ route('tickets.show', $task->ticket) }}" class="hover:underline">{{ $task->ticket->ticket_number }}</a>
                            @else
                            {{ $task->ticket->ticket_number }}
                            @endif
                        @else
                            <span style="color: var(--text-muted);">Standalone</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
                        {{ $task->assignee?->name ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-xs {{ $task->isOverdue() ? 'font-medium' : '' }}"
                        style="color: {{ $task->isOverdue() ? 'var(--text-danger)' : 'var(--text-secondary)' }};">
                        {{ $task->due_date?->format('M j, Y') ?? '—' }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                        No tasks found.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $tasks->links() }}</div>

</div>

@push('scripts')
<script>
(function () {
    const toggle = document.getElementById('new-task-toggle');
    const form   = document.getElementById('new-task-form');
    const cancel = document.getElementById('new-task-cancel');

    toggle.addEventListener('click', () => form.classList.toggle('hidden'));
    cancel.addEventListener('click', () => form.classList.add('hidden'));

    @if ($errors->any())
    form.classList.remove('hidden');
    @endif
})();
</script>
@endpush
@endsection
