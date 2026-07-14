<x-layouts.auth title="Confirm Password" subtitle="Please re-enter your password to continue">

    <p class="mb-6 text-sm" style="color: var(--text-secondary);">
        For your security, please confirm your password before continuing.
    </p>

    <form method="POST" action="{{ route('password.confirm') }}" novalidate>
        @csrf

        <div class="mb-6">
            <label for="password" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                Password
            </label>
            <input id="password" type="password" name="password"
                   required autocomplete="current-password"
                   class="w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                   style="background-color: var(--bg-base); border-color: {{ $errors->has('password') ? 'var(--danger)' : 'var(--border-base)' }}; color: var(--text-primary);">
            @error('password')
                <p class="mt-1.5 text-xs" style="color: var(--danger);">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit"
                class="w-full rounded-lg px-4 py-2.5 text-sm font-semibold transition"
                style="background-color: var(--accent); color: var(--accent-text);">
            Confirm
        </button>
    </form>

</x-layouts.auth>
