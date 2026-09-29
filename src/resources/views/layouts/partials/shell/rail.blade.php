{{--
    EPIC-013 WP5 — the 64px workspace rail, the Blade twin of components/shell/rail.tsx (§13.2, §14.1).

    It renders `$navigation['workspaces']` exactly as the server sent them: in the server's order, with
    the server's labels, hrefs and `isActive`. It filters nothing, infers no permission, matches no URL
    and hard-codes no workspace — all nine identities, including `resources`, render through this one
    loop (L14, §12.3 rule 1). Every destination is a plain `<a href>` in normal Tab order (L11): from a
    Blade page there is no client router, so `document` and `inertia` destinations are both full page
    loads, and an `inertia` destination simply boots the React app on arrival. Nothing here reads the
    URL to decide how to navigate.

    `nav "Workspaces"` wraps the workspace links only; the brand, the panel toggle and the account
    control sit outside the landmark (the A1.9 divergence the pre-WP5 bar had).
--}}
@php
    $shellFocusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';
@endphp
<div data-shell-rail>
    <span class="flex h-9 w-9 shrink-0 items-center justify-center" data-shell-brand>
        @include('layouts.partials.shell.brand-mark', [
            'variant' => 'compact',
            'label' => 'Intechral',
            'class' => 'block [&>svg]:h-7 [&>svg]:w-7',
        ])
    </span>

    @if ($shellRoot['hasPanel'])
        {{-- Shown by CSS only while the panel is collapsed at a width where Blade can dock it (§14.2). --}}
        <button type="button"
                data-shell-toggle
                aria-expanded="false"
                aria-controls="shell-drawer"
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-control text-text-muted transition-colors duration-motion-fast hover:bg-surface-hover hover:text-text {{ $shellFocusRing }}">
            @include('layouts.partials.shell.icon', ['name' => 'panel-left', 'class' => 'h-4 w-4'])
            <span class="sr-only">Show workspace views</span>
        </button>
    @endif

    @if ($navigation['workspaces'] !== [])
        <nav aria-label="Workspaces"
             data-shell-workspaces
             class="flex w-full min-w-0 flex-col items-center gap-1 px-1.5">
            @foreach ($navigation['workspaces'] as $workspace)
                <a href="{{ $workspace['href'] }}"
                   @if ($workspace['isActive']) aria-current="page" @endif
                   data-shell-nav-row
                   class="flex h-[46px] w-[52px] flex-col items-center justify-center gap-1 rounded-control text-[10px] leading-none transition-colors duration-motion-fast {{ $workspace['isActive'] ? 'bg-surface-selected font-semibold text-text ring-1 ring-rule' : 'text-text-muted hover:bg-surface-hover hover:text-text' }} {{ $shellFocusRing }}">
                    @include('layouts.partials.shell.icon', ['name' => $workspace['icon'], 'class' => 'h-[18px] w-[18px]'])
                    <span class="max-w-full truncate px-0.5">{{ $workspace['label'] }}</span>
                </a>
            @endforeach
        </nav>
    @endif

    <div class="flex shrink-0 items-center gap-1 md:mt-auto md:flex-col">
        @if ($navigation['workspaces'] !== [])
            @include('layouts.partials.shell.nav-sheet')
        @endif

        @if ($shellUser)
            @include('layouts.partials.shell.account-menu')
        @endif
    </div>
</div>
