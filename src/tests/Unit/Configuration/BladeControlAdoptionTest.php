<?php

/*
 * EPIC-016 WP1 (PR 1) exit check: "no target field without the focus outline; no indigo action fill or
 * checkbox in the target views" (§19 WP1 exit, §20 #2, #3, #5), and the component seam's own rules (§7.2).
 *
 * This is the PR 1 control-migration check, deliberately narrow: it looks only at CONTROLS (actions,
 * links, fields, checkboxes) in the target views, and at the shape of `components/ui/*`. It is NOT the
 * permanent two-level guard of §17, which is PR 4 and also covers page-body colour. Status, priority,
 * alerts, the time tracker and page-body colour are later packages and are not asserted here.
 */

/** The views directory, resolved from this file: datasets are built before the application boots. */
function bladeViewsRoot(): string
{
    return dirname(__DIR__, 3).'/resources/views';
}

/** The target views of the four workspaces plus errors/403 (EPIC-016 §13, §17.2), repo-relative to views/. */
function bladeTargetViews(): array
{
    $root = bladeViewsRoot();
    $files = [];

    foreach (['tickets', 'operator/tickets', 'crm', 'organizations', 'billing', 'admin', 'operator/cms'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$directory}", FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }
    }

    $files[] = 'errors/403.blade.php';
    sort($files);

    return $files;
}

function bladeViewSource(string $relative): string
{
    return (string) file_get_contents(bladeViewsRoot()."/{$relative}");
}

it('scans the whole target set, so a new view cannot dodge the check', function () {
    $views = bladeTargetViews();

    expect(count($views))->toBeGreaterThanOrEqual(39)
        ->and($views)->toContain('billing/invoices/_line_item.blade.php', 'tickets/show.blade.php', 'admin/roles/edit.blade.php', 'errors/403.blade.php');
});

it('has no hand-built control left in any target view: every action, link, field and checkbox is an x-ui component', function (string $view) {
    $source = bladeViewSource($view);

    // Hidden inputs carry values, not UI; everything else must come from x-ui.*.
    $withoutHidden = preg_replace('/<input\b[^>]*\btype="hidden"[^>]*>/i', '', $source);

    expect(preg_match_all('/<(a|button|select|textarea)\b/i', $withoutHidden, $raw))->toBe(0, $view.' hand-builds '.implode(', ', $raw[1] ?? []))
        ->and(preg_match_all('/<input\b/i', $withoutHidden))->toBe(0, $view.' hand-builds an <input>');
})->with(array_combine(bladeTargetViews(), array_map(fn ($view) => [$view], bladeTargetViews())));

it('has no legacy accent fill, legacy checkbox accent or obsolete focus treatment on a control', function (string $view) {
    $source = bladeViewSource($view);

    expect($source)->not->toContain('accent-legacy-accent')
        ->and($source)->not->toContain('legacy-btn-accent')
        ->and($source)->not->toMatch('/background(?:-color)?\s*:\s*var\(--accent\)/')
        ->and($source)->not->toMatch('/\bbg-(?:brand|indigo|blue|violet)-\d/')
        ->and($source)->not->toContain('outline-none')
        ->and($source)->not->toContain('focus:ring');
})->with(array_combine(bladeTargetViews(), array_map(fn ($view) => [$view], bladeTargetViews())));

it('keeps the component seam on Direction D utilities only: no hex, no legacy variable, no inline style, no obsolete focus', function () {
    $components = glob(bladeViewsRoot().'/components/ui/*.blade.php');
    $components[] = bladeViewsRoot().'/pagination/direction-d.blade.php';
    $components[] = bladeViewsRoot().'/pagination/simple-direction-d.blade.php';

    expect(count($components))->toBeGreaterThanOrEqual(10);

    foreach ($components as $path) {
        // Strip the Blade comment header, which documents the rules by naming them.
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));
        $name = basename($path);

        expect($source)->not->toMatch('/#[0-9a-fA-F]{3,8}\b/', "{$name} carries a hex colour")
            ->and($source)->not->toContain('var(--')
            ->and($source)->not->toContain('style=')
            ->and($source)->not->toContain('outline-none')
            ->and($source)->not->toContain('focus:ring')
            ->and($source)->not->toMatch('/\b(?:bg|text|border)-(?:gray|slate|zinc|neutral|stone|red|green|blue|indigo|violet|amber|teal|white|black)\b/')
            ->and($source)->not->toContain('legacy-')
            ->and($source)->not->toContain('prefers-color-scheme')
            ->and($source)->not->toMatch('/\bdark:/')
            // Tailwind's `transition-colors` / `transition-all` include `outline-color`, so the focus ring
            // would fade in from currentColor (control-metrics.ts `focusRing`; EPIC-016 §7.2 rule 2).
            ->and($source)->not->toMatch('/\btransition-(?:colors|all)\b/');
    }
});

it('gives every interactive component the exact focus ring string', function (string $component) {
    $source = (string) file_get_contents(bladeViewsRoot()."/components/ui/{$component}.blade.php");

    expect($source)->toContain('focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus');
})->with(['button', 'link', 'input', 'select', 'textarea', 'checkbox']);

it('never lets a component read the database', function () {
    foreach (glob(bladeViewsRoot().'/components/ui/*.blade.php') as $path) {
        $source = (string) file_get_contents($path);

        expect($source)->not->toMatch('/DB::|App\\\\Models|::query\(|->query\(|Eloquent/', basename($path));
    }
});

it('reads its identity rules from the one FieldState helper and never from the request', function () {
    foreach (['input', 'select', 'textarea', 'checkbox', 'field-error'] as $component) {
        // The Blade comment header documents the contract and names `old()`; only the code is checked.
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(bladeViewsRoot()."/components/ui/{$component}.blade.php"));

        expect($source)->toContain('FieldState::')
            ->and($source)->not->toMatch('/request\(|session\(|auth\(|old\(/');
    }
});
