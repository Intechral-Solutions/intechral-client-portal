<?php

/*
 * EPIC-016 WP2 §10.1 / §20 #10: the contrast evidence for the semantic-state surfaces WP2 migrates, in both
 * themes. It closes the EPIC-013 A13.4 / A4.8 debts recorded in EPIC-016 §3.5 for status, priority,
 * overdue, internal note, tags, alerts and the live state.
 *
 *   - text (labels, due dates, figures): at least 4.5:1 (WCAG 1.4.3);
 *   - non-text semantic marks (status glyphs, priority bars, the dashed internal-note boundary): at least
 *     3:1 (WCAG 1.4.11).
 *
 * Token values are read from the `[data-theme]` blocks of resources/css/app.css, so a retuned token fails
 * here. The page backgrounds are the Direction D surfaces plus the legacy page and card backgrounds the
 * Blade bodies still sit on until WP3 (the same approximations DirectionDThemeContractTest uses). The real
 * rendered pairs are measured in the browser by tests/Browser/blade-theme-controls.spec.ts (the "Semantic state" blocks). The claim is
 * scoped to WP2's surfaces; it is not a product-wide WCAG conformance claim.
 */

require_once __DIR__.'/../../Support/UiHtmlHelpers.php';

/** The surfaces a WP2 mark can sit on in a theme: Direction D surfaces plus the legacy Blade page and card backgrounds. */
function semPageSurfaces(string $theme): array
{
    $legacy = ['light' => ['#FFFFFF', '#F9FAFB'], 'dark' => ['#030712', '#111827', '#1F2937']][$theme];

    return array_merge(array_map(fn (string $t) => uiTokenHex($theme, $t), ['canvas', 'surface']), $legacy);
}

function semAssertContrast(string $theme, string $fg, array $backgrounds, float $minimum): void
{
    foreach ($backgrounds as $background) {
        $ratio = uiContrast(uiTokenHex($theme, $fg), $background);

        expect($ratio)->toBeGreaterThanOrEqual($minimum, "{$theme}: --ds-{$fg} on {$background} is ".round($ratio, 2).":1, needs {$minimum}:1");
    }
}

dataset('themes', ['light', 'dark']);

it('keeps every status, priority, overdue, tag and live LABEL at 4.5:1 on every page surface', function (string $theme) {
    $surfaces = semPageSurfaces($theme);

    foreach ([
        'accent',          // Open, In Progress, Sent (info)
        'text-muted',      // Pending, Closed, Draft, Cancelled, Built-in / Custom, kind tags
        'success',         // Resolved, Paid, Published, Enabled, ✓ Paid, +amount
        'danger',          // Overdue status, overdue due dates, Critical priority
        'text-secondary',  // Low / Medium / High priority labels
        'live-text',       // Timer running
    ] as $label) {
        semAssertContrast($theme, $label, $surfaces, 4.5);
    }
})->with('themes');

it('keeps every status GLYPH, priority bar and live dot at 3:1 on every page surface', function (string $theme) {
    $surfaces = semPageSurfaces($theme);

    foreach (['success-glyph', 'warning-glyph', 'live', 'accent', 'danger', 'text-muted', 'text-secondary'] as $glyph) {
        semAssertContrast($theme, $glyph, $surfaces, 3.0);
    }
})->with('themes');

it('keeps the internal note readable: warning text and body text on the warning-soft card, and its dashed boundary at 3:1', function (string $theme) {
    $card = uiTokenHex($theme, 'warning-soft');

    semAssertContrast($theme, 'warning', [$card], 4.5);       // "Internal Note" label
    semAssertContrast($theme, 'text', [$card], 4.5);          // the note body
    semAssertContrast($theme, 'text-secondary', [$card], 4.5); // timestamps inside the card
    semAssertContrast($theme, 'warning-glyph', [$card, ...semPageSurfaces($theme)], 3.0); // dashed border, lock glyph
})->with('themes');

it('keeps every alert variant legible: body text on its tint and the kind glyph on it', function (string $theme) {
    $tints = [
        'info' => ['accent-soft', 'accent'],
        'success' => ['surface', 'success'],
        'warning' => ['warning-soft', 'warning'],
        'danger' => ['danger-soft', 'danger'],
        'neutral' => ['surface', 'text'],
    ];

    foreach ($tints as [$tint, $glyph]) {
        $background = uiTokenHex($theme, $tint);

        semAssertContrast($theme, 'text', [$background], 4.5);   // the message body
        semAssertContrast($theme, $glyph, [$background], 4.5); // the glyph colour is text-safe, so it clears the text bar too
    }
})->with('themes');

it('keeps the soft status tints distinguishable from the page they sit on only by meaning, never by colour', function () {
    // The honest limit: the soft tints (accent-soft, warning-soft, danger-soft) are decorative fills a few
    // percent apart from the page, far below 3:1, by Direction D design. They are never the signal: every
    // alert carries a glyph and an sr-only kind label, and the internal note carries a lock glyph, the
    // "Internal Note" text and a dashed boundary. This test pins that the tints stay decorative, and that
    // each is paired in the markup with a non-colour signal.
    foreach (['light', 'dark'] as $theme) {
        foreach (['accent-soft', 'warning-soft', 'danger-soft'] as $tint) {
            expect(uiContrast(uiTokenHex($theme, $tint), uiTokenHex($theme, 'surface')))->toBeLessThan(1.5, "{$theme} {$tint}");
        }
    }

    $alert = (string) file_get_contents(resource_path('views/components/ui/alert.blade.php'));
    $note = (string) file_get_contents(resource_path('views/tickets/_internal_note_label.blade.php'));

    expect($alert)->toContain('sr-only', '<svg')
        ->and($note)->toContain('<svg', 'Internal Note');
});

it('would catch a regression: the retired pastel priority and status pills fail the bars this suite sets', function () {
    // The legacy hex pairs EPIC-016 §3.5 measured. They are NOT in the product any more; this proves the
    // 4.5:1 / 3:1 bars above are able to fail, so the evidence is not vacuous.
    $legacy = [
        ['#ca8a04', '#fefce8'], // medium priority pill 2.84
        ['#ea580c', '#fff7ed'], // high 3.35
        ['#16a34a', '#f0fdf4'], // low 3.15 (and resolved)
        ['#dc2626', '#fef2f2'], // critical 4.41
        // The same hex is used in both themes, so on the dark legacy card they collapse further:
        ['#2563eb', '#1F2937'], // in_progress on dark 2.84
        ['#7c3aed', '#1F2937'], // pending on dark 2.58
    ];

    foreach ($legacy as [$text, $background]) {
        expect(uiContrast($text, $background))->toBeLessThan(4.5);
    }

    // The dark overdue row: theme text on the light bg-red-50 row.
    expect(uiContrast(uiTokenHex('dark', 'text'), '#FEF2F2'))->toBeLessThan(1.5);
});
