@extends('layouts.app', ['title' => $task->title])

@section('content')
<div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">

    {{-- Breadcrumb --}}
    <div class="mb-6 flex items-center gap-2 text-sm" style="color: var(--text-secondary);">
        <a href="{{ route('projects.index') }}" class="hover:underline">Projects</a>
        <span>/</span>
        <a href="{{ route('projects.board', $project) }}" class="hover:underline">{{ $project->name }}</a>
        <span>/</span>
        <span style="color: var(--text-primary);">{{ Str::limit($task->title, 40) }}</span>
    </div>

    @if (session('success'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- Main content --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Task title & description --}}
            <div class="rounded-xl border p-6"
                 style="background-color: var(--surface-card); border-color: var(--border-base);">
                <h1 class="text-xl font-semibold mb-3" style="color: var(--text-primary);">{{ $task->title }}</h1>
                @if ($task->description)
                <p class="text-sm whitespace-pre-line" style="color: var(--text-secondary);">{{ $task->description }}</p>
                @else
                <p class="text-sm italic" style="color: var(--text-muted);">No description.</p>
                @endif
            </div>

            {{-- Checklist --}}
            @if ($task->checklistItems->isNotEmpty())
            <div class="rounded-xl border p-6"
                 style="background-color: var(--surface-card); border-color: var(--border-base);">
                <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Checklist</h2>
                @php $done = $task->checklistItems->where('completed', true)->count(); $total = $task->checklistItems->count(); @endphp
                <div class="mb-3">
                    <div class="mb-1 flex justify-between text-xs" style="color: var(--text-muted);">
                        <span>{{ $done }} / {{ $total }}</span>
                        <span>{{ $total > 0 ? round($done / $total * 100) : 0 }}%</span>
                    </div>
                    <div class="h-1.5 w-full overflow-hidden rounded-full" style="background-color: var(--border-base);">
                        <div class="h-full rounded-full transition-all" style="width: {{ $total > 0 ? round($done / $total * 100) : 0 }}%; background-color: var(--accent);"></div>
                    </div>
                </div>
                <ul class="space-y-1.5">
                    @foreach ($task->checklistItems as $item)
                    <li class="flex items-center gap-2">
                        <button type="button"
                                class="toggle-checklist flex h-5 w-5 items-center justify-center rounded border transition-colors"
                                style="{{ $item->completed ? 'background-color: var(--accent); border-color: var(--accent); color: #fff;' : 'border-color: var(--border-strong); color: transparent;' }}"
                                data-item="{{ $item->id }}">
                            @if ($item->completed)
                            <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                            </svg>
                            @endif
                        </button>
                        <span class="text-sm {{ $item->completed ? 'line-through' : '' }}"
                              style="color: {{ $item->completed ? 'var(--text-muted)' : 'var(--text-primary)' }};">
                            {{ $item->title }}
                        </span>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Comments --}}
            <div class="rounded-xl border p-6"
                 style="background-color: var(--surface-card); border-color: var(--border-base);">
                <h2 class="text-sm font-semibold mb-4" style="color: var(--text-primary);">Comments</h2>

                @forelse ($task->comments as $comment)
                <div class="mb-4 flex gap-3">
                    <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-medium"
                          style="background-color: var(--accent); color: #fff;">
                        {{ strtoupper(substr($comment->user->name, 0, 1)) }}
                    </span>
                    <div>
                        <p class="text-xs font-medium" style="color: var(--text-primary);">
                            {{ $comment->user->name }}
                            <span class="font-normal ml-1" style="color: var(--text-muted);">{{ $comment->created_at->diffForHumans() }}</span>
                        </p>
                        <p class="mt-1 text-sm whitespace-pre-line" style="color: var(--text-secondary);">{{ $comment->body }}</p>
                    </div>
                </div>
                @empty
                <p class="text-sm italic" style="color: var(--text-muted);">No comments yet.</p>
                @endforelse

                <form method="POST" action="{{ route('projects.tasks.comments.store', [$project, $task]) }}" class="mt-4">
                    @csrf
                    <textarea name="body" rows="3" placeholder="Add a comment…" required
                              class="block w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-1 mb-2"
                              style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);"></textarea>
                    <button type="submit"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium"
                            style="background-color: var(--accent); color: #fff;">Post Comment</button>
                </form>
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-4">
            <div class="rounded-xl border p-5"
                 style="background-color: var(--surface-card); border-color: var(--border-base);">
                <h2 class="mb-4 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Details</h2>

                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="font-medium" style="color: var(--text-muted);">Status</dt>
                        <dd class="mt-0.5" style="color: var(--text-primary);">{{ $task->effectiveStatus() }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium" style="color: var(--text-muted);">Priority</dt>
                        <dd class="mt-0.5 capitalize" style="color: var(--text-primary);">{{ $task->priority }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium" style="color: var(--text-muted);">Assignee</dt>
                        <dd class="mt-0.5" style="color: var(--text-primary);">{{ $task->assignee?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium" style="color: var(--text-muted);">Due Date</dt>
                        <dd class="mt-0.5 {{ $task->isOverdue() ? 'font-medium' : '' }}"
                            style="color: {{ $task->isOverdue() ? 'var(--text-danger)' : 'var(--text-primary)' }};">
                            {{ $task->due_date?->format('M j, Y') ?? '—' }}
                        </dd>
                    </div>
                    @if ($task->milestone)
                    <div>
                        <dt class="font-medium" style="color: var(--text-muted);">Milestone</dt>
                        <dd class="mt-0.5" style="color: var(--text-primary);">{{ $task->milestone->name }}</dd>
                    </div>
                    @endif
                </dl>
            </div>

            @can('manage', $project)
            <div class="rounded-xl border p-5"
                 style="background-color: var(--surface-card); border-color: var(--border-base);">
                <h2 class="mb-4 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Edit Task</h2>
                <form method="POST" action="{{ route('projects.tasks.update', [$project, $task]) }}">
                    @csrf @method('PUT')
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Title</label>
                            <input type="text" name="title" value="{{ $task->title }}" required
                                   class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                                   style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Priority</label>
                            <select name="priority"
                                    class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                                    style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                                @foreach (['low', 'medium', 'high', 'critical'] as $p)
                                <option value="{{ $p }}" @selected($task->priority === $p)>{{ ucfirst($p) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Assignee</label>
                            <select name="assignee_id"
                                    class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                                    style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                                <option value="">Unassigned</option>
                                @foreach ($project->members as $m)
                                <option value="{{ $m->id }}" @selected($task->assignee_id === $m->id)>{{ $m->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Due Date</label>
                            <input type="date" name="due_date" value="{{ $task->due_date?->format('Y-m-d') }}"
                                   class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                                   style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Description</label>
                            <textarea name="description" rows="3"
                                      class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                                      style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">{{ $task->description }}</textarea>
                        </div>
                        <button type="submit"
                                class="w-full rounded-lg px-3 py-1.5 text-sm font-medium"
                                style="background-color: var(--accent); color: #fff;">Save</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('projects.tasks.destroy', [$project, $task]) }}" class="mt-3"
                      onsubmit="return confirm('Delete this task?')">
                    @csrf @method('DELETE')
                    <button type="submit"
                            class="w-full rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors"
                            style="border-color: var(--border-danger); color: var(--text-danger);">Delete Task</button>
                </form>
            </div>
            @endcan
        </div>
    </div>
</div>

@push('scripts')
<script>
document.querySelectorAll('.toggle-checklist').forEach(btn => {
    btn.addEventListener('click', function () {
        const itemId = this.dataset.item;
        fetch(`/projects/{{ $project->id }}/tasks/{{ $task->id }}/checklist/${itemId}/toggle`, {
            method: 'PUT',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
        }).then(r => r.json()).then(() => location.reload());
    });
});
</script>
@endpush
@endsection
