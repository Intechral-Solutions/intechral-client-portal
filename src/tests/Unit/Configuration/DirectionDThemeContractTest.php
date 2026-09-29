<?php

/*
 * EPIC-013 WP1c: the Direction D semantic theme contract in resources/css/app.css.
 *
 * Asserts names and wiring only, never colour values: every canonical token from
 * docs/design/direction-d-design-system.md §2.2 is defined in both theme blocks and
 * exposed to Tailwind, and the compatibility layer unmigrated screens depend on
 * stays defined (Direction D §2.4). A missing dark value would otherwise fall back
 * to the light one silently.
 */

dataset('direction d colour tokens', [
    'canvas', 'rail', 'drawer', 'surface', 'surface-sunken', 'surface-hover', 'surface-selected',
    'rule', 'rule-control', 'control-edge', 'rule-strong',
    'text', 'text-secondary', 'text-muted', 'text-faint',
    'accent', 'accent-hover', 'accent-soft', 'accent-line',
    'live', 'live-soft', 'live-text',
    'ink', 'on-ink',
    'danger', 'danger-soft', 'warning', 'warning-glyph', 'warning-soft', 'success', 'success-glyph',
    'progress-fill', 'progress-track', 'stage-future',
    'focus', 'scrim',
]);

function directionDThemeCss(): string
{
    return (string) file_get_contents(resource_path('css/app.css'));
}

function directionDThemeBlock(string $selector): string
{
    preg_match('/^'.$selector.'\s*\{(.*?)^\}/ms', directionDThemeCss(), $match);

    return $match[1] ?? '';
}

function directionDDeclarationCount(string $block, string $property): int
{
    return preg_match_all('/^\s*'.preg_quote($property, '/').':/m', $block);
}

function directionDTailwindExposure(): string
{
    preg_match_all('/@theme inline\s*\{(.*?)^\}/ms', directionDThemeCss(), $matches);

    return implode("\n", $matches[1]);
}

test('each canonical colour token is defined once per theme and exposed to Tailwind', function (string $token) {
    $light = directionDThemeBlock('\[data-theme="light"\]');
    $dark = directionDThemeBlock('\[data-theme="dark"\]');

    expect(directionDDeclarationCount($light, "--ds-{$token}"))->toBe(1)
        ->and(directionDDeclarationCount($dark, "--ds-{$token}"))->toBe(1)
        ->and(directionDTailwindExposure())->toContain("--color-{$token}: var(--ds-{$token});");
})->with('direction d colour tokens');

test('the canonical text utilities, shadows and motion tokens are exposed', function () {
    $light = directionDThemeBlock('\[data-theme="light"\]');
    $dark = directionDThemeBlock('\[data-theme="dark"\]');
    $invariant = directionDThemeBlock(':root');
    $exposure = directionDTailwindExposure();

    foreach (['secondary', 'muted', 'faint'] as $text) {
        expect($exposure)->toContain("--text-color-{$text}: var(--ds-text-{$text});");
    }

    foreach (['shadow-card', 'shadow-overlay'] as $shadow) {
        expect(directionDDeclarationCount($light, "--ds-{$shadow}"))->toBe(1)
            ->and(directionDDeclarationCount($dark, "--ds-{$shadow}"))->toBe(1)
            ->and($exposure)->toContain("--{$shadow}: var(--ds-{$shadow});");
    }

    foreach (['fast', 'base', 'panel', 'sheet'] as $motion) {
        expect(directionDDeclarationCount($invariant, "--ds-motion-{$motion}"))->toBe(1)
            ->and($exposure)->toContain("--transition-duration-motion-{$motion}: var(--ds-motion-{$motion});");
    }
});

