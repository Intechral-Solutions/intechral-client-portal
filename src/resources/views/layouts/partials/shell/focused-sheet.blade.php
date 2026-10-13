{{--
    EPIC-017 WP1 — the customer menu sheet, the Blade twin of components/shell/customer-menu-sheet.tsx
    (Direction D §5.2, §7). Below 900px the top bar's links fold into this sheet, from the same payload.
    It holds the Focused links only: the account control stays in the bar, and there is no contextual
    section because the Focused shell has no drawer.

    A native modal <dialog>: the browser supplies the modal focus containment, inert background, Escape
    and focus return that React gets from Radix, with no hand-rolled trap. blade-shell.ts only opens it,
    closes it on the close button or a backdrop click, and keeps aria-expanded honest.

    Its width is `[data-shell-sheet='focused']` in app.css, shared with React. `left-auto` releases the
    dialog's user-agent `left: 0`, so `right-0` anchors it to the right edge.
--}}
@php
    $shellFocusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';
@endphp
<button type="button"
        data-shell-sheet-trigger
        aria-haspopup="dialog"
        aria-expanded="false"
        aria-controls="shell-sheet"
        aria-label="Open navigation"
        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-control text-text-secondary transition-colors duration-motion-fast hover:bg-surface-hover hover:text-text {{ $shellFocusRing }}">
    @include('layouts.partials.shell.icon', ['name' => 'menu', 'class' => 'h-5 w-5'])
</button>

<dialog id="shell-sheet"
        data-shell-sheet="focused"
        aria-labelledby="shell-sheet-title"
        class="fixed inset-y-0 right-0 left-auto m-0 h-full max-h-none max-w-none overflow-y-auto border-0 border-l border-rule bg-surface p-0 text-text backdrop:bg-scrim open:animate-overlay-in">
    {{-- Fills the sheet, so a click that lands on the <dialog> itself is a backdrop click. --}}
    <div class="flex min-h-full flex-col">
        <div class="flex h-14 shrink-0 items-center justify-between border-b border-rule px-3">
            <h2 id="shell-sheet-title" class="text-sm font-semibold text-text">Navigation</h2>
            <button type="button"
                    data-shell-sheet-close
                    aria-label="Close navigation"
                    class="flex h-11 w-11 items-center justify-center rounded-control text-text-muted hover:bg-surface-hover hover:text-text {{ $shellFocusRing }}">
                @include('layouts.partials.shell.icon', ['name' => 'x', 'class' => 'h-5 w-5'])
            </button>
        </div>

        <nav aria-label="Main" class="flex flex-col gap-0.5 px-2 py-3">
            @foreach ($navigation['workspaces'] as $workspace)
                <a href="{{ $workspace['href'] }}"
                   @if ($workspace['isActive']) aria-current="page" @endif
                   data-shell-nav-row
                   class="flex min-h-11 items-center rounded-control px-2.5 text-sm {{ $workspace['isActive'] ? 'bg-surface-selected font-medium text-text ring-1 ring-rule' : 'text-text-secondary hover:bg-surface-hover hover:text-text' }} {{ $shellFocusRing }}">{{ $workspace['label'] }}</a>
            @endforeach
        </nav>
    </div>
</dialog>
