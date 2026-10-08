{{--
    EPIC-013 WP5 — the Blade root, rendering the Direction D Operational shell (§14).

    Blade and React render the same shell from the same server payload (ShellComposer, bound to this
    view): the same data-shell-* structure, so the one unlayered geometry block in app.css lays out
    both renderers identically; the same navigation, active state and authorization result; the same
    account destinations and avatar initials; the same pre-paint bootstrap. Blade's differences are
    exactly the ones §14.2 permits — a docked-or-hidden panel with no overlay, pin or view switcher.

    Page bodies (@yield('content')) are untouched and keep their own containers (§14.3).

    EPIC-017 WP1 — this file is the Blade branch point for the shell presentation, as AppShell is for
    React. `$shell['presentation']` comes from the one ShellPresentationResolver (via ShellComposer): a
    `focused` user gets the customer top bar and a centred column, an `operational` one (and a guest)
    gets the rail, panel and utility bar below. The navigation each reads is the projection the same
    resolver chose, so neither branch filters, matches a URL or computes an active state.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light"
      @if ($shellRoot['workspace']) data-workspace="{{ $shellRoot['workspace'] }}" @endif
      @if ($shellRoot['drawerDefault']) data-drawer-default="{{ $shellRoot['drawerDefault'] }}" @endif
      @if ($shellRoot['drawerSurface']) data-drawer-surface="{{ $shellRoot['drawerSurface'] }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- First script in <head>, before any stylesheet can block it (A1.7 req. 2). --}}
    @include('layouts.partials.shell.bootstrap')

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="icon" type="image/svg+xml" href="/favicon.svg">

    <title>{{ $title ?? config('app.name') }} &mdash; {{ config('app.name') }}</title>
    <meta name="description" content="{{ $description ?? '' }}">

    @include('layouts.partials.font-preloads')

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('head')
</head>
<body class="min-h-screen antialiased">
    @if ($shell['presentation'] === 'focused')
        {{-- The Focused (customer) shell: a 60px top bar and one centred column, no rail, panel,
             utility bar or timer (Direction D §7). Geometry is the same `[data-shell='focused']` block in
             app.css that lays out React, off the same hooks. --}}
        <div data-shell="focused"
             data-shell-renderer="blade">
            {{-- First focusable element; visible on focus, never removed (Direction D §14.1). --}}
            @include('layouts.partials.shell.skip-link')

            @include('layouts.partials.shell.topbar')

            <div data-shell-column>
                {{-- `tabindex="-1"` makes it the skip link's focus target. --}}
                <main id="main-content" tabindex="-1" class="min-w-0 flex-1 outline-none">
                    @yield('content')
                </main>
            </div>
        </div>
    @else
        <div data-shell="operational"
             data-shell-renderer="blade"
             data-panel="{{ $shellRoot['hasPanel'] ? 'true' : 'false' }}">
            {{-- First focusable element; visible on focus, never removed (Direction D §14.1). --}}
            @include('layouts.partials.shell.skip-link')

            @include('layouts.partials.shell.rail')

            @if ($shellRoot['hasPanel'])
                @include('layouts.partials.shell.drawer')
            @endif

            <div data-shell-canvas>
                {{-- Carries the breadcrumb and, on its right, the timer pill (WP6, §18.4). The
                     pre-Direction-D timer strip that used to follow it here is retired. --}}
                @include('layouts.partials.shell.utility-bar')

                {{-- `tabindex="-1"` makes it the skip link's focus target. --}}
                <main id="main-content" tabindex="-1" class="min-w-0 flex-1 outline-none">
                    @yield('content')
                </main>
            </div>
        </div>
    @endif

    @stack('scripts')
</body>
</html>
