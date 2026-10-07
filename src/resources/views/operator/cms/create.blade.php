@extends('layouts.app', ['title' => 'New Page'])

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8">
        <x-ui.link variant="quiet" :href="route('operator.cms.index')" class="text-sm">&larr; Pages</x-ui.link>
        <h1 class="mt-2 text-2xl font-semibold text-text">New Page</h1>
    </div>

    <div class="rounded-lg border p-6 bg-surface border-rule">
        <form method="POST" action="{{ route('operator.cms.store') }}" class="space-y-4">
            @csrf
            @include('operator.cms._form')
            <div class="flex gap-3 pt-2">
                <x-ui.button type="submit">Create Page</x-ui.button>
                <x-ui.button :href="route('operator.cms.index')" variant="secondary">Cancel</x-ui.button>
            </div>
        </form>
    </div>
</div>
@endsection
