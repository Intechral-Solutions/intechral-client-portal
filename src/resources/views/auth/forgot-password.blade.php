<x-layouts.auth title="Forgot Password" subtitle="Reset your password">

    @if (session('status'))
        <div class="mb-4 rounded-md p-3 text-sm" style="background-color: var(--bg-surface); color: var(--success);">
            {{ session('status') }}
        </div>
    @endif

    <p class="mb-6 text-sm" style="color: var(--text-secondary);">
        Enter your email address and we'll send you a password reset link.
    </p>

    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf

        <div class="mb-6">
            <label for="email" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                Email address
            </label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   required autofocus autocomplete="username"
                   class="w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                   style="background-color: var(--bg-base); border-color: {{ $errors->has('email') ? 'var(--danger)' : 'var(--border-base)' }}; color: var(--text-primary);">
            @error('email')
                <p class="mt-1.5 text-xs" style="color: var(--danger);">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit"
                class="w-full rounded-lg px-4 py-2.5 text-sm font-semibold transition"
                style="background-color: var(--accent); color: var(--accent-text);">
            Send reset link
        </button>
    </form>

    <div class="mt-4 text-center">
        <a href="{{ route('login') }}" class="text-xs hover:underline" style="color: var(--text-muted);">
            Back to sign in
        </a>
    </div>

</x-layouts.auth>
