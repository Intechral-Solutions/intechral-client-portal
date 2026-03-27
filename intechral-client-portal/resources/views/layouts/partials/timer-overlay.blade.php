{{--
    Global timer overlay — rendered on every page.
    Shown as a green banner between the nav and main content whenever the
    current user has at least one active timer running.

    The container is always emitted for authenticated users with time.log
    permission so the JS has a reliable mount point. Tiles are injected via
    fetch('/time/timers/active') on DOMContentLoaded; the overlay is hidden
    until at least one tile is present.
--}}
@auth
@can('time.log')
<div id="timer-overlay"
     class="hidden border-b"
     style="background-color: var(--surface-success); border-color: var(--border-success); z-index: 30; position: relative;">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div id="timer-tiles"
             class="flex items-stretch gap-2 overflow-x-auto py-2"
             aria-label="Active timers"
             aria-live="polite">
            {{-- Timer tiles injected by timer-overlay.js --}}
        </div>
    </div>
</div>
@endcan
@endauth
