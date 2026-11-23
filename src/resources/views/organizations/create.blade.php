@extends('layouts.app', ['title' => 'New Organization', 'breadcrumbTrail' => [['New Organization', null]]])

@section('content')
<div class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8">
        <h1 class="text-2xl font-semibold text-text">New Organization</h1>
    </div>

    <div class="rounded-lg border p-6 bg-surface border-rule">
        <form method="POST" action="{{ route('organizations.store') }}" class="space-y-4">
            @csrf
            @include('organizations._form')
            <div class="flex gap-3 pt-2">
                <x-ui.button type="submit">Create Organization</x-ui.button>
                <x-ui.button :href="route('organizations.index')" variant="secondary">Cancel</x-ui.button>
            </div>
        </form>
    </div>
</div>
@endsection
