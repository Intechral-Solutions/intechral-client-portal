@extends('layouts.app', ['title' => 'New Page'])

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8">
        <a href="{{ route('operator.cms.index') }}" class="text-sm hover:underline" style="color: var(--text-secondary);">&larr; Pages</a>
        <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">New Page</h1>
    </div>

    <div class="rounded-xl border p-6" style="background-color: var(--surface-card); border-color: var(--border-base);">
        <form method="POST" action="{{ route('operator.cms.store') }}" class="space-y-4">
            @csrf
            @include('operator.cms._form')
            <div class="flex gap-3 pt-2">
                <button type="submit" class="rounded-lg px-5 py-2 text-sm font-medium"
                        style="background-color: var(--accent); color: #fff;">Create Page</button>
                <a href="{{ route('operator.cms.index') }}" class="rounded-lg border px-5 py-2 text-sm font-medium"
                   style="border-color: var(--border-base); color: var(--text-secondary);">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
