<x-layouts.auth title="Reset Password" subtitle="Choose a new password">

    <form method="POST" action="{{ route('password.update') }}" novalidate>
        @csrf

        <input type="hidden" name="token" value="{{ request()->route('token') }}">

        <div class="mb-4">
            <label for="email" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                Email address
            </label>
            <input id="email" type="email" name="email" value="{{ old('email', request()->email) }}"
                   required autocomplete="username"
                   class="w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                   style="background-color: var(--bg-base); border-color: {{ $errors->has('email') ? 'var(--danger)' : 'var(--border-base)' }}; color: var(--text-primary);">
            @error('email')
                <p class="mt-1.5 text-xs" style="color: var(--danger);">{{ $message }}</p>
            @enderror
        </div>

        <div class="mb-4">
            <label for="password" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                New password
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

        <div class="mb-6">
            <label for="password_confirmation" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                Confirm new password
            </label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                   required autocomplete="new-password"
                   class="w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                   style="background-color: var(--bg-base); border-color: var(--border-base); color: var(--text-primary);">
        </div>

        <button type="submit"
                class="w-full rounded-lg px-4 py-2.5 text-sm font-semibold transition"
                style="background-color: var(--accent); color: var(--accent-text);">
            Reset password
        </button>
    </form>

</x-layouts.auth>
