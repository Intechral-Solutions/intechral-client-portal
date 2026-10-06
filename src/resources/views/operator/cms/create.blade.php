@extends('layouts.app', ['title' => 'New Page'])

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8">
        <x-ui.link variant="quiet" :href="route('operator.cms.index')" class="text-sm">&larr; Pages</x-ui.link>
        <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">New Page</h1>
    </div>

    <div class="rounded-xl border p-6" style="background-color: var(--surface-card); border-color: var(--border-base);">
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
