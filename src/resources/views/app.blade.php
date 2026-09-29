{{--
    The Inertia root. Nothing of the React shell paints before JavaScript (ssr: false), but it shares
    the Blade root's pre-paint bootstrap (EPIC-013 §14.4) so html[data-theme] and html[data-drawer]
    are resolved by one script on both sides of every renderer crossing. The two inputs it stamps come
    from the navigation prop this response already resolved — the builder is not invoked again.
--}}
@php($shellRoot = \App\View\Composers\ShellComposer::rootState($page['props']['navigation'] ?? null))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light"
      @if ($shellRoot['workspace']) data-workspace="{{ $shellRoot['workspace'] }}" @endif
      @if ($shellRoot['drawerDefault']) data-drawer-default="{{ $shellRoot['drawerDefault'] }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    @include('layouts.partials.shell.bootstrap')

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="icon" type="image/svg+xml" href="/favicon.svg">

    @include('layouts.partials.font-preloads')

    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="min-h-screen antialiased">
    @inertia
</body>
</html>