test('the compatibility layer stays defined in both theme blocks', function () {
    $compatibility = [
        // shadcn aliases
        '--background', '--foreground', '--card', '--card-foreground', '--primary', '--primary-foreground',
        '--secondary', '--secondary-foreground', '--muted', '--muted-foreground', '--accent-foreground',
        '--destructive', '--destructive-foreground', '--border', '--input', '--ring',
        // raw legacy families
        '--bg-base', '--bg-surface', '--bg-elevated', '--bg-overlay',
        '--surface-card', '--surface-input', '--surface-success', '--surface-danger', '--surface-warning', '--surface-info',
        '--border-subtle', '--border-base', '--border-success', '--border-danger', '--border-warning', '--border-info',
        '--text-primary', '--text-secondary', '--text-muted', '--text-inverse',
        '--text-success', '--text-danger', '--text-warning', '--text-info',
        '--accent', '--accent-hover', '--accent-text', '--danger', '--success', '--warning', '--info',
        '--shadow-sm', '--shadow-md', '--shadow-lg',
        // EPIC-013 WP1b orphans
        '--surface-base', '--surface-muted', '--surface-elevated', '--border-muted', '--surface-accent', '--accent-success',
    ];

    foreach (['light', 'dark'] as $theme) {
        $block = directionDThemeBlock('\[data-theme="'.$theme.'"\]');

        foreach ($compatibility as $property) {
            expect(directionDDeclarationCount($block, $property))->toBe(1, "{$property} in {$theme}");
        }
    }

    // `legacy-success` / `legacy-warning` were retired in WP2 with the Alert restyle (their only
    // consumer, FlashRegion, moved to the Direction D Alert); `legacy-accent` still has Blade consumers.
    expect(directionDTailwindExposure())
        ->toContain('--color-legacy-accent: var(--accent);')
        ->not->toContain('--color-legacy-success')
        ->not->toContain('--color-legacy-warning');
});

/*
 * EPIC-013 WP2 (Amendment 6): `primary` was held on the legacy indigo `accent` by WP1c and is now
 * flipped to Direction D ink, together with the Button restyle. It is safe only because every consumer
 * that meant "accent" (active-tab text and border, link hover, checkbox accent-color, hover text in the
 * Blade nav, the avatar and progress fills) was first given an explicit token. This pair of tests is the
 * guard for both halves: the mapping, and the absence of accent-meaning `primary` idioms.
 */
test('primary is the Direction D ink, and primary-foreground its on-ink text', function () {
    foreach (['light', 'dark'] as $theme) {
        $block = directionDThemeBlock('\[data-theme="'.$theme.'"\]');

        expect($block)
            ->toMatch('/^\s*--primary:\s*var\(--ds-ink\);/m')
            ->toMatch('/^\s*--primary-foreground:\s*var\(--ds-on-ink\);/m');
    }
});

test('no source uses `primary` for an accent meaning (text, border, ring or accent-color)', function () {
    $accentIdiom = '/(?<![\w-])(?:text|border|ring|accent|outline|fill|stroke|decoration|divide)-primary(?![\w-])/';
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path()));

    foreach ($iterator as $file) {
        if (! $file->isFile() || ! preg_match('/\.(tsx?|jsx?|php)$/', $file->getFilename())) {
            continue;
        }

        if (preg_match($accentIdiom, (string) file_get_contents($file->getPathname()), $match)) {
            $offenders[] = str_replace(resource_path().'/', '', $file->getPathname()).' => '.$match[0];
        }
    }

    // `bg-primary` / `text-primary-foreground` (an action fill and its text) are still legitimate ink
    // uses; the accent-meaning idioms above must go through `accent`, `accent-line`, `ink` or `legacy-accent`.
    expect($offenders)->toBe([]);
});

test('the WP1b compatibility orphans keep their accepted mappings', function () {
    $mappings = [
        '--surface-base' => 'var(--bg-base)',
        '--surface-muted' => 'var(--bg-surface)',
        '--surface-elevated' => 'var(--bg-surface)',
        '--border-muted' => 'var(--border-subtle)',
        '--surface-accent' => 'var(--surface-info)',
        '--accent-success' => 'var(--success)',
    ];

    foreach (['light', 'dark'] as $theme) {
        $block = directionDThemeBlock('\[data-theme="'.$theme.'"\]');

        foreach ($mappings as $property => $value) {
            expect($block)->toMatch('/^\s*'.preg_quote($property, '/').':\s*'.preg_quote($value, '/').';/m');
        }
    }
});

