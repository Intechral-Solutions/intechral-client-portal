@extends('layouts.app', ['title' => '403 Forbidden'])

@section('content')
<div class="flex min-h-[60vh] flex-col items-center justify-center px-4 py-20 text-center">

    <p class="mb-2 text-6xl font-bold tabular-nums text-text-muted">403</p>
    <h1 class="mb-3 text-2xl font-semibold text-text">Access Denied</h1>
    <p class="mb-8 max-w-sm text-sm text-text-secondary">
        You don't have permission to view this page. Contact your administrator if you believe this is a mistake.
    </p>

    <div class="flex gap-3">
        <x-ui.button :href="url()->previous('/')" variant="secondary">Go back</x-ui.button>
        @auth
        <x-ui.button :href="route('dashboard')">Dashboard</x-ui.button>
        @else
        <x-ui.button :href="route('login')">Sign in</x-ui.button>
        @endauth
    </div>

</div>
@endsection
