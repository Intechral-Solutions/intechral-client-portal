{{--
    Embeddable time tracker component.

    Props:
        $contextType  — 'project' | 'task' | 'ticket'
        $contextId    — the record's primary key
        $contextLabel — short display name (e.g. ticket number, task title)
        $contextUrl   — URL to the record (for back-link in the overlay tile)
--}}
@php
    use App\Models\TimeEntry;
    use Illuminate\Support\Str;

    $col = $contextType . '_id';

    // D6: on a task, a viewer sees their own time; everyone's only with time.view_all. Being a
    // project manager grants nothing extra. Ticket and project contexts are unchanged.
    $ownTimeOnly = $contextType === 'task' && ! auth()->user()->can('time.view_all');

    $recentEntries = TimeEntry::where($col, $contextId)
        ->when($ownTimeOnly, fn ($q) => $q->where('user_id', auth()->id()))
        ->whereNull('timer_started_at')
        ->with('user:id,name')
        ->orderByDesc('date')
        ->orderByDesc('id')
        ->limit(5)
        ->get();

    $totalMinutes = TimeEntry::where($col, $contextId)
        ->when($ownTimeOnly, fn ($q) => $q->where('user_id', auth()->id()))
        ->whereNull('timer_started_at')
        ->sum('duration_minutes');

    $runningEntry = TimeEntry::where($col, $contextId)
        ->running()
        ->where('user_id', auth()->id())
        ->first();

    $totalHuman = $totalMinutes > 0
        ? (intdiv($totalMinutes, 60) > 0
            ? intdiv($totalMinutes, 60) . 'h ' . ($totalMinutes % 60 > 0 ? ($totalMinutes % 60) . 'm' : '')
            : ($totalMinutes % 60) . 'm')
        : '0h';
@endphp

<div class="rounded-xl border p-4 mt-4"
     style="background-color: var(--surface-card); border-color: var(--border-base);"
     data-time-tracker
     data-context-type="{{ $contextType }}"
     data-context-id="{{ $contextId }}"
     data-context-label="{{ $contextLabel }}"
     data-context-url="{{ $contextUrl }}">

    <div class="flex items-center justify-between mb-3">
        <h3 class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Time Tracked</h3>
        <span class="text-sm font-semibold font-mono" style="color: var(--text-primary);">{{ trim($totalHuman) }}</span>
    </div>

    @if ($recentEntries->isNotEmpty())
    <ul class="mb-3 space-y-1">
        @foreach ($recentEntries as $entry)
        <li class="flex items-center justify-between text-xs" style="color: var(--text-secondary);">
            <span>{{ $entry->date->format('M j') }} · {{ $entry->user->name ?? '—' }}</span>
            <span class="font-mono" style="color: var(--text-primary);">{{ $entry->durationForHumans() }}</span>
        </li>
        @endforeach
    </ul>
    @endif

    @can('time.log')
    <div class="flex items-center gap-2">
        @if ($runningEntry)
        <span class="inline-flex items-center gap-1.5 text-xs" style="color: var(--text-success);">
            <span class="inline-block h-1.5 w-1.5 rounded-full animate-pulse" style="background-color: var(--text-success);"></span>
            Timer running
        </span>
        <button type="button"
                class="time-tracker-stop-btn text-xs font-medium hover:underline ml-auto"
                style="color: var(--text-danger);"
                data-entry-id="{{ $runningEntry->id }}">
            Stop
        </button>
        @else
        <button type="button"
                class="time-tracker-start-btn text-xs font-medium rounded-lg border px-3 py-1.5 transition-colors hover:opacity-90"
                style="border-color: var(--border-base); color: var(--text-secondary); background-color: var(--surface-input);">
            &#9654; Start Timer
        </button>
        @endif
    </div>
    @endcan
</div>

@once
@push('scripts')
<script>
(function () {
    'use strict';

    var CSRF = document.querySelector('meta[name="csrf-token"]').content;

    // ── Start timer buttons ───────────────────────────────────────────────
    document.querySelectorAll('.time-tracker-start-btn').forEach(function(btn) {
        btn.addEventListener('click', async function() {
            var card = btn.closest('[data-time-tracker]');
            if (!card) return;

            var contextType  = card.dataset.contextType;
            var contextId    = parseInt(card.dataset.contextId, 10);
            var contextLabel = card.dataset.contextLabel || '';
            var contextUrl   = card.dataset.contextUrl   || '';

            var payload = {};
            if (contextType === 'project') payload.project_id = contextId;
            if (contextType === 'task')    payload.task_id    = contextId;
            if (contextType === 'ticket')  payload.ticket_id  = contextId;

            btn.disabled = true;
            btn.textContent = '\u2026';

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
                    btn.disabled = false;
                    btn.innerHTML = '&#9654; Start Timer';
                    return;
                }

                var data = await res.json();
                window.dispatchEvent(new CustomEvent('timerStarted', { detail: data }));

                // Replace button with "running" indicator — a full page reload
                // would also work, but this gives immediate feedback.
                card.querySelector('.flex.items-center.gap-2').innerHTML =
                    '<span class="inline-flex items-center gap-1.5 text-xs" style="color: var(--text-success);">' +
                    '<span class="inline-block h-1.5 w-1.5 rounded-full animate-pulse" style="background-color: var(--text-success);"></span>' +
                    'Timer running</span>' +
                    '<button type="button" class="time-tracker-stop-btn text-xs font-medium hover:underline ml-auto"' +
                    ' style="color: var(--text-danger);" data-entry-id="' + data.id + '">Stop</button>';

                // Re-wire the new stop button
                wireStopButtons();
            } catch (e) {
                btn.disabled = false;
                btn.innerHTML = '&#9654; Start Timer';
            }
        });
    });

    // ── Stop timer buttons ────────────────────────────────────────────────
    function wireStopButtons() {
        document.querySelectorAll('.time-tracker-stop-btn').forEach(function(btn) {
            if (btn._wired) return;
            btn._wired = true;
            btn.addEventListener('click', async function() {
                var entryId = btn.dataset.entryId;
                btn.disabled = true;
                btn.textContent = '\u2026';
                try {
                    var res = await fetch('/time/timer/' + entryId + '/stop', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': CSRF },
                    });

                    if (!res.ok) {
                        throw new Error('Unable to stop timer (' + res.status + ')');
                    }

                    window.dispatchEvent(new CustomEvent('timerStopped', { detail: { id: parseInt(entryId, 10) } }));
                    // Reload the page to refresh the totals / recent entries
                    location.reload();
            } catch (e) {
                btn.disabled = false;
                btn.textContent = 'Retry stop';
                btn.title = e.message || 'Unable to stop timer';
            }
            });
        });
    }

    wireStopButtons();
})();
</script>
@endpush
@endonce
