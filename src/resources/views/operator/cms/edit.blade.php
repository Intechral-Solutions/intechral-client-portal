@extends('layouts.app', ['title' => 'Edit: ' . $page->title])

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-start justify-between">
        <div>
            <x-ui.link variant="quiet" :href="route('operator.cms.index')" class="text-sm">&larr; Pages</x-ui.link>
            <h1 class="mt-2 text-2xl font-semibold" style="color: var(--text-primary);">{{ $page->title }}</h1>
            <div class="mt-1 flex items-center gap-3">
                <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                      style="background-color: {{ $page->isPublished() ? 'var(--surface-success)' : 'var(--surface-input)' }};
                             color: {{ $page->isPublished() ? 'var(--text-success)' : 'var(--text-muted)' }};">
                    {{ $page->isPublished() ? 'Published' : 'Draft' }}
                </span>
                @if ($page->isPublished())
                <x-ui.link :href="route('cms.show', $page->slug)" target="_blank" class="text-xs">View live ↗</x-ui.link>
                @endif
            </div>
        </div>
        <div class="flex gap-2">
            @if ($page->isPublished())
            <form method="POST" action="{{ route('operator.cms.unpublish', $page) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">Unpublish</x-ui.button>
            </form>
            @else
            <form method="POST" action="{{ route('operator.cms.publish', $page) }}">
                @csrf
                <x-ui.button type="submit">Publish</x-ui.button>
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
                <x-ui.button type="submit">Save</x-ui.button>
                <x-ui.button :href="route('operator.cms.index')" variant="secondary">Cancel</x-ui.button>
            </div>
        </form>
    </div>

    <div class="mt-8 rounded-xl border border-danger p-5" style="background-color: var(--surface-card);">
        <h2 class="mb-2 text-sm font-semibold" style="color: var(--text-danger);">Delete Page</h2>
        <p class="mb-4 text-xs" style="color: var(--text-muted);">Permanently delete this page. This cannot be undone.</p>
        <form method="POST" action="{{ route('operator.cms.destroy', $page) }}"
              onsubmit="return confirm('Delete \'{{ addslashes($page->title) }}\'?')">
            @csrf @method('DELETE')
            <x-ui.button type="submit" variant="secondary" tone="danger" size="sm">Delete Page</x-ui.button>
        </form>
    </div>
</div>
@endsection
