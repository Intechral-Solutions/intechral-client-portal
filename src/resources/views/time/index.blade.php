@extends('layouts.app', ['title' => 'My Time'])

@section('content')
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">

    {{-- Page header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">My Time</h1>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">Log and track your time entries.</p>
        </div>
    </div>

    {{-- Tab bar --}}
    <div class="mb-6 flex items-center gap-0.5 border-b" style="border-color: var(--border-base);">
        <a href="{{ route('time.index') }}"
           class="px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors"
           style="border-color: var(--accent); color: var(--accent);">
            Entries
        </a>
        <a href="{{ route('time.allocation') }}"
           class="px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors"
           style="border-color: transparent; color: var(--text-secondary);">
            Allocation Chart
        </a>
    </div>

    @if (session('success'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    @error('time_entry')
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-danger); border-color: var(--border-danger); color: var(--text-danger);"
         role="alert">{{ $message }}</div>
    @enderror

    {{-- Start New Timer ──────────────────────────────────────────────────── --}}
    <div class="mb-6 rounded-xl border p-5"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted);">New Timer</h2>
            <button id="toggle-timer-form" type="button"
                    class="text-xs font-medium hover:underline"
                    style="color: var(--accent);">Show form</button>
        </div>

        <div id="new-timer-form" class="hidden">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-6 mb-3">
                {{-- Context type --}}
                <div class="col-span-1">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Context</label>
                    <select id="timer-context-type"
                            class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                            style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                        <option value="">None</option>
                        <option value="project">Project</option>
                        <option value="task">Task</option>
                        <option value="ticket">Ticket</option>
                    </select>
                </div>

                {{-- Context record (cascading) --}}
                <div class="col-span-2" id="timer-context-record-wrap" style="display:none;">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Select record</label>
                    <select id="timer-context-id"
                            class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                            style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                        <option value="">Loading…</option>
                    </select>
                </div>

                {{-- Description --}}
                <div class="col-span-2 sm:col-span-3">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Description</label>
                    <input type="text" id="timer-description" placeholder="What are you working on?"
                           maxlength="500"
                           class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                           style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                </div>

                {{-- Billable + submit --}}
                <div class="col-span-2 sm:col-span-6 flex items-center gap-3">
                    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-secondary);">
                        <input type="checkbox" id="timer-billable" checked class="rounded">
                        Billable
                    </label>
                    <button type="button" id="start-timer-btn"
                            class="rounded-lg px-4 py-2 text-sm font-medium"
                            style="background-color: var(--accent); color: #fff;">
                        &#9654; Start Timer
                    </button>
                    <span id="timer-start-error" class="text-xs hidden" style="color: var(--text-danger);"></span>
                </div>
            </div>
        </div>

        @if ($activeTimers->isNotEmpty())
        <p class="text-xs mt-1" style="color: var(--text-muted);">
            {{ $activeTimers->count() }} timer{{ $activeTimers->count() !== 1 ? 's' : '' }} currently running — visible in the bar above.
        </p>
        @endif
    </div>

    {{-- Log Time (manual entry) ─────────────────────────────────────────── --}}
    <div class="mb-6 rounded-xl border p-5"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Log Time</h2>
        <form method="POST" action="{{ route('time.store') }}" class="grid grid-cols-2 gap-3 sm:grid-cols-6">
            @csrf

            <div class="col-span-1">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Date *</label>
                <input type="date" name="date" value="{{ old('date', today()->format('Y-m-d')) }}" required
                       max="{{ today()->format('Y-m-d') }}"
                       class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                       style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                @error('date')<p class="text-xs mt-0.5" style="color: var(--text-danger);">{{ $message }}</p>@enderror
            </div>

            <div class="col-span-1">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Hours *</label>
                <input type="number" name="hours" value="{{ old('hours') }}" min="0.25" max="24" step="0.25"
                       placeholder="1.5" required
                       class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                       style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                @error('hours')<p class="text-xs mt-0.5" style="color: var(--text-danger);">{{ $message }}</p>@enderror
            </div>

            {{-- Context type selector --}}
            <div class="col-span-2">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Context</label>
                <select id="log-context-type" name="log_context_type"
                        class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                        style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                    <option value="">None</option>
                    <option value="project" @selected(old('log_context_type') === 'project')>Project</option>
                    <option value="task"    @selected(old('log_context_type') === 'task')>Task</option>
                    <option value="ticket"  @selected(old('log_context_type') === 'ticket')>Ticket</option>
                </select>
            </div>

            {{-- Project select --}}
            <div class="col-span-2" id="log-project-wrap" style="{{ old('log_context_type') === 'project' ? '' : 'display:none;' }}">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Project</label>
                <select name="project_id"
                        class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                        style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                    <option value="">Select project</option>
                    @foreach ($projects as $project)
                    <option value="{{ $project->id }}" @selected(old('project_id') == $project->id)>{{ $project->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Task/Ticket (AJAX) --}}
            <div class="col-span-2" id="log-task-ticket-wrap"
                 style="{{ in_array(old('log_context_type'), ['task','ticket']) ? '' : 'display:none;' }}">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Record</label>
                <select id="log-context-id"
                        class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                        style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                    <option value="">Select…</option>
                </select>
                <input type="hidden" name="task_id"   id="log-task-id"   value="{{ old('task_id') }}">
                <input type="hidden" name="ticket_id" id="log-ticket-id" value="{{ old('ticket_id') }}">
            </div>

            <div class="col-span-2 sm:col-span-4">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Description</label>
                <input type="text" name="description" value="{{ old('description') }}" placeholder="What did you work on?"
                       maxlength="500"
                       class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                       style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
            </div>

            <div class="col-span-2 flex items-end gap-3 sm:col-span-6">
                <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-secondary);">
                    <input type="checkbox" name="billable" value="1" @checked(old('billable', '1') == '1')
                           class="rounded">
                    Billable
                </label>
                <button type="submit"
                        class="rounded-lg px-4 py-2 text-sm font-medium"
                        style="background-color: var(--accent); color: #fff;">Log Time</button>
            </div>
        </form>
    </div>

    {{-- Filters ─────────────────────────────────────────────────────────── --}}
    <form method="GET" class="mb-4 flex flex-wrap gap-3">
        <select name="project_id"
                class="rounded-lg border px-3 py-2 text-sm outline-none"
                style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
            <option value="">All projects</option>
            @foreach ($projects as $project)
            <option value="{{ $project->id }}" @selected(request('project_id') == $project->id)>{{ $project->name }}</option>
            @endforeach
        </select>
        <input type="date" name="from" value="{{ request('from') }}"
               class="rounded-lg border px-3 py-2 text-sm outline-none"
               style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
        <input type="date" name="to" value="{{ request('to') }}"
               class="rounded-lg border px-3 py-2 text-sm outline-none"
               style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
        <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium"
                style="background-color: var(--accent); color: #fff;">Filter</button>
    </form>

    @if (request('from') || request('to') || request('project_id'))
    <p class="mb-3 text-sm" style="color: var(--text-secondary);">
        Total: <span class="font-semibold" style="color: var(--text-primary);">{{ number_format($totalMinutes / 60, 2) }} hours</span>
    </p>
    @endif

    {{-- Entries table ───────────────────────────────────────────────────── --}}
    <div class="rounded-xl border overflow-hidden"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b" style="border-color: var(--border-base);">
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Context</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Description</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Duration</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Bill.</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries as $entry)
                <tr class="border-t" style="border-color: var(--border-base);">
                    <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-secondary);">{{ $entry->date->format('M j, Y') }}</td>
                    <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
                        @if ($entry->ticket)
                            <a href="{{ route('tickets.show', $entry->ticket) }}"
                               class="hover:underline" style="color: var(--accent);">
                                {{ $entry->ticket->ticket_number }}
                            </a>
                        @elseif ($entry->task)
                            @if ($entry->task->project_id)
                            <a href="{{ route('projects.tasks.show', [$entry->task->project_id, $entry->task]) }}"
                               class="hover:underline" style="color: var(--accent);">
                                {{ Str::limit($entry->task->title, 30) }}
                            </a>
                            @else
                            {{ Str::limit($entry->task->title, 30) }}
                            @endif
                        @elseif ($entry->project)
                            <a href="{{ route('projects.board', $entry->project) }}"
                               class="hover:underline" style="color: var(--accent);">
                                {{ $entry->project->name }}
                            </a>
                        @else
                            <span style="color: var(--text-muted);">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3" style="color: var(--text-primary);">{{ $entry->description ?? '—' }}</td>
                    <td class="px-4 py-3 text-right font-mono font-medium whitespace-nowrap" style="color: var(--text-primary);">
                        @if ($entry->isRunning())
                        <span class="text-xs" style="color: var(--text-success);">&#9679; Running</span>
                        @else
                        {{ $entry->durationForHumans() }}
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center">
                        @if ($entry->billable)
                        <span class="text-xs" style="color: var(--text-success);">&#10003;</span>
                        @else
                        <span class="text-xs" style="color: var(--text-muted);">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if (! $entry->isLockedForBilling() && ! $entry->isRunning())
                        <form method="POST" action="{{ route('time.destroy', $entry) }}"
                              onsubmit="return confirm('Delete this entry?')" class="inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs hover:underline" style="color: var(--text-danger);">Delete</button>
                        </form>
                        @elseif ($entry->isLockedForBilling())
                        <span class="text-xs" style="color: var(--text-muted);" title="Billed time entries cannot be modified.">Locked</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No time entries yet.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $entries->links() }}</div>
