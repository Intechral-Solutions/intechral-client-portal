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

<div class="rounded-lg border p-4 mt-4 bg-surface border-rule"
     data-time-tracker
     data-context-type="{{ $contextType }}"
     data-context-id="{{ $contextId }}"
     data-context-label="{{ $contextLabel }}"
     data-context-url="{{ $contextUrl }}">

    <div class="flex items-center justify-between mb-3">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-text-muted">Time Tracked</h3>
        <span class="text-sm font-semibold font-mono text-text">{{ trim($totalHuman) }}</span>
    </div>

    @if ($recentEntries->isNotEmpty())
    <ul class="mb-3 space-y-1">
        @foreach ($recentEntries as $entry)
        <li class="flex items-center justify-between text-xs text-text-secondary">
            <span>{{ $entry->date->format('M j') }} · {{ $entry->user->name ?? '—' }}</span>
            <span class="font-mono text-text">{{ $entry->durationForHumans() }}</span>
        </li>
        @endforeach
    </ul>
    @endif

    @can('time.log')
    {{-- The script finds the controls through data-* hooks, never utility classes. --}}
    <div class="flex items-center gap-2" data-time-tracker-controls>
        @if ($runningEntry)
        <x-time-tracker.running :entry-id="$runningEntry->id" />
        @else
        <x-ui.button variant="secondary" size="sm" data-time-tracker-start>&#9654; Start Timer</x-ui.button>
        @endif
    </div>
    {{-- The running state the script clones when a timer starts here: same component, no JavaScript markup. --}}
    <template data-time-tracker-running-template>
        <x-time-tracker.running />
    </template>
    @endcan
</div>

@once
@push('scripts')
<script>
(function () {
    'use strict';

    var CSRF = document.querySelector('meta[name="csrf-token"]').content;

    // ── Start timer buttons ───────────────────────────────────────────────
    document.querySelectorAll('[data-time-tracker-start]').forEach(function(btn) {
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

            var label = btn.textContent;
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
                    btn.textContent = label;
                    return;
                }

                var data = await res.json();
                window.dispatchEvent(new CustomEvent('timerStarted', { detail: data }));

                // Replace the button with the server-rendered running state (a <template> holding the same
                // component markup), so no markup or colour is built here.
                var controls = card.querySelector('[data-time-tracker-controls]');
                var template = card.querySelector('[data-time-tracker-running-template]');
                controls.replaceChildren(template.content.cloneNode(true));
                controls.querySelector('[data-time-tracker-stop]').dataset.entryId = data.id;

                // Re-wire the new stop button
                wireStopButtons();
            } catch (e) {
                btn.disabled = false;
                btn.textContent = label;
            }
        });
    });

    // ── Stop timer buttons ────────────────────────────────────────────────
    function wireStopButtons() {
        document.querySelectorAll('[data-time-tracker-stop]').forEach(function(btn) {
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
