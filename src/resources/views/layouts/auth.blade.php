<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      data-theme="{{ cookie('theme', 'light') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Sign In' }} &mdash; {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex items-center justify-center antialiased" style="background-color: var(--bg-surface);">

    <div class="w-full max-w-md px-4">

        {{-- Logo --}}
        <div class="mb-8 text-center">
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">
                <a href="/">{{ config('app.name') }}</a>
            </h1>
            @isset($subtitle)
                <p class="mt-1 text-sm" style="color: var(--text-secondary);">{{ $subtitle }}</p>
            @endisset
        </div>

        {{-- Card --}}
        <div class="rounded-xl border p-8" style="background-color: var(--bg-elevated); border-color: var(--border-base); box-shadow: var(--shadow-md);">
            {{ $slot }}
        </div>

        {{-- Theme toggle --}}
        <div class="mt-6 text-center">
            <button id="theme-toggle" type="button" class="text-xs hover:underline" style="color: var(--text-muted);">
                Toggle dark mode
            </button>
        </div>

    </div>

    <script>
        const toggle = document.getElementById('theme-toggle');
        toggle.addEventListener('click', () => {
            const html = document.documentElement;
            const next = html.dataset.theme === 'dark' ? 'light' : 'dark';
            html.dataset.theme = next;
            document.cookie = `theme=${next};path=/;max-age=31536000`;
        });
    </script>
</body>
</html>
