@extends('layouts.app', ['title' => 'Profile'])

@section('content')
<div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8 space-y-8">

    <h2 class="text-xl font-semibold" style="color: var(--text-primary);">Profile</h2>

    {{-- Flash status --}}
    @if (session('status'))
        <div class="rounded-md p-3 text-sm" style="background-color: var(--bg-surface); color: var(--success);">
            {{ session('status') }}
        </div>
    @endif

    {{-- ── Profile Information ──────────────────────────────────── --}}
    <section class="rounded-xl border p-6 space-y-4" style="background-color: var(--bg-elevated); border-color: var(--border-base);">
        <h3 class="text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Profile Information</h3>

        <form method="POST" action="{{ route('user-profile-information.update') }}" novalidate>
            @csrf
            @method('PUT')

            <div class="mb-4">
                <label for="name" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">Full name</label>
                <input id="name" type="text" name="name" value="{{ old('name', $user->name) }}" required
                       class="w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                       style="background-color: var(--bg-base); border-color: {{ $errors->updateProfileInformation->has('name') ? 'var(--danger)' : 'var(--border-base)' }}; color: var(--text-primary);">
                @if ($errors->updateProfileInformation->has('name'))
                    <p class="mt-1.5 text-xs" style="color: var(--danger);">{{ $errors->updateProfileInformation->first('name') }}</p>
                @endif
            </div>

            <div class="mb-4">
                <label for="email" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">Email address</label>
                <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required
                       class="w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                       style="background-color: var(--bg-base); border-color: {{ $errors->updateProfileInformation->has('email') ? 'var(--danger)' : 'var(--border-base)' }}; color: var(--text-primary);">
                @if ($errors->updateProfileInformation->has('email'))
                    <p class="mt-1.5 text-xs" style="color: var(--danger);">{{ $errors->updateProfileInformation->first('email') }}</p>
                @endif
            </div>

            <button type="submit"
                    class="rounded-lg px-4 py-2 text-sm font-semibold transition"
                    style="background-color: var(--accent); color: var(--accent-text);">
                Save changes
            </button>
        </form>
    </section>

    {{-- ── Change Password ──────────────────────────────────────── --}}
    @if ($user->password)
    <section class="rounded-xl border p-6 space-y-4" style="background-color: var(--bg-elevated); border-color: var(--border-base);">
        <h3 class="text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Change Password</h3>

        <form method="POST" action="{{ route('user-password.update') }}" novalidate>
            @csrf
            @method('PUT')

            <div class="mb-4">
                <label for="current_password" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">Current password</label>
                <input id="current_password" type="password" name="current_password" autocomplete="current-password"
                       class="w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                       style="background-color: var(--bg-base); border-color: {{ $errors->updatePassword->has('current_password') ? 'var(--danger)' : 'var(--border-base)' }}; color: var(--text-primary);">
                @if ($errors->updatePassword->has('current_password'))
                    <p class="mt-1.5 text-xs" style="color: var(--danger);">{{ $errors->updatePassword->first('current_password') }}</p>
                @endif
            </div>

            <div class="mb-4">
                <label for="password" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">New password</label>
                <input id="password" type="password" name="password" autocomplete="new-password"
                       class="w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                       style="background-color: var(--bg-base); border-color: {{ $errors->updatePassword->has('password') ? 'var(--danger)' : 'var(--border-base)' }}; color: var(--text-primary);">
                <p class="mt-1 text-xs" style="color: var(--text-muted);">Min 12 chars, upper &amp; lowercase, numbers, symbols.</p>
                @if ($errors->updatePassword->has('password'))
                    <p class="mt-1.5 text-xs" style="color: var(--danger);">{{ $errors->updatePassword->first('password') }}</p>
                @endif
            </div>

            <div class="mb-4">
                <label for="password_confirmation" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">Confirm new password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password"
                       class="w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                       style="background-color: var(--bg-base); border-color: var(--border-base); color: var(--text-primary);">
            </div>

            <button type="submit"
                    class="rounded-lg px-4 py-2 text-sm font-semibold transition"
                    style="background-color: var(--accent); color: var(--accent-text);">
                Update password
            </button>
        </form>
    </section>
    @endif

    {{-- ── Two-Factor Authentication ─────────────────────────────── --}}
    <section class="rounded-xl border p-6 space-y-4" style="background-color: var(--bg-elevated); border-color: var(--border-base);">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Two-Factor Authentication</h3>
            @if ($user->two_factor_confirmed_at)
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full" style="background-color: var(--success-bg, #dcfce7); color: var(--success);">Enabled</span>
            @else
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full" style="background-color: var(--bg-surface); color: var(--text-muted);">Disabled</span>
            @endif
        </div>

        @if (! $user->two_factor_confirmed_at)
            <p class="text-sm" style="color: var(--text-secondary);">
                Add extra security to your account using a TOTP authenticator app (Google Authenticator, Authy, etc.).
            </p>
            <form method="POST" action="{{ route('two-factor.enable') }}">
                @csrf
                <button type="submit"
                        class="rounded-lg px-4 py-2 text-sm font-semibold transition"
                        style="background-color: var(--accent); color: var(--accent-text);">
                    Enable 2FA
                </button>
            </form>
        @else
            <p class="text-sm" style="color: var(--text-secondary);">
                Two-factor authentication is active. Use the codes below to recover access if you lose your device.
            </p>

            @if (! empty($recoveryCodes))
                <div class="rounded-lg p-4 font-mono text-sm space-y-1" style="background-color: var(--bg-surface); color: var(--text-primary);">
                    @foreach ($recoveryCodes as $code)
                        <div>{{ $code }}</div>
                    @endforeach
                </div>
                <form method="POST" action="{{ route('two-factor.recovery-codes') }}" class="inline">
                    @csrf
                    <button type="submit" class="text-sm hover:underline" style="color: var(--accent);">
                        Regenerate recovery codes
                    </button>
                </form>
            @endif

            <form method="POST" action="{{ route('two-factor.disable') }}">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="rounded-lg px-4 py-2 text-sm font-semibold transition"
                        style="background-color: var(--danger); color: #fff;">
                    Disable 2FA
                </button>
            </form>
        @endif
    </section>

    {{-- ── Connected Accounts (SSO) ─────────────────────────────── --}}
    <section class="rounded-xl border p-6 space-y-4" style="background-color: var(--bg-elevated); border-color: var(--border-base);">
        <h3 class="text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Connected Accounts</h3>

        @foreach (['google', 'microsoft'] as $provider)
            @php $linked = $user->socialAccounts->firstWhere('provider', $provider); @endphp
            <div class="flex items-center justify-between py-2">
                <div class="flex items-center gap-3">
                    @if ($provider === 'google')
                        <svg class="h-5 w-5" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                    @else
                        <svg class="h-5 w-5" viewBox="0 0 24 24"><path fill="#F25022" d="M1 1h10v10H1z"/><path fill="#7FBA00" d="M13 1h10v10H13z"/><path fill="#00A4EF" d="M1 13h10v10H1z"/><path fill="#FFB900" d="M13 13h10v10H13z"/></svg>
                    @endif
                    <span class="text-sm font-medium" style="color: var(--text-primary);">{{ ucfirst($provider) }}</span>
                </div>
                @if ($linked)
                    <form method="POST" action="{{ route('profile.social.unlink', $provider) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-xs hover:underline" style="color: var(--danger);">Unlink</button>
                    </form>
                @else
                    <a href="{{ route('sso.redirect', $provider) }}"
                       class="text-xs hover:underline" style="color: var(--accent);">Connect</a>
                @endif
            </div>
        @endforeach
    </section>

    {{-- ── Session Management ───────────────────────────────────── --}}
    <section class="rounded-xl border p-6 space-y-4" style="background-color: var(--bg-elevated); border-color: var(--border-base);">
        <h3 class="text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Sessions</h3>
        <p class="text-sm" style="color: var(--text-secondary);">Sign out all other browser sessions on all your devices.</p>

        <form method="POST" action="{{ route('profile.sessions.destroy') }}" novalidate>
            @csrf
            @method('DELETE')
            <div class="mb-4">
                <label for="session_password" class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">Confirm your password</label>
                <input id="session_password" type="password" name="password" autocomplete="current-password"
                       class="w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
                       style="background-color: var(--bg-base); border-color: {{ $errors->has('password') ? 'var(--danger)' : 'var(--border-base)' }}; color: var(--text-primary);">
                @error('password')
                    <p class="mt-1.5 text-xs" style="color: var(--danger);">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit"
                    class="rounded-lg px-4 py-2 text-sm font-semibold transition"
                    style="background-color: var(--danger); color: #fff;">
                Sign out other sessions
            </button>
        </form>
    </section>

</div>
@endsection
