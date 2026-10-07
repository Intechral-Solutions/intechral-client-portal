{{--
    EPIC-016 WP2: the CMS page state mapping (§9.6). Direction D §10 defines no CMS states, so this follows
    the invoice paid / draft pattern: Published is success + check, Draft is neutral + dashed. The publish
    rules (Page::isPublished()) are unchanged.

    @param bool $published
--}}
@if ($published)
<x-ui.status tone="success" glyph="check" data-page-state="published">Published</x-ui.status>
@else
<x-ui.status tone="neutral" glyph="dashed" data-page-state="draft">Draft</x-ui.status>
@endif
