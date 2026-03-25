@extends('layouts.app', ['title' => 'Dashboard'])

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    <h2 class="text-xl font-semibold mb-2" style="color: var(--text-primary);">Dashboard</h2>
    <p style="color: var(--text-secondary);">
        Welcome, {{ auth()->user()->name }}. The full dashboard is coming in EPIC-002.
    </p>
</div>
@endsection
