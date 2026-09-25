{{--
    Main navigation bar.
    No Alpine.js dependency — all interactivity handled with vanilla JS below.
--}}
@php
    $navigationGroups = app(\App\Shared\Navigation\NavigationBuilder::class)->build(request());
    $primaryNavigation = collect($navigationGroups)->firstWhere('key', 'primary')['items'] ?? [];
    $managementNavigation = collect($navigationGroups)->firstWhere('key', 'management')['items'] ?? [];
    $navLink = fn (bool $active): string =>
        'rounded-md px-3 py-2 text-sm font-medium transition-colors ' . (
            $active
                ? 'legacy-bg-surface legacy-text-primary'
                : 'legacy-text-secondary hover:legacy-bg-surface hover:text-primary'
        );
@endphp

<nav class="sticky top-0 z-40 border-b legacy-border-base legacy-bg-base legacy-shadow-theme-sm" aria-label="Primary navigation">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between">

            {{-- ── Logo + Desktop nav links ─────────────────────── --}}
            <div class="flex items-center gap-1">
                <a href="{{ route('dashboard') }}"
                   class="mr-4 flex items-center gap-2 text-lg font-semibold legacy-text-primary"
                   aria-label="Intechral Client Portal home">
                    Intechral Portal
                </a>

                @auth
                <div class="hidden md:flex items-center gap-0.5">
                    @foreach ($primaryNavigation as $item)
                    <a href="{{ $item['href'] }}" class="{{ $navLink($item['isActive']) }}">{{ $item['label'] }}</a>
                    @endforeach
                </div>
                @endauth
            </div>

            {{-- ── Right side: theme toggle + user menu ─────────── --}}
            <div class="flex items-center gap-1">

                {{-- Theme toggle --}}
                <button id="theme-toggle" type="button" aria-label="Toggle colour theme"
                        class="rounded-md p-2 legacy-text-secondary hover:legacy-bg-surface hover:text-primary transition-colors">
                    {{-- Moon — shown in light mode --}}
                    <svg id="icon-moon" class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                    </svg>
                    {{-- Sun — shown in dark mode --}}
                    <svg id="icon-sun" class="h-5 w-5 hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                    </svg>
                </button>

                @auth
                {{-- ── User menu ─────────────────────────────────── --}}
                <div class="relative">
                    <button id="user-menu-btn"
                            type="button"
                            aria-expanded="false"
                            aria-haspopup="true"
                            aria-controls="user-menu"
                            class="flex items-center gap-1.5 rounded-md px-3 py-2 text-sm font-medium legacy-text-secondary hover:legacy-bg-surface hover:text-primary transition-colors">
                        <span>{{ auth()->user()->name }}</span>
                        <svg class="h-4 w-4 transition-transform duration-150" id="user-menu-chevron"
                             xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                        </svg>
                    </button>

                    <div id="user-menu"
                         role="menu"
                         aria-labelledby="user-menu-btn"
                         class="hidden absolute right-0 mt-1 w-52 rounded-lg border py-1 legacy-shadow-theme-lg z-50"
                         style="background-color: var(--bg-elevated); border-color: var(--border-base);">

                        {{-- User info header --}}
                        <div class="px-4 py-2 border-b" style="border-color: var(--border-subtle);">
                            <p class="text-xs font-semibold truncate" style="color: var(--text-primary);">{{ auth()->user()->name }}</p>
                            <p class="text-xs truncate" style="color: var(--text-muted);">{{ auth()->user()->email }}</p>
                        </div>

                        {{-- Profile --}}
                        <a href="{{ route('profile.show') }}"
                           role="menuitem"
                           class="block px-4 py-2 text-sm transition-colors {{ request()->routeIs('profile.*') ? 'legacy-text-primary' : 'legacy-text-secondary hover:text-primary' }}"
                           style="{{ request()->routeIs('profile.*') ? 'background-color: var(--bg-surface);' : '' }}">
                            Profile
                        </a>

                        {{-- Operator management links --}}
                        @if ($managementNavigation)
                        <div class="my-1 border-t" style="border-color: var(--border-subtle);"></div>
                        <p class="px-4 py-1 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Manage</p>
                        @foreach ($managementNavigation as $item)
                        <a href="{{ $item['href'] }}"
                           role="menuitem"
                           class="block px-4 py-2 text-sm transition-colors {{ $item['isActive'] ? 'legacy-text-primary' : 'legacy-text-secondary hover:text-primary' }}"
                           style="{{ $item['isActive'] ? 'background-color: var(--bg-surface);' : '' }}">
                            {{ $item['label'] }}
                        </a>
                        @endforeach
                        @endif

                        {{-- Sign out --}}
                        <div class="my-1 border-t" style="border-color: var(--border-subtle);"></div>
                        <form method="POST" action="{{ route('logout') }}" role="none">
                            @csrf
                            <button type="submit"
                                    role="menuitem"
                                    class="w-full px-4 py-2 text-left text-sm legacy-text-secondary hover:text-primary transition-colors">
                                Sign out
                            </button>
                        </form>
                    </div>
                </div>

                {{-- Mobile hamburger --}}
                <button id="mobile-menu-btn"
                        type="button"
                        aria-expanded="false"
                        aria-controls="mobile-menu"
                        aria-label="Open navigation menu"
                        class="md:hidden rounded-md p-2 legacy-text-secondary hover:legacy-bg-surface hover:text-primary transition-colors ml-1">
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                </button>

                @else
                <a href="{{ route('login') }}"
                   class="rounded-md px-4 py-2 text-sm font-medium legacy-text-secondary hover:legacy-bg-surface hover:text-primary transition-colors">
                    Sign in
                </a>
                @endauth
            </div>
        </div>
    </div>

    {{-- ── Mobile nav menu ──────────────────────────────────── --}}
    @auth
    <div id="mobile-menu"
         class="hidden border-t md:hidden"
         style="border-color: var(--border-base); background-color: var(--bg-base);">
        <div class="px-4 py-3 space-y-1">
            @foreach ($primaryNavigation as $item)
            <a href="{{ $item['href'] }}"
               class="block rounded-md px-3 py-2 text-sm font-medium transition-colors {{ $item['isActive'] ? 'legacy-bg-surface legacy-text-primary' : 'legacy-text-secondary hover:legacy-bg-surface hover:text-primary' }}">
                {{ $item['label'] }}
            </a>
            @endforeach
        </div>
    </div>
    @endauth
