@extends('layouts.app', ['title' => 'CMS Pages'])

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">CMS Pages</h1>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">Manage portal content pages.</p>
        </div>
        <x-ui.button :href="route('operator.cms.create')">+ New Page</x-ui.button>
    </div>

    @if (session('success'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('success') }}</div>
    @endif

    <div class="rounded-xl border overflow-hidden" style="background-color: var(--surface-card); border-color: var(--border-base);">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b" style="border-color: var(--border-base);">
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Title</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Slug</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Updated</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y" style="divide-color: var(--border-base);">
                @forelse ($pages as $page)
                <tr>
                    <td class="px-4 py-3 font-medium">
                        <x-ui.link variant="row" :href="route('operator.cms.edit', $page)">
                            {{ $page->title }}
                        </x-ui.link>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs" style="color: var(--text-muted);">{{ $page->slug }}</td>
                    <td class="px-4 py-3 text-center">
                        <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                              style="background-color: {{ $page->isPublished() ? 'var(--surface-success)' : 'var(--surface-input)' }};
                                     color: {{ $page->isPublished() ? 'var(--text-success)' : 'var(--text-muted)' }};">
                            {{ $page->isPublished() ? 'Published' : 'Draft' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">{{ $page->updated_at->diffForHumans() }}</td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-3">
                            @if ($page->isPublished())
                            <x-ui.link variant="quiet" :href="route('cms.show', $page->slug)" target="_blank" class="text-xs">View ↗</x-ui.link>
                            @endif
                            <x-ui.link variant="quiet" :href="route('operator.cms.edit', $page)" class="text-xs">Edit</x-ui.link>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No pages yet.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $pages->links() }}</div>
</div>
@endsection
