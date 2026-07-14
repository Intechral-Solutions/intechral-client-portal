@extends('layouts.app', ['title' => 'Resources'])

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8">
        <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">Resources</h1>
        <p class="mt-1 text-sm" style="color: var(--text-secondary);">Guides, policies, and information pages.</p>
    </div>

    <div class="space-y-3">
        @forelse ($pages as $page)
        <a href="{{ route('cms.show', $page->slug) }}"
           class="block rounded-xl border px-5 py-4 transition-colors hover:border-transparent"
           style="background-color: var(--surface-card); border-color: var(--border-base);">
            <div class="flex items-center justify-between">
                <h2 class="font-medium" style="color: var(--text-primary);">{{ $page->title }}</h2>
                <span class="text-xs" style="color: var(--text-muted);">{{ $page->published_at->format('M j, Y') }}</span>
            </div>
        </a>
        @empty
        <p class="text-center py-12 text-sm" style="color: var(--text-muted);">No pages published yet.</p>
        @endforelse
    </div>
</div>
@endsection
