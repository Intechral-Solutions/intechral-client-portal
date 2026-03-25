<x-layouts.auth title="Two-Factor Authentication" subtitle="Verify your identity">

    <div x-data="{ recovery: false }">

        {{-- TOTP form --}}
        <div x-show="!recovery">
            <p class="mb-6 text-sm" style="color: var(--text-secondary);">
                Enter the 6-digit code from your authenticator app.
            </p>

            <form method="POST" action="{{ route('two-factor.login') }}" novalidate>
                @csrf

                <div class="mb-6">
                    <label for="code" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                        Authentication code
                    </label>
                    <input id="code" type="text" name="code" inputmode="numeric"
                           autofocus autocomplete="one-time-code" maxlength="6"
                           class="w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2 tracking-widest text-center"
                           style="background-color: var(--bg-base); border-color: {{ $errors->has('code') ? 'var(--danger)' : 'var(--border-base)' }}; color: var(--text-primary); font-size: 1.25rem;">
                    @error('code')
                        <p class="mt-1.5 text-xs" style="color: var(--danger);">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit"
                        class="w-full rounded-lg px-4 py-2.5 text-sm font-semibold transition"
                        style="background-color: var(--accent); color: var(--accent-text);">
                    Verify
                </button>
            </form>

            <div class="mt-4 text-center">
                <button type="button" @click="recovery = true"
                        class="text-xs hover:underline" style="color: var(--text-muted);">
                    Use a recovery code instead
                </button>
            </div>
        </div>

        {{-- Recovery code form --}}
        <div x-show="recovery" x-cloak>
            <p class="mb-6 text-sm" style="color: var(--text-secondary);">
                Enter one of your recovery codes.
            </p>

            <form method="POST" action="{{ route('two-factor.login') }}" novalidate>
                @csrf

                <div class="mb-6">
                    <label for="recovery_code" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                        Recovery code
                    </label>
                    <input id="recovery_code" type="text" name="recovery_code"
                           autocomplete="one-time-code"
                           class="w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2 font-mono"
                           style="background-color: var(--bg-base); border-color: {{ $errors->has('recovery_code') ? 'var(--danger)' : 'var(--border-base)' }}; color: var(--text-primary);">
                    @error('recovery_code')
                        <p class="mt-1.5 text-xs" style="color: var(--danger);">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit"
                        class="w-full rounded-lg px-4 py-2.5 text-sm font-semibold transition"
                        style="background-color: var(--accent); color: var(--accent-text);">
                    Verify
                </button>
            </form>

            <div class="mt-4 text-center">
                <button type="button" @click="recovery = false"
                        class="text-xs hover:underline" style="color: var(--text-muted);">
                    Use authenticator app instead
                </button>
            </div>
        </div>

    </div>

</x-layouts.auth>
