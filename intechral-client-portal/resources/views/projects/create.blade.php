@extends('layouts.app', ['title' => 'New Project'])

@section('content')
<div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-6">
        <a href="{{ route('projects.index') }}" class="inline-flex items-center gap-1 text-sm" style="color: var(--text-secondary);">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
            </svg>
            Projects
        </a>
        <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">New Project</h1>
    </div>

    <form method="POST" action="{{ route('projects.store') }}" class="space-y-6">
        @csrf

        <div class="rounded-xl border p-6 space-y-5"
             style="background-color: var(--surface-card); border-color: var(--border-base);">

            <h2 class="text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Project Details</h2>

            {{-- Name --}}
            <div>
                <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="name">Project Name <span style="color: var(--text-danger);">*</span></label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required
                       class="block w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                       style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                @error('name')<p class="mt-1 text-xs" style="color: var(--text-danger);">{{ $message }}</p>@enderror
            </div>

            {{-- Description --}}
            <div>
                <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="description">Description</label>
                <textarea id="description" name="description" rows="3"
                          class="block w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                          style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">{{ old('description') }}</textarea>
            </div>

            {{-- Dates --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="{{ old('start_date') }}"
                           class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                           style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                    @error('start_date')<p class="mt-1 text-xs" style="color: var(--text-danger);">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="target_date">Target Date</label>
                    <input type="date" id="target_date" name="target_date" value="{{ old('target_date') }}"
                           class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                           style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                    @error('target_date')<p class="mt-1 text-xs" style="color: var(--text-danger);">{{ $message }}</p>@enderror
                </div>
            </div>

            {{-- Status & Budget --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="status">Status</label>
                    <select id="status" name="status"
                            class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                            style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                        @foreach (['active' => 'Active', 'on_hold' => 'On Hold', 'completed' => 'Completed', 'archived' => 'Archived'] as $val => $label)
                        <option value="{{ $val }}" @selected(old('status', 'active') === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);" for="budget">Budget ($)</label>
                    <input type="number" id="budget" name="budget" value="{{ old('budget') }}" min="0" step="0.01"
                           class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                           style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                    @error('budget')<p class="mt-1 text-xs" style="color: var(--text-danger);">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        {{-- Members --}}
        <div class="rounded-xl border p-6 space-y-4"
             style="background-color: var(--surface-card); border-color: var(--border-base);">
            <h2 class="text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Initial Members</h2>
            <p class="text-xs" style="color: var(--text-secondary);">You will be added as manager automatically. Add additional members below.</p>

            <div id="members-container" class="space-y-2">
                @if (old('members'))
                @foreach (old('members') as $i => $m)
                <div class="flex gap-2 member-row">
                    <select name="members[{{ $i }}][user_id]" class="flex-1 rounded-lg border px-3 py-2 text-sm outline-none"
                            style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                        <option value="">Select user&hellip;</option>
                        @foreach ($members as $u)
                        <option value="{{ $u->id }}" @selected((string)($m['user_id'] ?? '') === (string)$u->id)>{{ $u->name }} &lt;{{ $u->email }}&gt;</option>
                        @endforeach
                    </select>
                    <select name="members[{{ $i }}][role]" class="rounded-lg border px-3 py-2 text-sm outline-none"
                            style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                        <option value="member" @selected(($m['role'] ?? '') === 'member')>Member</option>
                        <option value="manager" @selected(($m['role'] ?? '') === 'manager')>Manager</option>
                    </select>
                    <button type="button" onclick="this.closest('.member-row').remove()"
                            class="rounded-lg border px-2 py-1 text-sm transition-colors hover:opacity-80"
                            style="border-color: var(--border-base); color: var(--text-danger);">&times;</button>
                </div>
                @endforeach
                @endif
            </div>

            <button type="button" id="add-member"
                    class="text-sm font-medium" style="color: var(--accent);">
                + Add member
            </button>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('projects.index') }}"
               class="rounded-lg border px-4 py-2 text-sm font-medium transition-colors"
               style="border-color: var(--border-base); color: var(--text-secondary);">Cancel</a>
            <button type="submit"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
                    style="background-color: var(--accent); color: #fff;">Create Project</button>
        </div>
    </form>
</div>

@push('scripts')
<script>
(function() {
    const members = @json($members);
    let idx = {{ old('members') ? count(old('members')) : 0 }};

    document.getElementById('add-member').addEventListener('click', function () {
        const container = document.getElementById('members-container');
        const row = document.createElement('div');
        row.className = 'flex gap-2 member-row';

        const userSelect = document.createElement('select');
        userSelect.name = `members[${idx}][user_id]`;
        userSelect.className = 'flex-1 rounded-lg border px-3 py-2 text-sm outline-none';
        userSelect.style.cssText = 'background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);';
        userSelect.innerHTML = '<option value="">Select user\u2026</option>' +
            members.map(u => `<option value="${u.id}">${u.name} &lt;${u.email}&gt;</option>`).join('');

        const roleSelect = document.createElement('select');
        roleSelect.name = `members[${idx}][role]`;
        roleSelect.className = 'rounded-lg border px-3 py-2 text-sm outline-none';
        roleSelect.style.cssText = 'background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);';
        roleSelect.innerHTML = '<option value="member">Member</option><option value="manager">Manager</option>';

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.innerHTML = '&times;';
        removeBtn.className = 'rounded-lg border px-2 py-1 text-sm transition-colors hover:opacity-80';
        removeBtn.style.cssText = 'border-color: var(--border-base); color: var(--text-danger);';
        removeBtn.addEventListener('click', () => row.remove());

        row.appendChild(userSelect);
        row.appendChild(roleSelect);
        row.appendChild(removeBtn);
        container.appendChild(row);
        idx++;
    });
})();
</script>
@endpush
@endsection
