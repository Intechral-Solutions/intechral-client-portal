{{--
    EPIC-013 WP6 — the Blade timer pill and tray, the twin of components/time/timer-pill.tsx and
    timer-tray.tsx (Direction D §12.1, §12.2, §18.4).

    Per ADR-007 and §2 there is no React island on Blade pages, so this is the same affordance drawn
    in Blade markup and driven by resources/js/shell/blade-timer.ts. What it must NOT be is a second
    interpretation of the timer: both renderers read the same `GET /time/timers/active`, derive elapsed
    time, the shown timer and every label through the same shared module (resources/js/lib/timer-state.ts),
    and mutate through the same endpoints. Renderer-specific markup, one set of rules.

    It replaces partials/timer-overlay.blade.php, the full-width strip that used to sit below this bar.
    That also retires the A10.16 stacking defect by construction: there is no strip left to draw over
    the sticky utility bar, because the timer now lives *inside* it.

    Gated on `time.log`, exactly as the React shell gates the pill on the same permission. Visibility is
    not authorization — every timer endpoint keeps its own `can:time.log` middleware and its ownership
    check (§27).

    The root renders HIDDEN. Direction D §15.1: the pill shows the last confirmed state, or nothing on
    first load, and the server knows nothing about this user's timers at render time. A visible idle
    pill painted here would claim that nothing is running, then grow leftward into the running pill when
    the active set arrived — the pill is the last item in the bar, but it is right-aligned, so growing
    moves the pill itself and the shell reports a layout shift (measured 0.0010528120713305898,
    1317,9,107,30 -> 1117,8,307,32). blade-timer.ts reveals the root once, after the first read has
    reached a final result and the confirmed (or unavailable) state is drawn; later refreshes never hide
    it again. Server-rendering the running state instead would mean a timer query on every Blade page
    render, and §18.2 is explicit that WP6 adds no backend work.
--}}
@auth
@can('time.log')
<div class="relative shrink-0" data-shell-timer data-timer-running="false" data-timer-count="0" hidden>
    {{-- Start/stop transitions only. The elapsed digits are deliberately outside this region: a clock
         in a live region announces every second (§13, Direction D §12.1). --}}
    <p class="sr-only" aria-live="polite" data-shell-timer-announce></p>

    <div class="flex min-w-0 max-w-[360px] items-center gap-1">
        <button type="button"
                data-shell-timer-trigger
                aria-haspopup="dialog"
                aria-expanded="false"
                aria-controls="shell-timer-tray"
                aria-label="Start timer"
                class="flex min-w-0 items-center gap-1.5 rounded-control border border-transparent px-2 py-1 text-sm transition-[color,background-color,border-color] duration-motion-fast ease-motion hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
            {{-- Idle glyph and running dot are swapped by the script, never both visible. --}}
            <span data-timer-idle-glyph class="flex shrink-0">@include('layouts.partials.shell.icon', ['name' => 'clock', 'class' => 'size-4 text-text-muted'])</span>
            <span data-timer-dot hidden aria-hidden="true" class="size-2 shrink-0 rounded-full bg-live animate-live-pulse"></span>

            <span data-timer-idle class="text-text-secondary">Start timer</span>
            <span data-timer-pending hidden class="text-text-secondary"></span>
            <span data-timer-elapsed="wide" hidden class="font-mono font-medium tabular-nums text-live-text"></span>
            <span data-timer-elapsed="narrow" hidden class="font-mono font-medium tabular-nums text-live-text"></span>
            <span data-timer-label hidden class="truncate text-text-secondary"></span>
            <span data-timer-badge hidden class="shrink-0 rounded-tag bg-live-soft px-1.5 py-0.5 text-xs font-medium text-live-text"></span>
            <span data-timer-chevron hidden class="flex shrink-0">@include('layouts.partials.shell.icon', ['name' => 'chevron-down', 'class' => 'size-3.5 text-text-muted'])</span>
        </button>

        {{-- A sibling of the trigger, never nested inside it: a button inside a button is invalid
             markup and the inner control becomes unreachable. --}}
        <button type="button"
                data-shell-timer-stop
                hidden
                class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-control border border-transparent px-2.5 text-xs font-medium text-text transition-colors duration-motion-fast hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
            @include('layouts.partials.shell.icon', ['name' => 'square-filled', 'class' => 'size-3'])
            <span data-timer-stop-label>Stop</span>
        </button>

        <span role="alert" hidden data-shell-timer-error class="shrink-0 text-xs text-danger">Couldn’t stop</span>
    </div>

    {{-- Not a native <dialog>: the tray is non-modal (Direction D §12.2), so it must not take the page
         hostage the way the nav sheet's modal dialog deliberately does. Blade has no portal, so it is
         positioned inside the bar and blade-shell CSS lifts the bar while it is open (the same
         stacking fix the rail needed for the account menu, A10.12 #1). --}}
    {{-- `tabindex="-1"` makes the tray itself the focus target on open: focusing the first row's
         description field instead would drop the caret into a text box for someone who opened the
         tray to press Stop, and announce an editable field before the list it belongs to. --}}
    <div id="shell-timer-tray"
         role="dialog"
         aria-label="Running timers"
         data-shell-timer-tray
         tabindex="-1"
         hidden
         class="absolute top-full right-0 z-50 mt-1.5 w-[408px] max-w-[calc(100vw-16px)] rounded-overlay bg-surface text-text shadow-overlay">
        <div class="flex items-center justify-between border-b border-rule px-3 py-2">
            <p class="text-xs font-semibold uppercase text-text-muted" data-timer-heading>0 running</p>
            <button type="button"
                    data-timer-retry
                    hidden
                    class="inline-flex h-8 items-center rounded-control border border-control-edge bg-surface px-2.5 text-xs font-medium text-text hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">Retry</button>
        </div>

        <p data-timer-unavailable hidden role="alert" class="px-3 py-4 text-sm text-danger"></p>

        <div data-timer-empty class="px-3 py-4">
            <p class="text-sm text-text">No timers running.</p>
            <p class="mt-1 text-xs text-text-muted">Start one from a task or ticket, or from the Time screen.</p>
        </div>

        <ul data-timer-rows hidden class="max-h-[60vh] overflow-y-auto overscroll-contain"></ul>

        {{-- The zero state links to the existing start flow; "Start another…" search is NEXT (§12.0). --}}
        <div class="border-t border-rule px-3 py-2">
            <a href="{{ route('time.index', [], false) }}"
               class="rounded-control text-sm font-medium text-text-secondary hover:text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">Open Time &rarr;</a>
        </div>
    </div>
</div>
@endcan
@endauth
