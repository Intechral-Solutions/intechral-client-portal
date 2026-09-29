{{--
    EPIC-013 WP5 — the rail account control and personal-only account menu, the Blade twin of
    components/shell/account-menu.tsx (§17, L9, L15).

    PERSONAL ONLY. This partial receives no navigation data at all, so an administrative entry has no
    way to arrive here, and the retired "Manage" grouping is structurally impossible (§12.4). The
    destinations are the committed ones: Profile and its three existing sections (§17.2 — no new routes),
    Appearance (Light/Dark only, L6), a disabled Notifications row with its reason, and Sign out.

    The trigger is a 40×40 rounded-square button containing a 28px circular avatar (L9). The initials
    are the server's (`$shellUser['avatar']['initials']`, App\Support\Initials) — never derived here.

    Behaviour (resources/js/shell/blade-shell.ts): a real menu — the trigger toggles it, arrow keys /
    Home / End move between items, Escape closes and returns focus to the trigger, Tab or a pointer
    outside closes it. Appearance writes localStorage['theme'] and html[data-theme], the same contract
    the React control and the shared pre-paint bootstrap use.
--}}
@php
    $shellItem = 'block cursor-pointer rounded-control px-3 py-2 text-sm text-text hover:bg-surface-hover focus:bg-surface-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus';
    $shellProfile = route('profile.show', [], false);
@endphp
<div class="relative" data-shell-account-root>
    <button type="button"
            data-shell-account
            aria-haspopup="menu"
            aria-expanded="false"
            aria-controls="shell-account-menu"
            aria-label="Account menu: {{ $shellUser['name'] }}"
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[6px] bg-surface ring-1 ring-control-edge transition-colors duration-motion-fast hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
        {{-- The trigger already carries the accessible name, so the avatar is decorative. --}}
        <span aria-hidden="true"
              class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-rule-control bg-surface-sunken text-xs font-semibold text-text select-none">{{ $shellUser['avatar']['initials'] }}</span>
    </button>

    <div id="shell-account-menu"
         role="menu"
         aria-label="Account"
         data-shell-account-menu
         hidden
         class="absolute top-full right-0 z-50 mt-1.5 w-64 rounded-overlay bg-surface p-1 text-text shadow-overlay md:top-auto md:right-auto md:bottom-0 md:left-full md:mt-0 md:ml-1.5">
        <div class="flex items-center gap-2.5 px-3 py-2.5">
            <span aria-hidden="true"
                  class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-rule-control bg-surface-sunken text-sm font-semibold text-text select-none">{{ $shellUser['avatar']['initials'] }}</span>
            <div class="min-w-0">
                <p class="truncate text-sm font-medium text-text">{{ $shellUser['name'] }}</p>
                <p class="truncate text-xs text-text-muted">{{ $shellUser['email'] }}</p>
            </div>
        </div>

        <div role="separator" class="my-1 h-px bg-rule"></div>

        <a role="menuitem" tabindex="-1" href="{{ $shellProfile }}" class="{{ $shellItem }}">Profile</a>
        <a role="menuitem" tabindex="-1" href="{{ $shellProfile }}#security" class="{{ $shellItem }}">Security &amp; MFA</a>
        <a role="menuitem" tabindex="-1" href="{{ $shellProfile }}#connected-accounts" class="{{ $shellItem }}">Connected accounts</a>
        <a role="menuitem" tabindex="-1" href="{{ $shellProfile }}#sessions" class="{{ $shellItem }}">Sessions</a>

        <div role="separator" class="my-1 h-px bg-rule"></div>

        {{-- Appearance absorbs the standalone theme toggle the pre-WP5 Blade bar carried (§17.2). --}}
        <div class="px-3 py-2">
            <div role="group"
                 aria-label="Appearance"
                 class="flex items-center gap-1 rounded-control bg-surface-sunken p-0.5">
                @foreach (['light', 'dark'] as $shellAppearance)
                    <button type="button"
                            role="menuitemradio"
                            tabindex="-1"
                            aria-checked="false"
                            data-shell-appearance="{{ $shellAppearance }}"
                            class="flex-1 rounded-[3px] px-2 py-1 text-xs font-medium capitalize text-text-secondary transition-colors duration-motion-fast hover:text-text aria-checked:bg-ink aria-checked:text-on-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">{{ $shellAppearance }}</button>
                @endforeach
            </div>
        </div>

        {{-- A disabled row with a reason — never the mockups' FUTURE tag as product chrome (§17.2). --}}
        <div role="menuitem"
             aria-disabled="true"
             class="flex items-center justify-between rounded-control px-3 py-2 text-sm text-text-muted">
            <span>Notifications</span>
            <span class="text-xs text-text-faint">Not available yet</span>
        </div>

        <div role="separator" class="my-1 h-px bg-rule"></div>

        <form method="POST" action="{{ route('logout', [], false) }}" role="none">
            @csrf
            <button type="submit" role="menuitem" tabindex="-1" class="w-full text-left {{ $shellItem }}">Sign out</button>
        </form>
    </div>
</div>
