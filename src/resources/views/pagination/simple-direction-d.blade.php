{{--
    EPIC-016 WP1 §7.4: the Direction D replacement for the vendor `pagination::simple-tailwind` view
    (`Paginator::defaultSimpleView`). Previous / next only, as the vendor view; same treatment as
    pagination/direction-d.
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex items-center justify-between gap-2">
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
    </nav>
@endif
