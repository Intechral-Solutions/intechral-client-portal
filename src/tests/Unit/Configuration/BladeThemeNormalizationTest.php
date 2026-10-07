<?php

/*
 * EPIC-016 WP3 (PR 3) exit check: "the §3.3 colour measures are zero in the target views" (§19 WP3 exit, §20 #11,
 * #12). Every application presentation colour in the four target workspaces, `errors/403` and the shared
 * components is a semantic theme utility: no raw legacy colour variable, no literal colour, no numeric palette
 * utility, no inline colour declaration, no legacy accent or brand utility, no dead legacy hover, and no 12px
 * (`rounded-xl`) card.
 *
 * This proves the WP3 MIGRATION is complete. It is deliberately NOT the permanent two-level guard of §17, which
 * is WP4 (it also covers React and `app.css`, and carries the allowlist, B5 and B7). A rule's detector is
 * tested against a fixture it must catch and a near-miss it must not, so the check cannot be vacuous.
 *
 * `var(--…)` is not banned as a language feature (O9): only the legacy RAW COLOUR families are. A non-colour
 * custom property (`width: var(--progress)`), `--ds-*` inside a primitive and a `style` that sets no colour pass.
 * Comments ({{-- … --}}) are removed first, since they document the retired values.
 */

/** The WP3 scope: the explicit path list of EPIC-016 §17.2. */
function themeNormScope(): array
{
    $root = dirname(__DIR__, 3).'/resources/views';
    $files = [];

    foreach (['tickets', 'operator/tickets', 'crm', 'organizations', 'billing', 'admin', 'operator/cms', 'components/ui', 'components/time-tracker', 'pagination'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$directory}", FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }
    }

    array_push($files, 'components/time-tracker.blade.php', 'errors/403.blade.php');
    sort($files);

    return $files;
}

function themeNormSource(string $relative): string
{
    $source = (string) file_get_contents(dirname(__DIR__, 3)."/resources/views/{$relative}");

    return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
}

/** Each rule: name => regex. A match anywhere in a scoped view is a violation. */
function themeNormRules(): array
{
    $families = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
    $prefixes = 'bg|text|border|ring|outline|divide|accent|fill|stroke|from|via|to|decoration|placeholder|caret|shadow';

    return [
        // B1: numeric palette utilities, and the named white / black.
        'palette utility' => "/(?<![\\w-])(?:[\\w\\[\\]:-]+:)?(?:{$prefixes})-(?:{$families})-\\d{2,3}(?![\\w-])/",
        'named white/black utility' => '/(?<![\\w-])(?:[\\w\\[\\]:-]+:)?(?:bg|text|border|ring|divide|fill|stroke)-(?:white|black)(?![\\w-])/',
        // B2: literal colour values: hex, rgb(a), hsl(a), oklch, and an arbitrary class value.
        'literal colour' => '/(?<![&\w])#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})(?![\w-])|\b(?:rgba?|hsla?|oklch)\(|-\[#/',
        // B3: a legacy raw colour variable (the families of `app.css`'s compatibility layer).
        'legacy colour variable' => '/var\(\s*--(?:bg|surface|border|text|accent|danger|success|warning|info)\b[\w-]*/',
        // B4: an inline colour, background, border or outline declaration, in `style` or a JavaScript `.style` write.
        'inline colour declaration' => '/style="[^"]*(?<![\w-])(?:color|background(?:-color)?|border(?:-[a-z]+)*-color|border|outline(?:-color)?|accent-color|fill|stroke|divide-color)\s*:|\.style\.(?:color|background\w*|border\w*|outline\w*|fill|stroke)\b/',
        // B6: legacy accent and brand utilities, and the dead `legacy-*` utilities.
        'legacy utility' => '/legacy-[\w-]+|(?<![\w])(?:[a-z]+-)?brand-\d{2,3}(?![\w-])/',
        // P14: the 12px card radius is the 8px convention in the target views.
        'rounded-xl card' => '/(?<![\w-])rounded-xl(?![\w-])/',
    ];
}

/** @return list<string> the rule names a source violates */
function themeNormViolations(string $source): array
{
    $found = [];

    foreach (themeNormRules() as $name => $pattern) {
        if (preg_match($pattern, $source) === 1) {
            $found[] = $name;
        }
    }

    return $found;
}

