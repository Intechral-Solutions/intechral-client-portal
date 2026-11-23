{{--
    EPIC-013 WP5 — the 48px utility bar, the Blade twin of components/shell/utility-bar.tsx and
    breadcrumb.tsx (§13.2, §14.1).

    It never carries workspace navigation. The breadcrumb is the server's truth: the current workspace,
    then its active contextual item — both read from the payload, never matched from the URL — and it
    always ends with the current page. The view switcher React shows while its panel is collapsed is not
    required of Blade (§14.1). Search is NEXT and does not render; the timer pill sits on the right, as it
    does on React (WP6, §18.4).
--}}
@php
    $shellActiveItem = null;

    if ($shellWorkspace) {
        foreach ($shellWorkspace['context'] as $section) {
            if ($section['kind'] === 'actions') {
                continue;
            }

            foreach ($section['items'] as $item) {
                if ($item['isActive']) {
                    $shellActiveItem = $item;
                    break 2;
                }
            }
        }
    }
@endphp
<header data-shell-utility>
    <div class="min-w-0 flex-1">
        @if ($shellWorkspace)
            <nav aria-label="Breadcrumb" class="flex min-w-0 items-center gap-1 text-sm">
                <a href="{{ $shellWorkspace['href'] }}"
                   @if (! $shellActiveItem) aria-current="page" @endif
                   class="truncate rounded-control px-1 py-0.5 text-text-secondary hover:text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">{{ $shellWorkspace['label'] }}</a>

                @if ($shellActiveItem)
                    @include('layouts.partials.shell.icon', ['name' => 'chevron-right', 'class' => 'h-3.5 w-3.5 shrink-0 text-text-faint'])
                    @if (! empty($breadcrumbTrail))
                        {{-- ORG-3b: a record page names the record after its list. The page passes `breadcrumbTrail` (a list of [label, href|null], the last being the
                             current page); the active item above becomes a link back to its list. Absent, the trail is exactly the two crumbs it always was. --}}
                        <a href="{{ $shellActiveItem['href'] }}"
                           class="truncate rounded-control px-1 py-0.5 text-text-secondary hover:text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">{{ $shellActiveItem['label'] }}</a>
                        @foreach ($breadcrumbTrail as $crumb)
                            @include('layouts.partials.shell.icon', ['name' => 'chevron-right', 'class' => 'h-3.5 w-3.5 shrink-0 text-text-faint'])
                            @if ($loop->last || empty($crumb[1]))
                                <span class="truncate px-1 font-medium text-text" aria-current="page">{{ $crumb[0] }}</span>
                            @else
                                <a href="{{ $crumb[1] }}"
                                   class="truncate rounded-control px-1 py-0.5 text-text-secondary hover:text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">{{ $crumb[0] }}</a>
                            @endif
                        @endforeach
                    @else
                        <span class="truncate px-1 font-medium text-text" aria-current="page">{{ $shellActiveItem['label'] }}</span>
                    @endif
                @endif
            </nav>
        @else
            <p class="truncate text-sm text-text-muted">Intechral</p>
        @endif
    </div>

    @include('layouts.partials.shell.timer')
</header>
