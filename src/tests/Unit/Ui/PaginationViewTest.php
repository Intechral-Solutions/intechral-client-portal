<?php

/*
 * EPIC-016 WP1 §7.4 / §18.3: the Direction D Blade pagination view, registered as the application default
 * for both the length-aware and the simple paginator. Laravel still builds every URL, so these tests hold
 * the new view to the vendor view's affordances (prev/next, numbered pages, ellipsis, summary, compact
 * mobile) and URLs, query string included.
 */

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

require_once __DIR__.'/../../Support/UiHtmlHelpers.php';

function uiPaginator(int $current, int $total = 100, int $perPage = 5): LengthAwarePaginator
{
    return (new LengthAwarePaginator(range(1, $perPage), $total, $perPage, $current, ['path' => '/crm/contacts']))
        ->appends(['search' => 'a b', 'sort' => 'name']);
}

/** @return list<string> */
function uiHrefs(string $html): array
{
    $hrefs = [];
    foreach (uiDom($html)->query('//a[@href]') as $anchor) {
        $hrefs[] = $anchor->getAttribute('href');
    }

    return array_values(array_unique($hrefs));
}

it('is the application default for the length-aware and the simple paginator', function () {
    $paginator = uiPaginator(4);
    $simple = new Paginator(range(1, 5), 5, 2, ['path' => '/x']);

    expect((string) $paginator->links())->toBe((string) $paginator->links('pagination.direction-d'))
        ->and((string) $simple->links())->toBe((string) $simple->links('pagination.simple-direction-d'))
        ->and((string) $paginator->links())->not->toContain('Pagination Navigation');
});

it('renders a nav named Pagination with previous and next carrying rel and the generated URLs', function () {
    $xpath = uiDom((string) uiPaginator(4)->links());
    $nav = uiOne($xpath, '//nav');

    expect($nav->getAttribute('aria-label'))->toBe('Pagination')
        ->and($xpath->query('//a[@rel="prev"]')->length)->toBeGreaterThan(0)
        ->and($xpath->query('//a[@rel="next"]')->length)->toBeGreaterThan(0);

    foreach ($xpath->query('//a[@rel="prev"]') as $prev) {
        expect(html_entity_decode($prev->getAttribute('href')))->toBe('/crm/contacts?search=a%20b&sort=name&page=3');
    }
    foreach ($xpath->query('//a[@rel="next"]') as $next) {
        expect(html_entity_decode($next->getAttribute('href')))->toBe('/crm/contacts?search=a%20b&sort=name&page=5');
    }
});

it('links every numbered page with the query string preserved and marks only the current page', function () {
    $xpath = uiDom((string) uiPaginator(4)->links());
    $current = uiOne($xpath, '//*[@aria-current="page"]');
    $numbered = [];

    foreach ($xpath->query('//a[starts-with(@aria-label, "Go to page")]') as $anchor) {
        $numbered[$anchor->textContent] = html_entity_decode($anchor->getAttribute('href'));
    }

    expect($current->textContent)->toBe('4')
        ->and($current->tagName)->toBe('span')
        ->and(uiClasses($current))->toContain('bg-ink', 'text-on-ink')
        ->and($numbered)->not->toHaveKey('4')
        ->and($numbered['1'])->toBe('/crm/contacts?search=a%20b&sort=name&page=1')
        ->and($numbered['5'])->toBe('/crm/contacts?search=a%20b&sort=name&page=5')
        ->and($numbered['20'])->toBe('/crm/contacts?search=a%20b&sort=name&page=20')
        ->and(uiOne($xpath, '//a[@aria-label="Go to page 5"]')->textContent)->toBe('5');
});

it('produces exactly the URLs the vendor view produced for the same paginator', function (int $page) {
    $paginator = uiPaginator($page);

    expect(uiHrefs((string) $paginator->links()))
        ->toEqualCanonicalizing(uiHrefs((string) $paginator->links('pagination::tailwind')));
})->with([1, 4, 10, 19, 20]);

