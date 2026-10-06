{{--
    EPIC-016 WP1 §7.4: the Direction D replacement for the vendor `pagination::tailwind` view, registered as
    the application default in AppServiceProvider (`Paginator::defaultView`).

    Behaviour is preserved, not reduced: previous / next, numbered pages with the ellipsis, the
    "Showing x to y of z results" summary, and the compact previous / next on narrow screens. Laravel
    generates every URL, so query strings behave exactly as before. Treatment: a `nav` named
    "Pagination"; links are `x-ui.button` secondary sm; the current page is `aria-current="page"` drawn as
    the selected segment (`bg-ink text-on-ink`); an unavailable direction is omitted, never a fake
    control; the summary is `text-text-secondary tabular-nums`. Every colour is a Direction D semantic
    utility, so both themes follow `data-theme` (the vendor view's `dark:` followed the OS).
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination">

        {{-- Compact: previous / next only. --}}
        <div class="flex items-center justify-between gap-2 sm:hidden">
            @if ($paginator->onFirstPage())
                <span></span>
            @else
                <x-ui.button variant="secondary" size="sm" :href="$paginator->previousPageUrl()" rel="prev">{!! __('pagination.previous') !!}</x-ui.button>
            @endif

            @if ($paginator->hasMorePages())
                <x-ui.button variant="secondary" size="sm" :href="$paginator->nextPageUrl()" rel="next">{!! __('pagination.next') !!}</x-ui.button>
            @else
                <span></span>
            @endif
        </div>

        <div class="hidden sm:flex sm:items-center sm:justify-between sm:gap-4">
            <p class="text-sm text-text-secondary tabular-nums">
                {!! __('Showing') !!}
                @if ($paginator->firstItem())
                    <span class="font-medium text-text">{{ $paginator->firstItem() }}</span>
                    {!! __('to') !!}
                    <span class="font-medium text-text">{{ $paginator->lastItem() }}</span>
                @else
                    {{ $paginator->count() }}
                @endif
                {!! __('of') !!}
                <span class="font-medium text-text">{{ $paginator->total() }}</span>
                {!! __('results') !!}
            </p>

            <div class="flex items-center gap-1">
                @unless ($paginator->onFirstPage())
                    <x-ui.button variant="secondary" size="sm" :href="$paginator->previousPageUrl()" rel="prev" aria-label="{{ html_entity_decode(__('pagination.previous')) }}">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                    </x-ui.button>
                @endunless

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="px-2 text-sm text-text-muted" aria-hidden="true">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page" class="inline-flex h-8 pointer-coarse:h-10 min-w-8 items-center justify-center rounded-control border border-transparent bg-ink px-2.5 text-xs font-medium tabular-nums text-on-ink">{{ $page }}</span>
                            @else
                                <x-ui.button variant="secondary" size="sm" :href="$url" aria-label="{{ __('Go to page :page', ['page' => $page]) }}" class="tabular-nums">{{ $page }}</x-ui.button>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <x-ui.button variant="secondary" size="sm" :href="$paginator->nextPageUrl()" rel="next" aria-label="{{ html_entity_decode(__('pagination.next')) }}">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                        </svg>
                    </x-ui.button>
                @endif
            </div>
        </div>
    </nav>
@endif
