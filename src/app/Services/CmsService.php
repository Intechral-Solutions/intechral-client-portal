<?php

namespace App\Services;

use App\Models\CmsPage;
use App\Models\User;
use Illuminate\Support\Str;

class CmsService
{
    public function create(User $creator, array $data): CmsPage
    {
        return CmsPage::create([
            'slug' => $data['slug'] ?? $this->uniqueSlug($data['title']),
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'status' => 'draft',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    public function update(CmsPage $page, User $editor, array $data): CmsPage
    {
        $page->update([
            'title' => $data['title'] ?? $page->title,
            'slug' => $data['slug'] ?? $page->slug,
            'body' => $data['body'] ?? $page->body,
            'updated_by' => $editor->id,
        ]);

        return $page->fresh();
    }

    public function publish(CmsPage $page, User $publisher): CmsPage
    {
        $page->update([
            'status' => 'published',
            'published_at' => $page->published_at ?? now(),
            'updated_by' => $publisher->id,
        ]);

        return $page->fresh();
    }

    public function unpublish(CmsPage $page, User $editor): CmsPage
    {
        $page->update([
            'status' => 'draft',
            'updated_by' => $editor->id,
        ]);

        return $page->fresh();
    }

    public function delete(CmsPage $page): void
    {
        $page->delete();
    }

    // ── Private ───────────────────────────────────────────

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $i = 2;

        while (CmsPage::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug ?: 'page-'.now()->timestamp;
    }
}
