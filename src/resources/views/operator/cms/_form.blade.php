{{-- Shared form fields for CMS page create / edit --}}

<div class="space-y-2">
    <x-ui.label for="title" required>Title</x-ui.label>
    <x-ui.input type="text" name="title" :value="old('title', $page->title ?? '')" required class="w-full" />
    <x-ui.field-error for="title" />
</div>

<div class="space-y-2">
    <x-ui.label for="slug">
        Slug
        <span class="font-normal" style="color: var(--text-muted);">(leave blank to auto-generate)</span>
    </x-ui.label>
    <x-ui.input type="text" name="slug" :value="old('slug', $page->slug ?? '')"
                placeholder="my-page-slug" class="w-full font-mono" />
    <x-ui.field-error for="slug" />
</div>

<div class="space-y-2">
    <x-ui.label for="body">Content (HTML)</x-ui.label>
    <x-ui.textarea name="body" rows="20" class="w-full font-mono">{{ old('body', $page->body ?? '') }}</x-ui.textarea>
    <x-ui.field-error for="body" />
</div>
