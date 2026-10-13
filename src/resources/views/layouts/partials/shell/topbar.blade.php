{{--
    EPIC-017 WP1 — the customer (Focused) top bar, the Blade twin of components/shell/customer-shell.tsx
    and top-nav.tsx (Direction D §7).

    It renders `$navigation['workspaces']` exactly as the server projected them for the Focused
    presentation: in the server's order, with the server's labels, hrefs and `isActive`. It filters
    nothing, infers no permission, matches no URL and hard-codes no destination (L14). Every link is a
    plain `<a href>` in normal Tab order (no roving tabindex); CSS hides the list below 900px, where the
    menu sheet carries the same links, so they are never both in the accessibility tree.

    The header holds the brand, the `nav "Main"` landmark, then the menu button and the account
    control — the account control sits outside the nav. The shell adds no heading and no timer.
--}}
@php
    $shellFocusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';
@endphp
<header data-shell-topbar>
    <span data-shell-brand class="flex shrink-0 items-center gap-2">
        {{-- The mark carries the name; the visible wordmark repeats it, so it is hidden from assistive
             technology rather than announced twice. --}}
        @include('layouts.partials.shell.brand-mark', [
            'variant' => 'compact',
            'label' => 'Intechral',
            'class' => 'block [&>svg]:h-7 [&>svg]:w-7',
        ])
        <span aria-hidden="true" class="text-sm font-semibold text-text">Intechral</span>
    </span>

    @if ($navigation['workspaces'] !== [])
        <nav aria-label="Main" data-shell-topnav>
            @foreach ($navigation['workspaces'] as $workspace)
                <a href="{{ $workspace['href'] }}"
                   @if ($workspace['isActive']) aria-current="page" @endif
                   data-shell-nav-row
                   class="flex items-center border-b-2 px-3 text-sm transition-colors duration-motion-fast {{ $workspace['isActive'] ? 'border-accent-line font-semibold text-text' : 'border-transparent text-text-secondary hover:text-text' }} {{ $shellFocusRing }}">{{ $workspace['label'] }}</a>
            @endforeach
        </nav>
    @endif

    <div data-shell-topbar-end>
        @if ($navigation['workspaces'] !== [])
            @include('layouts.partials.shell.focused-sheet')
        @endif

        @if ($shellUser)
            @include('layouts.partials.shell.account-menu', ['placement' => 'topbar'])
        @endif
    </div>
</header>
