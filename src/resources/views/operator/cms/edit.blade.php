@extends('layouts.app', ['title' => 'Edit: ' . $page->title])

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-start justify-between">
        <div>
            <a href="{{ route('operator.cms.index') }}" class="text-sm hover:underline" style="color: var(--text-secondary);">&larr; Pages</a>
            <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">{{ $page->title }}</h1>
            <div class="mt-1 flex items-center gap-3">
                <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                      style="background-color: {{ $page->isPublished() ? 'var(--surface-success)' : 'var(--surface-input)' }};
                             color: {{ $page->isPublished() ? 'var(--text-success)' : 'var(--text-muted)' }};">
                    {{ $page->isPublished() ? 'Published' : 'Draft' }}
                </span>
                @if ($page->isPublished())
                <a href="{{ route('cms.show', $page->slug) }}" target="_blank"
                   class="text-xs hover:underline" style="color: var(--accent);">View live ↗</a>
                @endif
            </div>
        </div>
        <div class="flex gap-2">
            @if ($page->isPublished())
            <form method="POST" action="{{ route('operator.cms.unpublish', $page) }}">
                @csrf
                <button type="submit" class="rounded-lg border px-4 py-2 text-sm font-medium"
                        style="border-color: var(--border-base); color: var(--text-secondary);">Unpublish</button>
            </form>
            @else
            <form method="POST" action="{{ route('operator.cms.publish', $page) }}">
                @csrf
                <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium"
                        style="background-color: var(--accent); color: #fff;">Publish</button>
            </form>
            @endif
        </div>
    </div>

    @if (session('success'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    <div class="rounded-xl border p-6" style="background-color: var(--surface-card); border-color: var(--border-base);">
        <form method="POST" action="{{ route('operator.cms.update', $page) }}" class="space-y-4">
            @csrf @method('PUT')
            @include('operator.cms._form')
            <div class="flex gap-3 pt-2">
                <button type="submit" class="rounded-lg px-5 py-2 text-sm font-medium"
                        style="background-color: var(--accent); color: #fff;">Save</button>
                <a href="{{ route('operator.cms.index') }}" class="rounded-lg border px-5 py-2 text-sm font-medium"
                   style="border-color: var(--border-base); color: var(--text-secondary);">Cancel</a>
            </div>
        </form>
    </div>

    <div class="mt-8 rounded-xl border p-5" style="border-color: var(--border-danger); background-color: var(--surface-card);">
        <h2 class="mb-2 text-sm font-semibold" style="color: var(--text-danger);">Delete Page</h2>
        <p class="mb-4 text-xs" style="color: var(--text-muted);">Permanently delete this page. This cannot be undone.</p>
        <form method="POST" action="{{ route('operator.cms.destroy', $page) }}"
              onsubmit="return confirm('Delete \'{{ addslashes($page->title) }}\'?')">
            @csrf @method('DELETE')
            <button type="submit" class="rounded-lg px-4 py-2 text-xs font-medium"
                    style="background-color: var(--surface-danger); color: var(--text-danger);">Delete Page</button>
        </form>
    </div>
</div>
@endsection
