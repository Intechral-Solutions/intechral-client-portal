@extends('layouts.app', ['title' => '403 Forbidden'])

@section('content')
<div class="flex min-h-[60vh] flex-col items-center justify-center px-4 py-20 text-center">

    <p class="mb-2 text-6xl font-bold tabular-nums" style="color: var(--accent);">403</p>
    <h1 class="mb-3 text-2xl font-semibold" style="color: var(--text-primary);">Access Denied</h1>
    <p class="mb-8 max-w-sm text-sm" style="color: var(--text-secondary);">
        You don't have permission to view this page. Contact your administrator if you believe this is a mistake.
    </p>

    <div class="flex gap-3">
        <a href="{{ url()->previous('/') }}"
           class="rounded-lg border px-5 py-2 text-sm font-medium transition-colors hover:legacy-bg-surface"
           style="border-color: var(--border-base); color: var(--text-secondary);">
            Go back
        </a>
        @auth
        <a href="{{ route('dashboard') }}"
           class="rounded-lg px-5 py-2 text-sm font-medium transition-colors"
           style="background-color: var(--accent); color: #fff;">
            Dashboard
        </a>
        @else
        <a href="{{ route('login') }}"
           class="rounded-lg px-5 py-2 text-sm font-medium transition-colors"
           style="background-color: var(--accent); color: #fff;">
            Sign in
        </a>
        @endauth
    </div>

</div>
@endsection
