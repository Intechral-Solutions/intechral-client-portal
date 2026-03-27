<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name') }} &mdash; {{ config('app.name') }}</title>
    <meta name="description" content="{{ $description ?? '' }}">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700&family=jetbrains-mono:400,500" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- FOUC prevention: apply saved theme before first paint --}}
    <script>
        (function () {
            var t = localStorage.getItem('theme');
            if (!t) {
                t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
                    ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>

    @stack('head')
</head>
<body class="min-h-screen flex flex-col antialiased" style="background-color: var(--bg-base); color: var(--text-primary);">

    {{-- Navigation --}}
    @include('layouts.partials.nav')

    {{-- Main content --}}
    <main id="main-content" class="flex-1">
        @yield('content')
    </main>

    {{-- Footer --}}
    @include('layouts.partials.footer')

    @stack('scripts')
</body>
</html>
