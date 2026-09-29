{{--
    EPIC-013 WP5 — the narrow-width navigation sheet, the Blade twin of components/shell/nav-sheet.tsx
    (§16, §14.2).

    It holds the same two sets the rail and panel show at wider widths, from the same payload: every
    workspace, then the current workspace's contextual views (actions rendered as actions, never
    current). At S the rail's own list and the panel are removed from the accessibility tree by CSS, so
    these are the single set of workspace links there. Because Blade's panel never floats (§14.2), the
    sheet is also how the panel's content is reached at M.

    A native modal <dialog>: the browser supplies the modal focus containment, inert background, Escape
    and focus return that React gets from Radix, with no hand-rolled trap. blade-shell.ts only opens it,
    closes it on the close button or a backdrop click, and keeps aria-expanded honest.
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
        data-shell-sheet
        aria-labelledby="shell-sheet-title"
        class="fixed inset-y-0 left-0 m-0 h-full max-h-none w-[min(20rem,88vw)] max-w-none overflow-y-auto border-0 border-r border-rule bg-drawer p-0 text-text backdrop:bg-scrim open:animate-overlay-in">
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

        <nav aria-label="Workspaces" class="flex flex-col gap-0.5 px-2 py-3">
            @foreach ($navigation['workspaces'] as $workspace)
                <a href="{{ $workspace['href'] }}"
                   @if ($workspace['isActive']) aria-current="page" @endif
                   data-shell-nav-row
                   class="flex min-h-11 items-center gap-2.5 rounded-control px-2.5 text-sm {{ $workspace['isActive'] ? 'bg-surface-selected font-medium text-text ring-1 ring-rule' : 'text-text-secondary hover:bg-surface-hover hover:text-text' }} {{ $shellFocusRing }}">
                    @include('layouts.partials.shell.icon', ['name' => $workspace['icon'], 'class' => 'h-[18px] w-[18px]'])
                    {{ $workspace['label'] }}
                </a>
            @endforeach
        </nav>

        @if ($shellWorkspace && $shellWorkspace['context'] !== [])
            <nav aria-label="{{ $shellWorkspace['label'] }} views" class="flex flex-col gap-3 border-t border-rule px-2 py-3">
                @foreach ($shellWorkspace['context'] as $section)
                    @php($shellAction = $section['kind'] === 'actions')
                    <div role="group" @if ($section['label']) aria-labelledby="shell-sheet-{{ $section['key'] }}" @endif>
                        @if ($section['label'])
                            <p id="shell-sheet-{{ $section['key'] }}"
                               class="px-2.5 pb-1 text-[11px] font-semibold tracking-wide text-text-muted uppercase">{{ $section['label'] }}</p>
                        @endif
                        <div class="flex flex-col gap-0.5">
                            @foreach ($section['items'] as $item)
                                @php($shellCurrent = ! $shellAction && $item['isActive'])
                                <a href="{{ $item['href'] }}"
                                   @if ($shellCurrent) aria-current="page" @endif
                                   data-shell-nav-row
                                   class="flex min-h-11 items-center rounded-control px-2.5 text-sm {{ $shellCurrent ? 'bg-surface-selected font-medium text-text ring-1 ring-rule' : 'text-text-secondary hover:bg-surface-hover hover:text-text' }} {{ $shellAction ? 'text-accent' : '' }} {{ $shellFocusRing }}">{{ $item['label'] }}</a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </nav>
        @endif
    </div>
</dialog>
