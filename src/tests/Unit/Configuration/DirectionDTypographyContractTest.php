<?php

/*
 * EPIC-013 WP1d: the Direction D font-delivery contract (gate G1, Amendment 1 A1.4).
 *
 * Asserts wiring, never glyphs or pixels: the three type roles from
 * docs/design/direction-d-design-system.md §3.1 are the Tailwind font families; every face is
 * self-hosted WOFF2 with font-display: swap; no third-party font host is referenced; both root
 * views share one preload seam that preloads IBM Plex Sans 400 and 500 only; and the committed
 * font directory holds no unused file and ships its SIL OFL licences.
 */

function directionDTypographyCss(): string
{
    return (string) file_get_contents(resource_path('css/app.css'));
}

/** @return list<array{family: string, weight: string, file: string, display: string|null, range: string|null, block: string}> */
function directionDFontFaces(): array
{
    preg_match_all('/@font-face\s*\{(.*?)\}/s', directionDTypographyCss(), $matches);

    return array_map(function (string $block): array {
        preg_match("/font-family:\s*'([^']+)'/", $block, $family);
        preg_match('/font-weight:\s*(\d+)/', $block, $weight);
        preg_match("/src:\s*url\('\.\.\/fonts\/([^']+)'\)\s*format\('woff2'\);/", $block, $file);
        preg_match('/font-display:\s*([a-z]+)/', $block, $display);
        preg_match('/unicode-range:\s*([^;]+);/', $block, $range);

        return [
            'family' => $family[1] ?? '',
            'weight' => $weight[1] ?? '',
            'file' => $file[1] ?? '',
            'display' => $display[1] ?? null,
            'range' => $range[1] ?? null,
            'block' => $block,
        ];
    }, $matches[1]);
}

/** @return array<string, string> root view path => contents */
function directionDRootViews(): array
{
    return [
        'app.blade.php' => (string) file_get_contents(resource_path('views/app.blade.php')),
        'layouts/app.blade.php' => (string) file_get_contents(resource_path('views/layouts/app.blade.php')),
    ];
}

function directionDFontPreloads(): string
{
    return (string) file_get_contents(resource_path('views/layouts/partials/font-preloads.blade.php'));
}

test('the three Direction D type roles are the Tailwind font families, with the contract fallbacks', function () {
    preg_match('/^@theme\s*\{(.*?)^\}/ms', directionDTypographyCss(), $theme);

    expect($theme[1] ?? '')
        ->toMatch("/^\s*--font-sans:\s*'IBM Plex Sans',\s*system-ui,\s*sans-serif[,;]/m")
        ->toMatch("/^\s*--font-mono:\s*'IBM Plex Mono',\s*ui-monospace,\s*monospace;/m")
        ->toMatch("/^\s*--font-display:\s*'Newsreader',\s*Georgia,\s*serif;/m");
});

test('every face is self-hosted WOFF2 with font-display swap and a unicode-range', function () {
    $faces = directionDFontFaces();

    $declared = array_map(
        fn (array $face): string => $face['family'].' '.$face['weight'].' '.(str_contains($face['file'], '-latin-ext-') ? 'latin-ext' : 'latin'),
        $faces,
    );
    sort($declared);

    expect($declared)->toBe([
        'IBM Plex Mono 400 latin', 'IBM Plex Mono 500 latin',
        'IBM Plex Sans 400 latin', 'IBM Plex Sans 400 latin-ext',
        'IBM Plex Sans 500 latin', 'IBM Plex Sans 500 latin-ext',
        'IBM Plex Sans 600 latin', 'IBM Plex Sans 600 latin-ext',
        'Newsreader 400 latin', 'Newsreader 500 latin',
    ]);

    foreach ($faces as $face) {
        expect($face['display'])->toBe('swap', "{$face['file']} font-display")
            ->and($face['range'])->not->toBeNull("{$face['file']} unicode-range")
            ->and($face['block'])->not->toMatch('#https?://#')
            ->and(is_file(resource_path('fonts/'.$face['file'])))->toBeTrue("{$face['file']} is committed");
    }
});

test('no third-party font host is referenced by the stylesheet, either root view or the preload seam', function () {
    $sources = ['css/app.css' => directionDTypographyCss(), 'font-preloads' => directionDFontPreloads()] + directionDRootViews();

    foreach ($sources as $name => $contents) {
        expect($contents)->not->toMatch('/fonts\.bunny\.net|fonts\.googleapis\.com|fonts\.gstatic\.com|use\.typekit\.net|rel="preconnect"/i', $name);
    }
});

test('both root views share one preload seam that preloads IBM Plex Sans 400 and 500 only', function () {
    foreach (directionDRootViews() as $name => $contents) {
        expect(substr_count($contents, "@include('layouts.partials.font-preloads')"))->toBe(1, $name)
            ->and($contents)->not->toContain('as="font"');
    }

    preg_match_all('/<link\s+rel="preload"[^>]*>/', directionDFontPreloads(), $links);

    expect($links[0])->toHaveCount(2);

    $preloaded = [];
    foreach ($links[0] as $link) {
        expect($link)->toContain('as="font"')->toContain('type="font/woff2"')->toMatch('/\scrossorigin[\s>]/');
        preg_match("/Vite::asset\('resources\/fonts\/([^']+)'\)/", $link, $file);
        $preloaded[] = $file[1] ?? '';
    }
    sort($preloaded);

    expect($preloaded)->toBe(['ibm-plex-sans-latin-400-normal.woff2', 'ibm-plex-sans-latin-500-normal.woff2']);

    // A preload is only reused when it is the exact file an @font-face rule requests.
    $referenced = array_column(directionDFontFaces(), 'file');
    foreach ($preloaded as $file) {
        expect($referenced)->toContain($file);
    }
});

test('the committed font directory holds only referenced faces and ships the SIL OFL licences', function () {
    $committed = array_map('basename', glob(resource_path('fonts/*.woff2')) ?: []);
    $referenced = array_column(directionDFontFaces(), 'file');
    sort($committed);
    sort($referenced);

    expect($committed)->toBe($referenced);

    foreach (['OFL-IBM-Plex.txt' => 'IBM Corp.', 'OFL-Newsreader.txt' => 'The Newsreader Project Authors'] as $licence => $holder) {
        expect(resource_path('fonts/'.$licence))->toBeFile()
            ->and((string) file_get_contents(resource_path('fonts/'.$licence)))
            ->toContain('SIL Open Font License, Version 1.1')
            ->toContain($holder);
    }
});
