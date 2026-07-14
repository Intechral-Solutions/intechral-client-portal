<?php

namespace App\Http\Controllers;

use App\Models\CmsPage;
use Illuminate\View\View;

class CmsController extends Controller
{
    public function index(): View
    {
        $pages = CmsPage::published()
            ->orderByDesc('published_at')
            ->get(['id', 'slug', 'title', 'published_at']);

        return view('cms.index', compact('pages'));
    }

    public function show(string $slug): View
    {
        $page = CmsPage::published()
            ->where('slug', $slug)
            ->firstOrFail();

        return view('cms.show', compact('page'));
    }
}
