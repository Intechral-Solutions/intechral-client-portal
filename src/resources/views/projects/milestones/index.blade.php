@extends('layouts.app', ['title' => $project->name . ' — Milestones'])

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-6 flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2 text-sm mb-1" style="color: var(--text-secondary);">
                <a href="{{ route('projects.board', $project) }}" class="hover:underline">{{ $project->name }}</a>
                <span>/</span>
                <span>Milestones</span>
            </div>
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">Milestones</h1>
        </div>
        @can('manage', $project)
        <button type="button" id="new-milestone-btn"
                class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-colors"
                style="background-color: var(--accent); color: #fff;">
            + New Milestone
        </button>
        @endcan
    </div>

    @if (session('success'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    {{-- New Milestone form --}}
    @can('manage', $project)
    <div id="new-milestone-form" class="mb-6 hidden rounded-xl border p-5"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <h2 class="mb-4 text-sm font-semibold" style="color: var(--text-primary);">New Milestone</h2>
        <form method="POST" action="{{ route('projects.milestones.store', $project) }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Name *</label>
                    <input type="text" name="name" required
                           class="block w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-1"
                           style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Due Date *</label>
                    <input type="date" name="due_date" required
                           class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                           style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Description</label>
                <textarea name="description" rows="2"
                          class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                          style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);"></textarea>
            </div>
            <div class="flex gap-2">
                <button type="submit"
                        class="rounded-lg px-4 py-2 text-sm font-medium"
                        style="background-color: var(--accent); color: #fff;">Create</button>
                <button type="button" id="cancel-milestone"
                        class="rounded-lg border px-4 py-2 text-sm font-medium"
                        style="border-color: var(--border-base); color: var(--text-secondary);">Cancel</button>
            </div>
        </form>
    </div>
    @endcan

    {{-- Milestones list --}}
    @forelse ($milestones as $milestone)
    @php $completion = $milestone->completionPercentage(); $isOverdue = $milestone->due_date->isPast() && $completion < 100; @endphp
    <div class="mb-4 rounded-xl border p-5"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <div class="flex items-start justify-between mb-3">
            <div>
                <h2 class="text-base font-semibold" style="color: var(--text-primary);">{{ $milestone->name }}</h2>
                @if ($milestone->description)
                <p class="mt-0.5 text-sm" style="color: var(--text-secondary);">{{ $milestone->description }}</p>
                @endif
            </div>
            <div class="flex items-center gap-3 shrink-0 ml-4">
                <span class="text-sm {{ $isOverdue ? 'font-medium' : '' }}"
                      style="color: {{ $isOverdue ? 'var(--text-danger)' : 'var(--text-muted)' }};">
                    Due {{ $milestone->due_date->format('M j, Y') }}
                </span>
                @can('manage', $project)
                <button type="button"
                        class="edit-milestone-btn text-xs"
                        style="color: var(--text-muted);"
                        data-id="{{ $milestone->id }}"
                        data-name="{{ $milestone->name }}"
                        data-description="{{ $milestone->description }}"
                        data-due="{{ $milestone->due_date->format('Y-m-d') }}">
                    Edit
                </button>
                <form method="POST" action="{{ route('projects.milestones.destroy', [$project, $milestone]) }}"
                      onsubmit="return confirm('Delete this milestone?')" class="inline">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-xs" style="color: var(--text-danger);">Delete</button>
                </form>
                @endcan
            </div>
        </div>

        {{-- Progress --}}
        <div>
            <div class="mb-1 flex justify-between text-xs" style="color: var(--text-muted);">
                <span>{{ $milestone->tasks_count }} task{{ $milestone->tasks_count !== 1 ? 's' : '' }}</span>
                <span>{{ $completion }}% complete</span>
            </div>
            <div class="h-2 w-full overflow-hidden rounded-full" style="background-color: var(--border-base);">
                <div class="h-full rounded-full transition-all"
                     style="width: {{ $completion }}%; background-color: {{ $completion === 100 ? 'var(--accent-success, #22c55e)' : 'var(--accent)' }};"></div>
            </div>
        </div>
    </div>
    @empty
    <div class="rounded-xl border py-16 text-center"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <p class="text-sm" style="color: var(--text-muted);">No milestones yet.</p>
    </div>
    @endforelse

</div>

{{-- Edit milestone modal --}}
@can('manage', $project)
<div id="edit-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
    <div class="w-full max-w-lg rounded-xl border p-6 mx-4"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <h2 class="mb-4 text-base font-semibold" style="color: var(--text-primary);">Edit Milestone</h2>
        <form id="edit-milestone-form" method="POST" class="space-y-4">
            @csrf @method('PUT')
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Name *</label>
                <input type="text" id="edit-name" name="name" required
                       class="block w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-1"
                       style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Due Date *</label>
                <input type="date" id="edit-due" name="due_date" required
                       class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                       style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Description</label>
                <textarea id="edit-description" name="description" rows="2"
                          class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                          style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);"></textarea>
            </div>
            <div class="flex gap-2 justify-end">
                <button type="button" id="close-edit-modal"
                        class="rounded-lg border px-4 py-2 text-sm font-medium"
                        style="border-color: var(--border-base); color: var(--text-secondary);">Cancel</button>
                <button type="submit"
                        class="rounded-lg px-4 py-2 text-sm font-medium"
                        style="background-color: var(--accent); color: #fff;">Save</button>
            </div>
        </form>
    </div>
</div>
@endcan

@push('scripts')
<script>
(function () {
    const newBtn = document.getElementById('new-milestone-btn');
    const newForm = document.getElementById('new-milestone-form');
    const cancelBtn = document.getElementById('cancel-milestone');

    if (newBtn) {
        newBtn.addEventListener('click', () => newForm.classList.remove('hidden'));
    }
    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => newForm.classList.add('hidden'));
    }

    const modal = document.getElementById('edit-modal');
    const closeModal = document.getElementById('close-edit-modal');
    if (closeModal) closeModal.addEventListener('click', () => modal.classList.add('hidden'));

    document.querySelectorAll('.edit-milestone-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            document.getElementById('edit-name').value = btn.dataset.name;
            document.getElementById('edit-due').value = btn.dataset.due;
            document.getElementById('edit-description').value = btn.dataset.description || '';
            document.getElementById('edit-milestone-form').action = `/projects/{{ $project->id }}/milestones/${id}`;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        });
    });

    if (modal) {
        modal.addEventListener('click', e => {
            if (e.target === modal) modal.classList.add('hidden');
        });
    }
})();
</script>
@endpush
@endsection