/*
 * EPIC-013 WP2: the primitive layer's own theme wiring. Names only, as above.
 */
test('the Direction D radius scale is exposed as Tailwind utilities', function () {
    $css = directionDThemeCss();

    foreach (['control' => '5px', 'control-lg' => '7px', 'overlay' => '8px', 'tag' => '3px'] as $name => $value) {
        expect($css)->toMatch('/^\s*--radius-'.preg_quote($name, '/').':\s*'.preg_quote($value, '/').';/m');
    }
});

test('overlay animations use the motion tokens and collapse under prefers-reduced-motion', function () {
    $css = directionDThemeCss();

    foreach (['overlay-in', 'overlay-out', 'dialog-in', 'dialog-out', 'menu-in', 'menu-out'] as $animation) {
        expect($css)->toMatch('/^\s*--animate-'.$animation.':.*var\(--ds-anim-/m');
    }

    // Durations derive from the WP1c motion tokens; exits are 70% of the enter (§16).
    expect($css)
        ->toMatch('/--ds-anim-sheet:\s*var\(--ds-motion-sheet\);/')
        ->toMatch('/--ds-anim-sheet-exit:\s*calc\(var\(--ds-motion-sheet\) \* 0\.7\);/');

    // Under reduced motion the translation is removed and fades are capped at motion-fast.
    preg_match('/@media \(prefers-reduced-motion: reduce\)\s*\{\s*:root\s*\{(.*?)\}/s', $css, $reduced);
    expect($reduced[1] ?? '')
        ->toMatch('/--ds-shift:\s*0px;/')
        ->toMatch('/--ds-anim-sheet:\s*var\(--ds-motion-fast\);/');
});

/*
 * EPIC-013 WP2 (Amendment 6, A6.25): the resting boundary of an interactive control must reach 3:1
 * (WCAG 1.4.11) against every surface a field is rendered on. `rule-control` cannot (it is a
 * structural hairline at about 1.5:1), so `control-edge` exists. This is the one place the suite checks
 * colour VALUES, because a wiring-only test would let a later "tidy-up" quietly make it faint again.
 */
function directionDLuminance(string $hex): float
{
    $channels = array_map(
        fn (string $pair) => hexdec($pair) / 255,
        str_split(ltrim($hex, '#'), 2),
    );
    [$r, $g, $b] = array_map(fn (float $c) => $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $channels);

    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

function directionDContrast(string $a, string $b): float
{
    [$x, $y] = [directionDLuminance($a), directionDLuminance($b)];

    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

function directionDHex(string $block, string $property): string
{
    preg_match('/^\s*'.preg_quote($property, '/').':\s*(#[0-9A-Fa-f]{6})/m', $block, $match);

    return $match[1] ?? '';
}

test('control-edge reaches 3:1 on every surface a form control renders on, in both themes', function () {
    // Direction D surfaces plus the legacy page and card backgrounds React forms still sit on today.
    $legacy = [
        'light' => ['#FFFFFF', '#F9FAFB'],           // --bg-base, --bg-surface (gray-50)
        'dark' => ['#030712', '#111827', '#1F2937'], // gray-950 base, gray-900, gray-800 card
    ];

    foreach (['light', 'dark'] as $theme) {
        $block = directionDThemeBlock('\[data-theme="'.$theme.'"\]');
        $edge = directionDHex($block, '--ds-control-edge');
        expect($edge)->not->toBe('');

        $surfaces = array_map(fn (string $t) => directionDHex($block, "--ds-{$t}"), ['canvas', 'surface', 'drawer', 'surface-sunken']);

        foreach (array_merge($surfaces, $legacy[$theme]) as $surface) {
            expect(directionDContrast($edge, $surface))->toBeGreaterThanOrEqual(3.0, "{$theme}: {$edge} on {$surface}");
        }

        // The structural hairline stays a hairline: it must not have been raised to serve controls.
        expect(directionDContrast(directionDHex($block, '--ds-rule-control'), directionDHex($block, '--ds-canvas')))
            ->toBeLessThan(2.0);
    }
});
