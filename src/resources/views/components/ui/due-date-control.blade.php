{{--
    Release 1.0 (HD-C1): the operator composer's due-date control. It replaces the "Change due date (optional)" disclosure and
    its keep/set/restore dropdown with ONE compact button showing the effective due date. Clicking it opens a small editor
    PANEL laid over the button (a popover with a native `datetime-local` field that takes the place of the button's own field); the
    joined controls under it never change size or position while it is open.

    <x-ui.due-date-control :ticket="$ticket" :zone="$dueZone" :min="$dueMin" :max="$dueMax" :days="$dueDays" />

    THE SERVER CONTRACT IS UNCHANGED. The page still posts `due_action` (keep | set | restore) and `due_at` (the wall-clock text
    `Y-m-d\TH:i`, read in the application zone by DueDateInput) to the same reply route. This markup only carries the two hidden
    inputs; `shell/blade-ticket-composer.ts` infers them from what the operator did: nothing → keep; a different date → set (with
    that date); the explicit restore button → restore (no date). Nothing is saved until the reply is posted, there is no other
    endpoint, and the server still authorizes before validating and applies the change inside the reply's transaction.

    - The editor input is DETACHED from the form (`form` names no form): it is never submitted, and the browser never runs its
      own min/max validation on it, so a plain reply is never blocked by a half-typed or stale date. The script validates it
      against the live clock before it counts, and the server re-validates whatever is posted.
    - The panel is `absolute`, so opening it moves nothing. Its top edge sits 56px above the button (`-top-14`) and the field
      starts 48px into the panel (16px padding + the 20px heading line + 12px gap), so the field lands over the button, 8px above
      its top (the field is 40px tall to the button's 36px), as if the button had turned into the field with the heading above it.
      The script only sets three custom properties on it (`--due-editor-x`, `--due-editor-y`, `--due-editor-max`) to nudge it back
      inside the viewport on a narrow or short screen; the look is all static classes.
      Inside: a heading and the zone, the one field (dominant), a muted relative-time line (the touch-screen twin of the
      tooltip), and a footer with the two distinct, subdued actions: "Restore SLA" (only while an override exists and a restore
      is not already pending) and "Undo change" (only while something is pending).
    - A refused post refills the pending change from `old()`; a date is kept as long as it is a real wall-clock time (the
      server's message says why it was refused), so the operator's request is not silently lost.
    - Urgency (GREEN > 4 h left; YELLOW from 4 h before through 1 h after the deadline; RED > 1 h overdue; NEUTRAL no deadline)
      is set by the script as `data-urgency` and updates while the page is open. It is shown by colour AND by a different glyph
      per level, and the exact relative time ("Due in 3 hours", "Overdue by 30 minutes") is the tooltip/description.
    - Render only for an actor who passes `adjustDueDate` on an open ticket. A SEGMENT of a joined group (see
      x-ui.visibility-toggle). Wants, beside it in the page: `[data-composer-announcer]` (a polite live region). The pending note is the
      tooltip, not a line of its own: a line that appeared on blur would move "Post Update" between mousedown and mouseup.
      The panel carries its own `#due-date-editor-message` (role=alert, `hidden`, filled by the script when the editor's value
      cannot be taken), next to the field it is about.
--}}
@props([
    'ticket',
    'zone',
    'min',
    'max',
    'days',
])

@php
    $timezone = \App\Support\Helpdesk\DueDateInput::timezone();
    $effective = $ticket->effectiveDueAt();
    $sla = $ticket->sla_due_at;
    $hasOverride = $ticket->hasDueOverride();

    $print = fn ($at) => $at?->copy()->setTimezone($timezone)->format('d M Y H:i');
    $wall = fn ($at) => $at ? \App\Support\Helpdesk\DueDateInput::format($at) : '';
    $millis = fn ($at) => $at ? (string) ($at->getTimestamp() * 1000) : '';

    // A refused post refills the pending change (EPIC-017 stays out of it: this is the Blade operator page only).
    // A crafted post can flash an array; only text is ever a candidate, so a bad shape falls back to "keep" instead of failing the page.
    $oldAction = old('due_action', 'keep');
    $oldAction = is_string($oldAction) ? $oldAction : 'keep';
    $oldAt = old('due_at');
    $oldAt = is_string($oldAt) ? $oldAt : '';

    $action = 'keep';
    $at = '';

    if ($oldAction === 'restore' && $hasOverride) {
        $action = 'restore';
    } elseif ($oldAction === 'set' && \App\Support\Helpdesk\DueDateInput::parse($oldAt) !== null) {
        $action = 'set';
        $at = $oldAt;
    }

    $shown = match ($action) {
        'set' => \Carbon\CarbonImmutable::createFromFormat('!'.\App\Support\Helpdesk\DueDateInput::FORMAT, $at, $timezone)->format('d M Y H:i'),
        'restore' => $print($sla),
        default => $print($effective),
    };

    $slaText = $print($sla) ?? 'none set';
    $errorIds = collect(['due_action', 'due_at'])->filter(fn ($key) => $errors->has($key))->map(fn ($key) => $key.'-error')->implode(' ');
    $describedBy = trim('due-date-tip due-date-editor-message '.$errorIds);

    $segment = 'relative flex -ms-px first:ms-0 rounded-none first:rounded-s-control last:rounded-e-control focus-within:z-10';
    $trigger = 'peer group inline-flex h-9 pointer-coarse:h-11 w-full items-center gap-2 rounded-[inherit] border px-3 text-sm font-medium transition-[color,background-color,border-color] duration-motion-fast ease-motion focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus '
        .'border-control-edge bg-surface text-text hover:brightness-95 active:brightness-90 data-pending:border-dashed aria-expanded:brightness-95 '
        .'data-[urgency=none]:text-text-secondary data-[urgency=none]:hover:bg-surface-hover '
        .'data-[urgency=ok]:border-success data-[urgency=ok]:text-success '
        .'data-[urgency=soon]:border-warning-glyph data-[urgency=soon]:bg-warning-soft data-[urgency=soon]:text-warning '
        .'data-[urgency=late]:border-danger data-[urgency=late]:bg-danger-soft data-[urgency=late]:text-danger';
    $glyph = 'hidden size-4 shrink-0 ';
@endphp

<div data-due-control data-tooltip-host
     data-timezone="{{ $timezone }}"
     data-zone-label="{{ $zone }}"
     data-server-now="{{ now()->getTimestamp() * 1000 }}"
     data-max-days="{{ $days }}"
     data-original-wall="{{ $wall($effective) }}"
     data-original-epoch="{{ $millis($effective) }}"
     data-sla-wall="{{ $wall($sla) }}"
     data-sla-epoch="{{ $millis($sla) }}"
     data-sla-text="{{ $slaText }}"
     data-has-override="{{ $hasOverride ? '1' : '0' }}"
     class="group/tip {{ $segment }}">
    <input type="hidden" name="due_action" value="{{ $action }}" data-due-action>
    <input type="hidden" name="due_at" value="{{ $at }}" data-due-at>

    <button type="button" data-due-trigger data-urgency="none" @if ($action !== 'keep') data-pending="{{ $action }}" @endif @if ($errorIds !== '') aria-invalid="true" @endif
            aria-expanded="false" aria-controls="due-date-editor"
            aria-describedby="{{ $describedBy }}"
            class="{{ $trigger }}">
        <svg viewBox="0 0 24 24" class="{{ $glyph }}group-data-[urgency=none]:block" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" data-glyph="calendar"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
        <svg viewBox="0 0 24 24" class="{{ $glyph }}group-data-[urgency=ok]:block" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" data-glyph="clock"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        <svg viewBox="0 0 24 24" class="{{ $glyph }}group-data-[urgency=soon]:block" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" data-glyph="triangle-alert"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
        <svg viewBox="0 0 24 24" class="{{ $glyph }}group-data-[urgency=late]:block" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" data-glyph="octagon-alert"><path d="M12 16h.01"/><path d="M12 8v4"/><path d="M15.312 2a2 2 0 0 1 1.414.586l4.688 4.688A2 2 0 0 1 22 8.688v6.624a2 2 0 0 1-.586 1.414l-4.688 4.688a2 2 0 0 1-1.414.586H8.688a2 2 0 0 1-1.414-.586l-4.688-4.688A2 2 0 0 1 2 15.312V8.688a2 2 0 0 1 .586-1.414l4.688-4.688A2 2 0 0 1 8.688 2z"/></svg>
        <span class="sr-only">Due date:</span>
        <span data-due-text class="tabular-nums">{{ $shown ?? 'No due date' }}</span>
        <span data-due-zone class="text-xs font-normal" @if ($shown === null) hidden @endif>{{ $zone }}</span>
        <span data-due-pending-mark class="inline-flex" @if ($action === 'keep') hidden @endif><x-ui.tag>Pending</x-ui.tag></span>
    </button>

    <div id="due-date-editor" data-due-editor hidden role="group" aria-labelledby="due-date-editor-title"
         class="absolute -top-14 left-(--due-editor-x) z-20 w-80 max-w-(--due-editor-max) translate-y-(--due-editor-y) rounded-overlay bg-surface p-4 text-text shadow-overlay">
        <div class="flex items-baseline justify-between gap-3">
            <p id="due-date-editor-title" class="text-sm font-semibold">Change due date</p>
            <span id="due-date-editor-zone" class="text-xs font-medium text-text-muted">{{ $zone }}</span>
        </div>
        <input type="datetime-local" data-due-input form="due-date-editor-detached"
               aria-label="Due date and time ({{ $zone }})" aria-describedby="due-date-editor-help due-date-editor-message"
               min="{{ $min }}" max="{{ $max }}"
               class="mt-3 block h-10 pointer-coarse:h-12 w-full min-w-0 rounded-control border border-control-edge bg-surface px-3 text-sm text-text transition-[border-color,background-color,outline-color] duration-motion-fast ease-motion hover:border-text-muted focus-visible:border-focus focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-focus/30 aria-invalid:border-danger aria-invalid:bg-danger-soft">
        {{-- What the field holds, in words: on a touch screen the tooltip is out of reach. Always one line tall, so it never moves. --}}
        <p id="due-date-editor-help" data-due-relative class="mt-2 min-h-4 text-xs text-text-secondary"></p>
        <p id="due-date-editor-message" role="alert" hidden class="mt-1 text-xs font-medium text-danger"></p>
        <div data-due-actions @if ($action === 'keep' && ! $hasOverride) hidden @endif class="mt-3 flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-t border-rule pt-3">
            @if ($hasOverride)
            <x-ui.button data-due-restore variant="secondary" size="sm" :hidden="$action === 'restore'" title="Restore the original SLA deadline ({{ $slaText }})">Restore SLA <span class="font-normal text-text-secondary tabular-nums">{{ $slaText }}</span></x-ui.button>
            @endif
            <x-ui.button data-due-discard variant="ghost" size="sm" class="ms-auto" :hidden="$action === 'keep'">Undo change</x-ui.button>
        </div>
        <p class="mt-3 hidden text-xs text-text-muted pointer-fine:block">Enter to apply · Esc to cancel</p>
    </div>

    <span id="due-date-tip" role="tooltip" data-due-tip class="invisible absolute bottom-full left-0 z-20 mb-2 w-max max-w-72 rounded-control bg-ink px-2 py-1 text-xs text-on-ink opacity-0 transition-opacity duration-motion-fast ease-motion peer-hover:visible peer-hover:opacity-100 peer-focus-visible:visible peer-focus-visible:opacity-100 hover:visible hover:opacity-100 before:absolute before:top-full before:left-0 before:h-2 before:w-full group-data-dismissed/tip:invisible! group-data-editing/tip:hidden"></span>
</div>
