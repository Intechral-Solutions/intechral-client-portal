{{-- Shared form fields for CMS page create / edit --}}

<div>
    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Title *</label>
    <input type="text" name="title" value="{{ old('title', $page->title ?? '') }}" required
           class="block w-full rounded-lg border px-3 py-2 text-sm outline-none"
           style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
    @error('title')<p class="text-xs mt-0.5" style="color: var(--text-danger);">{{ $message }}</p>@enderror
</div>

<div>
    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">
        Slug
        <span class="font-normal" style="color: var(--text-muted);">(leave blank to auto-generate)</span>
    </label>
    <input type="text" name="slug" value="{{ old('slug', $page->slug ?? '') }}"
           placeholder="my-page-slug"
           class="block w-full rounded-lg border px-3 py-2 text-sm font-mono outline-none"
           style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
    @error('slug')<p class="text-xs mt-0.5" style="color: var(--text-danger);">{{ $message }}</p>@enderror
</div>

<div>
    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">Content (HTML)</label>
    <textarea name="body" rows="20"
              class="block w-full rounded-lg border px-3 py-2 text-sm font-mono outline-none"
              style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">{{ old('body', $page->body ?? '') }}</textarea>
    @error('body')<p class="text-xs mt-0.5" style="color: var(--text-danger);">{{ $message }}</p>@enderror
</div>
