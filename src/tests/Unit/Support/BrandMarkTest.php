<?php

/*
 * EPIC-013 WP5: App\Support\BrandMark is the Blade port of scopeBrandSvg() in
 * resources/js/components/shell/brand-mark.tsx (A8.19). These cases mirror brand-mark.test.tsx, so the
 * two renderers are held to the same transform, plus one the React suite does not need: the canonical
 * path data is never altered (L16).
 */

use App\Support\BrandMark;

function brandMarkSource(string $file): string
{
    return (string) file_get_contents(resource_path('images/brand/'.$file));
}

function brandMarkDom(string $svg): DOMXPath
{
    $dom = new DOMDocument;
    $dom->loadXML($svg);

    return new DOMXPath($dom);
}

it('carries the brand-mark class, the label and the image role', function () {
    $svg = brandMarkDom(BrandMark::scope(brandMarkSource('intechral-logo-compact.svg'), 'bm1', 'Intechral'));
    $root = $svg->query('/*')->item(0);

    // §10.3: light flattens the hard-coded cyan to ink through `.brand-mark stop` in app.css.
    expect($root->getAttribute('class'))->toBe('brand-mark')
        ->and($root->getAttribute('aria-label'))->toBe('Intechral')
        ->and($root->getAttribute('role'))->toBe('img');
});

it('gives two marks on one page distinct ids that each resolve their own gradient', function () {
    $compact = BrandMark::scope(brandMarkSource('intechral-logo-compact.svg'), 'bm1', 'One');
    $full = BrandMark::scope(brandMarkSource('intechral-logo.svg'), 'bm2', 'Two');

    preg_match_all('/\sid="([^"]+)"/', $compact.$full, $ids);

    expect($ids[1])->not->toBeEmpty()
        ->and(array_unique($ids[1]))->toHaveCount(count($ids[1]));

    foreach ([$compact, $full] as $svg) {
        $dom = brandMarkDom($svg);
        $gradient = $dom->query('//*[local-name()="linearGradient"]')->item(0)->getAttribute('id');

        expect($gradient)->not->toBe('')
            ->and($dom->query('//*[local-name()="style"]')->item(0)->textContent)->toContain("url(#{$gradient})");
    }
});

it('assigns every inline instance a fresh token', function () {
    preg_match('/linearGradient id="([^"]+)"/', BrandMark::inline('compact', 'A'), $first);
    preg_match('/linearGradient id="([^"]+)"/', BrandMark::inline('compact', 'B'), $second);

    expect($first[1])->not->toBe($second[1]);
});

it('strips the embedded title and desc so the partial owns the accessible name', function () {
    foreach (['intechral-logo-compact.svg', 'intechral-logo.svg'] as $file) {
        $svg = BrandMark::scope(brandMarkSource($file), 'bm1', 'Intechral');

        expect(str_contains($svg, '<title'))->toBeFalse()
            ->and(str_contains($svg, '<desc'))->toBeFalse()
            ->and(str_contains($svg, 'aria-labelledby'))->toBeFalse();
    }
});

it('removes the non-scaling stroke that makes the full mark unusable at small sizes', function () {
    expect(brandMarkSource('intechral-logo.svg'))->toContain('non-scaling-stroke')
        ->and(str_contains(BrandMark::scope(brandMarkSource('intechral-logo.svg'), 'bm1', 'X'), 'non-scaling-stroke'))->toBeFalse();
});

it('scopes the document-global class the embedded stylesheet declares', function () {
    $scoped = BrandMark::scope(
        '<svg><defs><linearGradient id="g"><stop/></linearGradient><style>.s{stroke:url(#g)}</style></defs><path class="s"/></svg>',
        'bm1',
        'Mark',
    );

    // The exact string brand-mark.tsx produces for the same fixture.
    expect($scoped)->toBe('<svg class="brand-mark" aria-label="Mark"><defs><linearGradient id="bm1-g"><stop/></linearGradient><style>.bm1-s{stroke:url(#bm1-g)}</style></defs><path class="bm1-s"/></svg>');
});

it('escapes a label so it cannot break out of the attribute', function () {
    expect(BrandMark::scope('<svg></svg>', 'bm1', 'A "quoted" name'))
        ->toContain('aria-label="A &quot;quoted&quot; name"');
});

it('never alters the canonical path data', function () {
    foreach (['intechral-logo-compact.svg', 'intechral-logo.svg'] as $file) {
        $source = brandMarkSource($file);

        preg_match_all('/\sd="([^"]+)"/', $source, $before);
        preg_match_all('/\sd="([^"]+)"/', BrandMark::scope($source, 'bm1', 'X'), $after);

        expect($after[1])->not->toBeEmpty()
            ->and($after[1])->toBe($before[1]);
    }
});
