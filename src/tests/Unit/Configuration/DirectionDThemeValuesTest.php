<?php

/*
 * EPIC-016 §18.3 "pre-existing token values" (WP3, PR 3): the focused `--ds-*` name -> value pin.
 *
 * `DirectionDThemeContractTest` pins names and wiring only. This pins the VALUES of the canonical Direction D
 * colour tokens (docs/design/direction-d-design-system.md §2.2) in both theme blocks of `resources/css/app.css`,
 * so that:
 *   - WP3 provably changed no existing shared token to reach visual parity on the Blade pages, and
 *   - the alias retirement of WP4, which edits `app.css`, cannot move one silently.
 *
 * The expected values below are typed here from the specification table, independently of `app.css`; nothing is
 * derived from the CSS under test, and the generated stylesheet is not snapshotted. Changing a token on purpose
 * means updating the specification, this map and the owner decision together.
 */

dataset('direction d token values', [
    // token            => [light, dark]
    'canvas' => ['canvas', '#F6F5F1', '#0D1416'],
    'rail' => ['rail', '#EBE8E1', '#091012'],
    'drawer' => ['drawer', '#F1EFEA', '#10191C'],
    'surface' => ['surface', '#FFFFFF', '#142023'],
    'surface-sunken' => ['surface-sunken', '#EFEDE7', '#0F181A'],
    'surface-hover' => ['surface-hover', '#E8E5DD', '#162427'],
    'surface-selected' => ['surface-selected', '#FFFFFF', '#18292D'],
    'rule' => ['rule', '#E3E0D8', '#1E2D31'],
    'rule-control' => ['rule-control', '#D2CEC3', '#2A3D42'],
    'control-edge' => ['control-edge', '#8B877C', '#617679'],
    'rule-strong' => ['rule-strong', '#1A1B1E', '#C8D7D9'],
    'text' => ['text', '#1A1B1E', '#E6EEEF'],
    'text-secondary' => ['text-secondary', '#3F4248', '#C3D1D3'],
    'text-muted' => ['text-muted', '#5C5F66', '#98AEB2'],
    'text-faint' => ['text-faint', '#8A8D93', '#6D8388'],
    'accent' => ['accent', '#0B6A73', '#7ADDE4'],
    'accent-hover' => ['accent-hover', '#084E55', '#B2F4F7'],
    'accent-soft' => ['accent-soft', '#E3EEEE', '#12292C'],
    'accent-line' => ['accent-line', '#0B6A73', '#19E7F2'],
    'live' => ['live', '#0B8792', '#19E7F2'],
    'live-soft' => ['live-soft', '#E4F2F2', '#0E2629'],
    'live-text' => ['live-text', '#0B6A73', '#19E7F2'],
    'ink' => ['ink', '#1A1B1E', '#E6EEEF'],
    'on-ink' => ['on-ink', '#FFFFFF', '#0D1416'],
    'danger' => ['danger', '#B42318', '#FF8A7A'],
    'danger-soft' => ['danger-soft', '#FBEAE7', '#2A1715'],
    'warning' => ['warning', '#8A5A0B', '#F2B45A'],
    'warning-glyph' => ['warning-glyph', '#B7791F', '#F2B45A'],
    'warning-soft' => ['warning-soft', '#FAF0DC', '#2A2013'],
    'success' => ['success', '#2B7A4B', '#6FD39A'],
    'success-glyph' => ['success-glyph', '#2E8B57', '#4CCB86'],
    'progress-fill' => ['progress-fill', '#1A1B1E', '#C8D7D9'],
    'progress-track' => ['progress-track', '#E4E1D9', '#1E2D31'],
    'stage-future' => ['stage-future', '#C9C5BA', '#34494E'],
    'focus' => ['focus', '#0B6A73', '#19E7F2'],
    'scrim' => ['scrim', 'rgba(26,27,30,.18)', 'rgba(0,0,0,.45)'],
]);

/** The declared value of `--ds-{token}` inside one theme block, normalised for comparison. */
function themeValuePinned(string $selector, string $token): ?string
{
    $css = (string) file_get_contents(resource_path('css/app.css'));

    if (preg_match('/^'.$selector.'\s*\{(.*?)^\}/ms', $css, $block) !== 1) {
        return null;
    }

    if (preg_match('/^\s*--ds-'.preg_quote($token, '/').':\s*([^;]+);/m', $block[1], $match) !== 1) {
        return null;
    }

    // Compare case-insensitively and ignoring spaces, and read `0.18` as `.18` (the specification writes `.18`).
    return preg_replace('/(?<![\d])0\./', '.', strtolower(preg_replace('/\s+/', '', $match[1])));
}

function themeValueExpected(string $value): string
{
    return preg_replace('/(?<![\d])0\./', '.', strtolower(preg_replace('/\s+/', '', $value)));
}

it('keeps every canonical Direction D colour token at its specified light and dark value', function (string $token, string $light, string $dark) {
    expect(themeValuePinned('\[data-theme="light"\]', $token))->toBe(themeValueExpected($light), "--ds-{$token} (light)")
        ->and(themeValuePinned('\[data-theme="dark"\]', $token))->toBe(themeValueExpected($dark), "--ds-{$token} (dark)");
})->with('direction d token values');

it('detects a changed value, so the pin cannot be vacuous', function () {
    // The comparison used above, run against a doctored declaration: a one-digit drift in `text-muted` must not match.
    $declared = themeValueExpected('#5C5F67');

    expect($declared)->not->toBe(themeValueExpected('#5C5F66'))
        ->and(themeValueExpected('rgba(26, 27, 30, 0.18)'))->toBe(themeValueExpected('rgba(26,27,30,.18)'));
});
