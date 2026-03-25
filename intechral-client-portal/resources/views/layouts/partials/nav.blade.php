{{--
    Main navigation bar.
    Renders differently for authenticated vs. guest users.
--}}
<nav class="sticky top-0 z-40 border-b border-base bg-base shadow-theme-sm" aria-label="Primary navigation">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between">

            {{-- Logo --}}
            <div class="flex items-center gap-6">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2" aria-label="Intechral Client Portal home">
                    <span class="text-lg font-semibold text-primary">Intechral Portal</span>
                </a>

                @auth
                <div class="hidden md:flex items-center gap-1">
                    @can('tickets.view')
                    <a href="{{ route('tickets.index') }}"
                       class="rounded-md px-3 py-2 text-sm font-medium text-secondary hover:bg-surface hover:text-primary transition-colors">
                        Tickets
                    </a>
                    @endcan
                    @can('projects.view')
                    <a href="{{ route('projects.index') }}"
                       class="rounded-md px-3 py-2 text-sm font-medium text-secondary hover:bg-surface hover:text-primary transition-colors">
                        Projects
                    </a>
                    @endcan
                    @can('billing.view')
                    <a href="{{ route('billing.index') }}"
                       class="rounded-md px-3 py-2 text-sm font-medium text-secondary hover:bg-surface hover:text-primary transition-colors">
                        Billing
                    </a>
                    @endcan
                    @can('crm.view')
                    <a href="{{ route('crm.index') }}"
                       class="rounded-md px-3 py-2 text-sm font-medium text-secondary hover:bg-surface hover:text-primary transition-colors">
                        CRM
                    </a>
                    @endcan
                </div>
                @endauth
            </div>

            {{-- Right side actions --}}
            <div class="flex items-center gap-3">

                {{-- Theme toggle --}}
                <button
                    id="theme-toggle"
                    type="button"
                    aria-label="Toggle colour theme"
                    class="rounded-md p-2 text-secondary hover:bg-surface hover:text-primary transition-colors"
                >
                    {{-- Sun icon (shown in dark mode) --}}
                    <svg class="hidden h-5 w-5 [data-theme=dark_&]:block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                    </svg>
                    {{-- Moon icon (shown in light mode) --}}
                    <svg class="h-5 w-5 [data-theme=dark_&]:hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                    </svg>
                </button>

                @auth
                {{-- User menu --}}
                <div class="relative" x-data="{ open: false }">
                    <button
                        @click="open = !open"
                        type="button"
                        class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-secondary hover:bg-surface hover:text-primary transition-colors"
                        aria-expanded="false"
                        aria-haspopup="true"
                    >
                        <span>{{ auth()->user()->name }}</span>
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div
                        x-show="open"
                        @click.outside="open = false"
                        x-transition
                        class="absolute right-0 mt-1 w-48 rounded-lg border border-base bg-elevated py-1 shadow-theme-lg"
                    >
                        <a href="{{ route('profile.show') }}" class="block px-4 py-2 text-sm text-secondary hover:bg-surface hover:text-primary">Profile</a>
                        @can('settings.view')
                        <a href="{{ route('settings.index') }}" class="block px-4 py-2 text-sm text-secondary hover:bg-surface hover:text-primary">Settings</a>
                        @endcan
                        <div class="my-1 border-t border-subtle"></div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="w-full px-4 py-2 text-left text-sm text-secondary hover:bg-surface hover:text-primary">
                                Sign out
                            </button>
                        </form>
                    </div>
                </div>
                @else
                <a href="{{ route('login') }}"
                   class="rounded-md px-4 py-2 text-sm font-medium text-secondary hover:bg-surface hover:text-primary transition-colors">
                    Sign in
                </a>
                @endauth
            </div>
        </div>
    </div>
</nav>
