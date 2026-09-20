<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | This application uses client-side Inertia exclusively. Keep the
    | repository default disabled so package defaults cannot enable SSR.
    |
    */

    'ssr' => [
        'enabled' => (bool) env('INERTIA_SSR_ENABLED', false),
        'runtime' => env('INERTIA_SSR_RUNTIME', 'node'),
        'ensure_bundle_exists' => (bool) env('INERTIA_SSR_ENSURE_BUNDLE_EXISTS', true),
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),
        'hot_url' => env('INERTIA_SSR_HOT_URL', 'http://127.0.0.1:5173/__inertia_ssr'),
        'ensure_server_is_reachable' => (bool) env('INERTIA_SSR_ENSURE_SERVER_IS_REACHABLE', true),
        'throw_on_error' => (bool) env('INERTIA_SSR_THROW_ON_ERROR', true),
    ],

];