</div>

@push('scripts')
<script>
(function () {
    'use strict';

    const CSRF = document.querySelector('meta[name="csrf-token"]').content;

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ── Toggle new-timer form visibility ─────────────────────────────────
    const toggleBtn = document.getElementById('toggle-timer-form');
    const timerForm = document.getElementById('new-timer-form');
    toggleBtn?.addEventListener('click', () => {
        const isHidden = timerForm.classList.contains('hidden');
        timerForm.classList.toggle('hidden', !isHidden);
        toggleBtn.textContent = isHidden ? 'Hide' : 'Show form';
    });

    // ── Cascading context selector (timer form) ───────────────────────────
    const contextTypeEl = document.getElementById('timer-context-type');
    const contextIdEl   = document.getElementById('timer-context-id');
    const contextWrapEl = document.getElementById('timer-context-record-wrap');

    async function loadContextOptions(type, targetSelect) {
        targetSelect.innerHTML = '<option value="">Loading\u2026</option>';
        try {
            const res  = await fetch('/time/context-options?type=' + encodeURIComponent(type), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const opts = await res.json();
            targetSelect.innerHTML = '<option value="">Select\u2026</option>' +
                opts.map(function(o) {
                    return '<option value="' + o.id + '">' + escHtml(o.label) + '</option>';
                }).join('');
        } catch (e) {
            targetSelect.innerHTML = '<option value="">Error loading options</option>';
        }
    }

    contextTypeEl?.addEventListener('change', function() {
        const type = contextTypeEl.value;
        if (!type) {
            contextWrapEl.style.display = 'none';
            return;
        }
        contextWrapEl.style.display = '';
        loadContextOptions(type, contextIdEl);
    });

    // ── Cascading context selector (log form) ─────────────────────────────
    const logContextTypeEl  = document.getElementById('log-context-type');
    const logContextIdEl    = document.getElementById('log-context-id');
    const logProjectWrap    = document.getElementById('log-project-wrap');
    const logTaskTicketWrap = document.getElementById('log-task-ticket-wrap');
    const logTaskIdEl       = document.getElementById('log-task-id');
    const logTicketIdEl     = document.getElementById('log-ticket-id');

    logContextTypeEl?.addEventListener('change', function() {
        const type = logContextTypeEl.value;
        logProjectWrap.style.display    = (type === 'project') ? '' : 'none';
        logTaskTicketWrap.style.display = (type === 'task' || type === 'ticket') ? '' : 'none';
        if (type === 'task' || type === 'ticket') {
            loadContextOptions(type, logContextIdEl);
        }
    });

    logContextIdEl?.addEventListener('change', function() {
        const type = logContextTypeEl.value;
        const val  = logContextIdEl.value;
        if (logTaskIdEl)   logTaskIdEl.value   = (type === 'task')   ? val : '';
        if (logTicketIdEl) logTicketIdEl.value = (type === 'ticket') ? val : '';
    });

    // ── Start timer button ────────────────────────────────────────────────
    document.getElementById('start-timer-btn')?.addEventListener('click', async function() {
        const type  = contextTypeEl  ? contextTypeEl.value  : '';
        const recId = contextIdEl    ? contextIdEl.value    : '';

        var payload = {
            description: (document.getElementById('timer-description')?.value || '').trim() || null,
            billable:    document.getElementById('timer-billable')?.checked ?? true,
        };

        if (type === 'project' && recId) payload.project_id = parseInt(recId, 10);
        if (type === 'task'    && recId) payload.task_id    = parseInt(recId, 10);
        if (type === 'ticket'  && recId) payload.ticket_id  = parseInt(recId, 10);

        var errEl = document.getElementById('timer-start-error');
        errEl.classList.add('hidden');

        try {
            var res = await fetch('/time/timer/start', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF,
                },
                body: JSON.stringify(payload),
            });

            if (!res.ok) {
                errEl.textContent = 'Failed to start timer. Please try again.';
                errEl.classList.remove('hidden');
                return;
            }

            var data = await res.json();
            window.dispatchEvent(new CustomEvent('timerStarted', { detail: data }));

            // Reset form fields
            if (contextTypeEl) contextTypeEl.value = '';
            if (contextWrapEl) contextWrapEl.style.display = 'none';
            var descEl = document.getElementById('timer-description');
            if (descEl) descEl.value = '';
        } catch (e) {
            errEl.textContent = 'Network error. Please try again.';
            errEl.classList.remove('hidden');
        }
    });
})();
</script>
@endpush
@endsection
