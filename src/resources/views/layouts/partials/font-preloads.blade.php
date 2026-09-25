{{--
    EPIC-013 WP1d — preload the two critical Direction D faces, IBM Plex Sans 400 and 500 (latin).
    Included by both root views (app.blade.php and layouts/app.blade.php). The hrefs come from the
    Vite manifest, so they are the same fingerprinted URLs the @font-face rules in
    resources/css/app.css resolve to; with `crossorigin` the browser reuses the preload instead of
    fetching the font twice. Plex Sans 600, Plex Mono, Newsreader and the latin-ext faces are not
    preloaded: they load on first use.
--}}
<link rel="preload" href="{{ Vite::asset('resources/fonts/ibm-plex-sans-latin-400-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="{{ Vite::asset('resources/fonts/ibm-plex-sans-latin-500-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