it('keeps the ellipsis and the "Showing x to y of z results" summary', function () {
    $html = (string) uiPaginator(4)->links();
    $xpath = uiDom($html);
    $summary = trim(preg_replace('/\s+/', ' ', uiOne($xpath, '//p')->textContent));

    expect($summary)->toBe('Showing 16 to 20 of 100 results')
        ->and(uiClasses(uiOne($xpath, '//p')))->toContain('text-text-secondary', 'tabular-nums')
        ->and($xpath->query('//span[@aria-hidden="true"][normalize-space()="..."]')->length)->toBe(1);
});

it('keeps the compact previous / next for narrow screens and the numbered navigation for wider ones', function () {
    $xpath = uiDom((string) uiPaginator(4)->links());
    $compact = uiOne($xpath, '//nav/div[contains(@class, "sm:hidden")]');
    $wide = uiOne($xpath, '//nav/div[contains(@class, "hidden") and contains(@class, "sm:flex")]');

    expect($compact->getElementsByTagName('a')->length)->toBe(2)
        ->and(trim($compact->textContent))->toContain('Previous', 'Next')
        ->and($wide->getElementsByTagName('a')->length)->toBeGreaterThan(2);
});

it('omits an unavailable direction instead of rendering a fake control', function () {
    $first = uiDom((string) uiPaginator(1)->links());
    $last = uiDom((string) uiPaginator(20)->links());

    expect($first->query('//a[@rel="prev"]')->length)->toBe(0)
        ->and($first->query('//a[@rel="next"]')->length)->toBeGreaterThan(0)
        ->and($last->query('//a[@rel="next"]')->length)->toBe(0)
        ->and($last->query('//a[@rel="prev"]')->length)->toBeGreaterThan(0)
        ->and(uiOne($last, '//*[@aria-current="page"]')->textContent)->toBe('20');
});

it('renders nothing for a single page', function () {
    $single = new LengthAwarePaginator(range(1, 3), 3, 5, 1, ['path' => '/x']);
    $simple = new Paginator(range(1, 3), 5, 1, ['path' => '/x']);

    expect(trim((string) $single->links()))->toBe('')
        ->and(trim((string) $simple->links()))->toBe('');
});

it('renders the simple paginator as previous / next only, with the query string preserved', function () {
    $simple = (new Paginator(range(1, 5), 5, 2, ['path' => '/tickets']))->appends(['status' => 'open']);
    $xpath = uiDom((string) $simple->links());

    expect(uiOne($xpath, '//nav')->getAttribute('aria-label'))->toBe('Pagination')
        ->and(html_entity_decode(uiOne($xpath, '//a[@rel="prev"]')->getAttribute('href')))->toBe('/tickets?status=open&page=1')
        ->and($xpath->query('//a[@rel="next"]')->length)->toBe(0)
        ->and($xpath->query('//*[@aria-current]')->length)->toBe(0);
});

it('uses only Direction D semantic utilities, so both themes follow data-theme and not the OS', function () {
    $html = (string) uiPaginator(4)->links();

    expect($html)->not->toMatch('/\bdark:/')
        ->and($html)->not->toMatch('/\b(?:bg|text|border|ring)-(?:gray|blue|indigo|slate|white|black)\b/')
        ->and($html)->not->toContain('focus:ring')
        ->and($html)->not->toContain('outline-none')
        ->and($html)->not->toContain('style=')
        ->and($html)->not->toContain('var(--');

    foreach (uiDom($html)->query('//a') as $anchor) {
        expect(uiClasses($anchor))->toContain(...UI_FOCUS_RING, ...['border-control-edge', 'bg-surface', 'text-text']);
    }
});

it('names every pagination link, with a clean accessible name for the arrows', function () {
    $xpath = uiDom((string) uiPaginator(4)->links());

    foreach ($xpath->query('//a') as $anchor) {
        expect(trim($anchor->textContent) !== '' || $anchor->getAttribute('aria-label') !== '')->toBeTrue();
    }

    expect(uiOne($xpath, '//a[@rel="prev"][@aria-label]')->getAttribute('aria-label'))->not->toContain('&');
});