it('scans the whole WP3 scope, so a new view cannot dodge the check', function () {
    $scope = themeNormScope();

    expect(count($scope))->toBeGreaterThanOrEqual(59)
        ->and($scope)->toContain('tickets/show.blade.php', 'billing/invoices/show.blade.php', 'admin/users/index.blade.php', 'errors/403.blade.php', 'components/time-tracker.blade.php', 'components/ui/status.blade.php');
});

it('leaves no raw legacy colour, literal, palette utility, inline colour, legacy utility or 12px card in a target view', function (string $view) {
    expect(themeNormViolations(themeNormSource($view)))->toBe([], "{$view} still carries legacy presentation");
})->with(array_combine(themeNormScope(), array_map(fn (string $view) => [$view], themeNormScope())));

it('reports one violation per rule, so a mixed reintroduction names every rule it breaks', function () {
    expect(themeNormViolations('<p class="bg-gray-800 rounded-xl" style="color: var(--text-primary);">'))
        ->toEqualCanonicalizing(['palette utility', 'rounded-xl card', 'legacy colour variable', 'inline colour declaration']);
});

// ── The detectors are not vacuous: each catches its fixture and passes its near-miss ──────────────────────

dataset('theme normalization fixtures', [
    'raw text variable' => ['<span style="color: var(--text-secondary);">x</span>', 'legacy colour variable'],
    'raw surface variable' => ['<div class="p-4" style="background-color: var(--surface-card);">', 'legacy colour variable'],
    'raw border variable' => ['<tr style="border-color: var(--border-base);">', 'legacy colour variable'],
    'legacy accent variable' => ['<a style="color: var(--accent);">', 'legacy colour variable'],
    'literal hex in a style' => ['<p style="color:#dc2626">x</p>', 'literal colour'],
    'short hex' => ['<p style="color:#fff">x</p>', 'literal colour'],
    'rgba overlay' => ['<div style="background-color: rgba(0,0,0,0.5);">', 'literal colour'],
    'arbitrary hex class' => ['<p class="text-[#123456]">', 'literal colour'],
    'numeric palette utility' => ['<div class="bg-red-50">', 'palette utility'],
    'palette with a variant' => ['<td class="dark:hover:text-slate-300">', 'palette utility'],
    'named white utility' => ['<button class="text-white">', 'named white/black utility'],
    'inline background' => ['<div style="background: none;">', 'inline colour declaration'],
    'inline divide colour' => ['<tbody style="divide-color: x;">', 'inline colour declaration'],
    'javascript style write' => ['el.style.backgroundColor = "x";', 'inline colour declaration'],
    'legacy accent utility' => ['<input class="accent-legacy-accent">', 'legacy utility'],
    'dead legacy hover' => ['<tr class="hover:legacy-bg-surface">', 'legacy utility'],
    'brand utility' => ['<p class="text-brand-600">', 'legacy utility'],
    'twelve pixel card' => ['<div class="rounded-xl border">', 'rounded-xl card'],
]);

it('catches a reintroduced legacy presentation by its rule', function (string $fixture, string $rule) {
    expect(themeNormViolations($fixture))->toContain($rule);
})->with('theme normalization fixtures');

dataset('theme normalization near-misses', [
    'semantic text' => ['<p class="text-sm text-text-secondary">'],
    'semantic surface and rule' => ['<div class="bg-surface border border-rule hover:bg-surface-hover divide-rule">'],
    'semantic status tokens' => ['<span class="text-danger bg-danger-soft border-warning-glyph bg-scrim text-on-ink bg-ink">'],
    'a non-colour inline style' => ['<div style="min-width: 11.25rem; width: 50%;">'],
    'a non-colour custom property' => ['<div style="width: var(--progress);">'],
    'a ds token inside a style width' => ['<i style="width: var(--ds-control-height);">'],
    'an anchor and an entity' => ['<a href="#">&#9654; Start &#10003;</a><script>document.getElementById("#add-row");</script>'],
    'an element id that looks like a selector' => ['<div id="select-all"></div><label for="invite-email">'],
    'a viewport height' => ['<div class="min-h-[60vh]">'],
    'ordinary radius' => ['<div class="rounded-lg rounded-control rounded-full rounded-tag">'],
    'a class whose name merely contains a family word' => ['<div class="grayscale text-grayish transition-red">'],
    'the border width alone' => ['<td class="border-t border-b-2">'],
]);

it('leaves ordinary and semantic presentation alone', function (string $nearMiss) {
    expect(themeNormViolations($nearMiss))->toBe([]);
})->with('theme normalization near-misses');
