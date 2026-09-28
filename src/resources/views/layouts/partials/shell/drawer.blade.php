{{--
    EPIC-013 WP5 — the simplified Blade contextual panel (§14.2), the twin of components/shell/drawer.tsx.

    It projects the current workspace's `context`; it does not own it. Sections and items arrive
    ordered, labelled, capability-filtered and pruned by the server, and render in that order.
    `kind: 'actions'` renders as an action and never carries `aria-current`, even if the server ever
    regressed (A1.10 req. 2). An unknown `kind` falls through to a plain section. `count` is reserved and
    always null (§12.3 rule 8), so no count slot renders.

    Blade's panel is DOCKED OR HIDDEN, never an overlay, with no pin and no focus trap (§14.2). Whether
    it shows is pure CSS off html[data-drawer] (set pre-paint by the shared bootstrap) and the width
    classes; at M/S it is never shown and its content is in the nav sheet instead.

    Neither the workspace name nor the section labels are headings: that would put a second
    "Helpdesk" above the page's own <h1> (the WP4 finding, A8.10).

    @param array $shellWorkspace
--}}
@php
    $shellFocusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';
    // Double-quoted so the strip's empty `content` value can be written literally: the selected row
    // carries the panel's 2px accent-line strip, the only one on the screen (Direction D §8).
    $shellRowCurrent = "bg-surface-selected font-medium text-text ring-1 ring-rule before:absolute before:inset-y-1 before:left-0 before:w-0.5 before:rounded-full before:bg-accent-line before:content-['']";
    $shellRowIdle = 'text-text-secondary hover:bg-surface-hover hover:text-text';
@endphp
<div id="shell-drawer" data-shell-drawer>
    <div class="flex h-12 shrink-0 items-center gap-1 border-b border-rule px-3">
        <p class="min-w-0 flex-1 truncate text-xs font-semibold tracking-wide text-text-muted uppercase">
            {{ $shellWorkspace['label'] }}
        </p>

        <button type="button"
                data-shell-collapse
                aria-expanded="true"
                aria-controls="shell-drawer"
                class="flex h-7 w-7 items-center justify-center rounded-control text-text-muted transition-colors duration-motion-fast hover:bg-surface-hover hover:text-text {{ $shellFocusRing }}">
            @include('layouts.partials.shell.icon', ['name' => 'panel-left-close', 'class' => 'h-3.5 w-3.5'])
            <span class="sr-only">Collapse workspace views</span>
        </button>
    </div>

    <nav aria-label="{{ $shellWorkspace['label'] }} views" class="flex flex-col gap-4 px-2 py-3">
        @foreach ($shellWorkspace['context'] as $section)
            @php($shellAction = $section['kind'] === 'actions')
            <div role="group" @if ($section['label']) aria-labelledby="shell-drawer-{{ $section['key'] }}" @endif>
                @if ($section['label'])
                    <p id="shell-drawer-{{ $section['key'] }}"
                       class="px-2 pb-1.5 text-[11px] font-semibold tracking-wide text-text-muted uppercase">{{ $section['label'] }}</p>
                @endif

                <div class="flex flex-col gap-0.5">
                    @foreach ($section['items'] as $item)
                        @php($shellCurrent = ! $shellAction && $item['isActive'])
                        <a href="{{ $item['href'] }}"
                           @if ($shellCurrent) aria-current="page" @endif
                           data-shell-nav-row
                           class="relative flex items-center gap-2 rounded-control py-1.5 pr-2 pl-3 text-sm transition-colors duration-motion-fast {{ $shellCurrent ? $shellRowCurrent : $shellRowIdle }} {{ $shellAction ? 'text-accent' : '' }} {{ $shellFocusRing }}">
                            @if ($shellAction)
                                @include('layouts.partials.shell.icon', ['name' => 'plus', 'class' => 'h-3.5 w-3.5 shrink-0'])
                            @endif
                            <span class="min-w-0 flex-1 truncate">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>
</div>
