{{--
    Release 1.0 (ORG-3b): the one header of the Organization workspace, drawn by Overview, People and Portal access. `$header` is built by
    OrganizationWorkspacePresenter; this view decides nothing about who may see what.

    The breadcrumb is the shell's (the utility bar: Directory / Organizations / name), so it is not drawn twice. The tabs are page navigation, not an ARIA tab widget:
    a labelled `nav` of ordinary links with `aria-current="page"` on the page you are on, the grammar the Projects pages use (no tablist, tab or aria-selected). Each tab is
    a separate page, so the browser's own Back and Forward move between them. At a narrow width the strip scrolls inside itself rather than wrapping.

    The `actions` slot (optional): already-authorized actions for this page, drawn beside the title.
--}}
@props(['header'])

<header class="mb-6 flex flex-col gap-3">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="mb-0.5 font-mono text-xs uppercase tracking-wide text-text-muted">{{ $header['classification'] }}</p>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h1 class="text-2xl font-semibold text-text break-words">{{ $header['name'] }}</h1>
                <x-organization.portal-state :state="$header['state']" />
            </div>
            @php($relationship = $header['relationship'])
            {{-- The partner and managed-by facts exist only for an actor who may manage relationships (null otherwise: nothing was read). --}}
            @if ($relationship !== null && ($relationship['isPartner'] || $relationship['managedBy'] !== null))
            <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-text-secondary">
                @if ($relationship['isPartner'])
                <span>Partner: manages {{ $relationship['manages'] }} {{ $relationship['manages'] === 1 ? 'customer' : 'customers' }}</span>
                @endif
                @if ($relationship['managedBy'] !== null)
                <span>Managed by <x-ui.link variant="row" :href="route('organizations.show', $relationship['managedBy']['id'])">{{ $relationship['managedBy']['name'] }}</x-ui.link></span>
                @endif
            </p>
            @endif
        </div>
        @if (isset($actions) && $actions->isNotEmpty())
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
        @endif
    </div>

    <div>
        <nav aria-label="Organization sections" class="-mb-px overflow-x-auto">
            <ul role="list" class="flex gap-5 whitespace-nowrap">
                @foreach ($header['tabs'] as $tab)
                @php($active = $tab['key'] === $header['current'])
                <li class="shrink-0">
                    <a href="{{ $tab['href'] }}" @if ($active) aria-current="page" @endif
                       class="inline-flex min-h-9 items-center border-b-2 px-0.5 text-sm transition-colors duration-motion-fast focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus {{ $active ? 'border-text font-semibold text-text' : 'border-transparent font-medium text-text-secondary hover:text-text' }}">{{ $tab['label'] }}</a>
                </li>
                @endforeach
            </ul>
        </nav>
        <div data-strata aria-hidden="true" class="flex flex-col gap-0.5">
            <span class="h-px bg-rule-control"></span>
            <span class="h-px bg-text-faint"></span>
            <span class="h-0.5 bg-rule-strong"></span>
        </div>
    </div>
</header>
