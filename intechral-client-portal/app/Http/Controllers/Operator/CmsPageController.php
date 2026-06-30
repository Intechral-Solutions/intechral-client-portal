<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use App\Services\CmsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CmsPageController extends Controller
{
    public function __construct(private CmsService $service) {}

    public function index(): View
    {
        $pages = CmsPage::with('creator')
            ->orderByDesc('updated_at')
            ->paginate(25);

        return view('operator.cms.index', compact('pages'));
    }

    public function create(): View
    {
        return view('operator.cms.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|regex:/^[a-z0-9\-]+$/|unique:cms_pages,slug',
            'body' => 'nullable|string',
        ]);

        $page = $this->service->create($request->user(), $data);

        return redirect()->route('operator.cms.edit', $page)
            ->with('success', 'Page created.');
    }

    public function edit(CmsPage $page): View
    {
        return view('operator.cms.edit', compact('page'));
    }

    public function update(Request $request, CmsPage $page): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => "nullable|string|max:255|regex:/^[a-z0-9\\-]+$/|unique:cms_pages,slug,{$page->id}",
            'body' => 'nullable|string',
        ]);

        $this->service->update($page, $request->user(), $data);

        return redirect()->route('operator.cms.edit', $page)
            ->with('success', 'Page saved.');
    }

    public function publish(Request $request, CmsPage $page): RedirectResponse
    {
        abort_unless($request->user()->can('cms.publish'), 403);

        $this->service->publish($page, $request->user());

        return redirect()->route('operator.cms.edit', $page)
            ->with('success', 'Page published.');
    }

    public function unpublish(Request $request, CmsPage $page): RedirectResponse
    {
        abort_unless($request->user()->can('cms.publish'), 403);

        $this->service->unpublish($page, $request->user());

        return redirect()->route('operator.cms.edit', $page)
            ->with('success', 'Page set back to draft.');
    }

    public function destroy(Request $request, CmsPage $page): RedirectResponse
    {
        abort_unless($request->user()->can('cms.admin'), 403);

        $this->service->delete($page);

        return redirect()->route('operator.cms.index')
            ->with('success', 'Page deleted.');
    }
}
