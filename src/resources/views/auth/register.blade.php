<x-layouts.auth title="Create Account" subtitle="Complete your registration">

    <form method="POST" action="{{ route('invitation.register', $token) }}" novalidate>
        @csrf

        {{-- Email (read-only, from invitation) --}}
        <div class="mb-4">
            <label class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                Email address
            </label>
            <input type="email" value="{{ $invitation->email }}" disabled
                   class="w-full rounded-lg border px-3 py-2 text-sm"
                   style="background-color: var(--bg-base); border-color: var(--border-base); color: var(--text-muted); cursor: not-allowed;">
            <p class="mt-1 text-xs" style="color: var(--text-muted);">Verified by your invitation link.</p>
        </div>

        {{-- Name --}}
        <div class="mb-4">
            <label for="name" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                Full name
            </label>
            <input id="name" type="text" name="name" value="{{ old('name') }}"
                   required autofocus autocomplete="name"
                   class="w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                   style="background-color: var(--bg-base); border-color: {{ $errors->has('name') ? 'var(--danger)' : 'var(--border-base)' }}; color: var(--text-primary);">
            @error('name')
                <p class="mt-1.5 text-xs" style="color: var(--danger);">{{ $message }}</p>
            @enderror
        </div>

        {{-- Password --}}
        <div class="mb-4">
            <label for="password" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                Password
            </label>
            <input id="password" type="password" name="password"
                   required autocomplete="new-password"
                   class="w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                   style="background-color: var(--bg-base); border-color: {{ $errors->has('password') ? 'var(--danger)' : 'var(--border-base)' }}; color: var(--text-primary);">
            <p class="mt-1 text-xs" style="color: var(--text-muted);">Min 12 characters, upper &amp; lowercase, numbers, and symbols.</p>
            @error('password')
                <p class="mt-1.5 text-xs" style="color: var(--danger);">{{ $message }}</p>
            @enderror
        </div>

        {{-- Confirm Password --}}
        <div class="mb-6">
            <label for="password_confirmation" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                Confirm password
            </label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                   required autocomplete="new-password"
                   class="w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                   style="background-color: var(--bg-base); border-color: var(--border-base); color: var(--text-primary);">
        </div>

        <button type="submit"
                class="w-full rounded-lg px-4 py-2.5 text-sm font-semibold transition"
                style="background-color: var(--accent); color: var(--accent-text);">
            Create account
        </button>
    </form>

    {{-- SSO option --}}
    <div class="mt-6">
        <div class="relative">
            <div class="absolute inset-0 flex items-center">
                <div class="w-full border-t" style="border-color: var(--border-subtle);"></div>
            </div>
            <div class="relative flex justify-center text-xs">
                <span class="px-2" style="background-color: var(--bg-elevated); color: var(--text-muted);">or register with</span>
            </div>
        </div>
        <div class="mt-4 grid grid-cols-2 gap-3">
            <a href="{{ route('sso.redirect', 'google') }}?invitation={{ $token }}"
               class="flex items-center justify-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition hover:opacity-80"
               style="border-color: var(--border-base); color: var(--text-secondary);">
                <svg class="h-4 w-4" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                Google
            </a>
            <a href="{{ route('sso.redirect', 'microsoft') }}?invitation={{ $token }}"
               class="flex items-center justify-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition hover:opacity-80"
               style="border-color: var(--border-base); color: var(--text-secondary);">
                <svg class="h-4 w-4" viewBox="0 0 24 24" aria-hidden="true"><path fill="#F25022" d="M1 1h10v10H1z"/><path fill="#7FBA00" d="M13 1h10v10H13z"/><path fill="#00A4EF" d="M1 13h10v10H1z"/><path fill="#FFB900" d="M13 13h10v10H13z"/></svg>
                Microsoft
            </a>
        </div>
    </div>

</x-layouts.auth>
