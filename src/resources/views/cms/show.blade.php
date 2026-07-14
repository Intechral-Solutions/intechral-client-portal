@extends('layouts.app', ['title' => $page->title])

@section('content')
<div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8">
        <a href="{{ route('cms.index') }}" class="text-sm hover:underline" style="color: var(--text-secondary);">&larr; Resources</a>
        <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">{{ $page->title }}</h1>
        <p class="mt-1 text-xs" style="color: var(--text-muted);">
            Last updated {{ $page->updated_at->format('M j, Y') }}
        </p>
    </div>

    <div class="rounded-xl border p-6" style="background-color: var(--surface-card); border-color: var(--border-base);">
        <div class="prose prose-sm max-w-none" style="color: var(--text-primary);">
            {!! $page->body !!}
        </div>
    </div>
</div>
@endsection
