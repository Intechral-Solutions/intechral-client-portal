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
    'rule', 'rule-control', 'rule-strong',
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

    expect(directionDTailwindExposure())
        ->toContain('--color-legacy-accent: var(--accent);')
        ->toContain('--color-legacy-success: var(--success);')
        ->toContain('--color-legacy-warning: var(--warning);');
});

test('primary stays on the legacy accent until WP2 flips it with the Button restyle', function () {
    foreach (['light', 'dark'] as $theme) {
        expect(directionDThemeBlock('\[data-theme="'.$theme.'"\]'))
            ->toMatch('/^\s*--primary:\s*var\(--accent\);/m');
    }
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