</nav>

<script>
(function () {
    'use strict';

    var html = document.documentElement;

    // ── Theme toggle ──────────────────────────────────────────
    var themeBtn  = document.getElementById('theme-toggle');
    var iconMoon  = document.getElementById('icon-moon');
    var iconSun   = document.getElementById('icon-sun');

    function applyTheme(theme) {
        html.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);
        if (theme === 'dark') {
            iconMoon && iconMoon.classList.add('hidden');
            iconSun  && iconSun.classList.remove('hidden');
        } else {
            iconMoon && iconMoon.classList.remove('hidden');
            iconSun  && iconSun.classList.add('hidden');
        }
    }

    // Sync icons with whatever theme the FOUC script already applied
    applyTheme(html.getAttribute('data-theme') || 'light');

    themeBtn && themeBtn.addEventListener('click', function () {
        applyTheme(html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    });

    // ── User dropdown ─────────────────────────────────────────
    var menuBtn     = document.getElementById('user-menu-btn');
    var menuEl      = document.getElementById('user-menu');
    var menuChevron = document.getElementById('user-menu-chevron');

    function openMenu() {
        menuEl.classList.remove('hidden');
        menuBtn.setAttribute('aria-expanded', 'true');
        menuChevron && menuChevron.classList.add('rotate-180');
    }

    function closeMenu() {
        menuEl && menuEl.classList.add('hidden');
        menuBtn && menuBtn.setAttribute('aria-expanded', 'false');
        menuChevron && menuChevron.classList.remove('rotate-180');
    }

    menuBtn && menuBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        menuEl.classList.contains('hidden') ? openMenu() : closeMenu();
    });

    document.addEventListener('click', function (e) {
        if (menuEl && !menuEl.contains(e.target) && e.target !== menuBtn) {
            closeMenu();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMenu();
    });

    // ── Mobile menu ───────────────────────────────────────────
    var mobileBtn  = document.getElementById('mobile-menu-btn');
    var mobileMenu = document.getElementById('mobile-menu');

    mobileBtn && mobileBtn.addEventListener('click', function () {
        var isHidden = mobileMenu.classList.contains('hidden');
        mobileMenu.classList.toggle('hidden', !isHidden);
        mobileBtn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
    });

})();
</script>
