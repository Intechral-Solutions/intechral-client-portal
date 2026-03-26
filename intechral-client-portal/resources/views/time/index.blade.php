@extends('layouts.app', ['title' => 'My Time'])

@section('content')
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">My Time</h1>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">Log and track your time entries.</p>
        </div>
        <div class="flex items-center gap-2">
            {{-- Live timer display --}}
            @if ($activeTimer)
            <div id="timer-display" class="flex items-center gap-2 rounded-lg border px-3 py-1.5"
                 style="background-color: var(--surface-success); border-color: var(--border-success);"
                 data-started="{{ $activeTimer->timer_started_at->toISOString() }}"
                 data-entry="{{ $activeTimer->id }}">
                <span class="inline-block h-2 w-2 rounded-full animate-pulse" style="background-color: var(--text-success);"></span>
                <span id="timer-clock" class="font-mono text-sm font-medium" style="color: var(--text-success);">00:00:00</span>
                <button id="stop-timer-btn"
                        class="text-xs font-medium ml-1 hover:underline" style="color: var(--text-success);">Stop</button>
            </div>
            @else
            <button id="start-timer-btn"
                    class="rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors"
                    style="border-color: var(--border-base); color: var(--text-secondary);">
                &#9654; Start Timer
            </button>
            @endif
        </div>
    </div>

    @if (session('success'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    {{-- Log entry form --}}
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
                <input type="number" name="hours" value="{{ old('hours') }}" min="0.01" max="24" step="0.25"
                       placeholder="1.5" required
                       class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                       style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                @error('hours')<p class="text-xs mt-0.5" style="color: var(--text-danger);">{{ $message }}</p>@enderror
            </div>

            <div class="col-span-2">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Project</label>
                <select name="project_id"
                        class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
                        style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                    <option value="">No project</option>
                    @foreach ($projects as $project)
                    <option value="{{ $project->id }}" @selected(old('project_id') == $project->id)>{{ $project->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-span-2 sm:col-span-2">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Description</label>
                <input type="text" name="description" value="{{ old('description') }}" placeholder="What did you work on?"
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

    {{-- Filters --}}
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

    {{-- Total --}}
    @if (request('from') || request('to') || request('project_id'))
    <p class="mb-3 text-sm" style="color: var(--text-secondary);">
        Total: <span class="font-semibold" style="color: var(--text-primary);">{{ number_format($totalMinutes / 60, 2) }} hours</span>
    </p>
    @endif

    {{-- Entries table --}}
    <div class="rounded-xl border overflow-hidden"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b" style="border-color: var(--border-base);">
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Project</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Description</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Duration</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Bill.</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y" style="divide-color: var(--border-base);">
                @forelse ($entries as $entry)
                <tr>
                    <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-secondary);">{{ $entry->date->format('M j, Y') }}</td>
                    <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $entry->project?->name ?? '—' }}</td>
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
                        @if (! $entry->billed && ! $entry->isRunning())
                        <form method="POST" action="{{ route('time.destroy', $entry) }}"
                              onsubmit="return confirm('Delete this entry?')" class="inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs hover:underline" style="color: var(--text-danger);">Delete</button>
                        </form>
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
    // ── Live timer clock ──────────────────────────────────────
    const timerDisplay = document.getElementById('timer-display');
    if (timerDisplay) {
        const startedAt = new Date(timerDisplay.dataset.started);
        const clockEl   = document.getElementById('timer-clock');

        const tick = () => {
            const elapsed = Math.floor((Date.now() - startedAt.getTime()) / 1000);
            const h = String(Math.floor(elapsed / 3600)).padStart(2, '0');
            const m = String(Math.floor((elapsed % 3600) / 60)).padStart(2, '0');
            const s = String(elapsed % 60).padStart(2, '0');
            clockEl.textContent = `${h}:${m}:${s}`;
        };
        tick();
        setInterval(tick, 1000);

        document.getElementById('stop-timer-btn')?.addEventListener('click', () => {
            fetch(`/time/timer/${timerDisplay.dataset.entry}/stop`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            }).then(() => location.reload());
        });
    }

    // ── Start timer button ────────────────────────────────────
    document.getElementById('start-timer-btn')?.addEventListener('click', () => {
        fetch('/time/timer/start', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({}),
        }).then(() => location.reload());
    });
})();
</script>
@endpush
@endsection
