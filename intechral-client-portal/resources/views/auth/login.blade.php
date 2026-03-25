<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      data-theme="{{ cookie('theme', 'light') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign In &mdash; {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex items-center justify-center antialiased" style="background-color: var(--bg-surface);">

    <div class="w-full max-w-md px-4">

        {{-- Logo / wordmark --}}
        <div class="mb-8 text-center">
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">
                Intechral Portal
            </h1>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">Sign in to your account</p>
        </div>

        {{-- Card --}}
        <div class="rounded-xl border p-8" style="background-color: var(--bg-elevated); border-color: var(--border-base); box-shadow: var(--shadow-md);">

            {{-- Session status --}}
            @if (session('status'))
                <div class="mb-4 rounded-md p-3 text-sm" style="background-color: var(--bg-surface); color: var(--success);">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" novalidate>
                @csrf

                {{-- Email --}}
                <div class="mb-4">
                    <label for="email" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                        Email address
                    </label>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        required
                        autofocus
                        autocomplete="username"
                        class="w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                        style="background-color: var(--bg-base); border-color: var(--border-base); color: var(--text-primary);"
                    >
                    @error('email')
                        <p class="mt-1.5 text-xs" style="color: var(--danger);">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Password --}}
                <div class="mb-6">
                    <div class="flex items-center justify-between mb-1.5">
                        <label for="password" class="block text-sm font-medium" style="color: var(--text-primary);">
                            Password
                        </label>
                        @if (Route::has('password.request'))
                            <a href="{{ route('password.request') }}" class="text-xs hover:underline" style="color: var(--accent);">
                                Forgot password?
                            </a>
                        @endif
                    </div>
                    <input
                        id="password"
                        type="password"
                        name="password"
                        required
                        autocomplete="current-password"
                        class="w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                        style="background-color: var(--bg-base); border-color: var(--border-base); color: var(--text-primary);"
                    >
                    @error('password')
                        <p class="mt-1.5 text-xs" style="color: var(--danger);">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Remember me --}}
                <div class="flex items-center gap-2 mb-6">
                    <input id="remember_me" type="checkbox" name="remember"
                           class="rounded border" style="accent-color: var(--accent);">
                    <label for="remember_me" class="text-sm" style="color: var(--text-secondary);">
                        Keep me signed in
                    </label>
                </div>

                {{-- Submit --}}
                <button type="submit"
                        class="w-full rounded-lg px-4 py-2.5 text-sm font-semibold transition"
                        style="background-color: var(--accent); color: var(--accent-text);">
                    Sign in
                </button>
            </form>
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
