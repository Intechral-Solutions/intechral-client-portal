<?php

/*
 * EPIC-013 WP4: the three seams that stop a second presentation from requiring a page rewrite
 * (§23.2). These are static assertions over the source tree because the property is architectural —
 * "no page knows the shell exists" cannot be observed by rendering one page.
 */

function shellPageFiles(): array
{
    $files = [];
    $pages = resource_path('js/pages');

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pages));

    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.tsx')) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/** True when `source` imports the module or component `forbidden`, or renders it as JSX. */
function shellSeamReferences(string $source, string $forbidden): bool
{
    if (str_starts_with($forbidden, '@/')) {
        return (bool) preg_match('#from\s+[\'"]'.preg_quote($forbidden, '#').'[\'"]#', $source);
    }

    $name = preg_quote($forbidden, '#');

    return (bool) preg_match('#\bimport\b[^;]*\b'.$name.'\b[^;]*\bfrom\b|<'.$name.'[\s/>]#', $source);
}

it('detects a shell reference, and only a real one', function () {
    // The guard below must be able to fail: it is asserted against positive and negative samples.
    expect(shellSeamReferences("import { Breadcrumb } from '@/components/shell/breadcrumb';", 'Breadcrumb'))->toBeTrue()
        ->and(shellSeamReferences('return <Rail workspaces={x} />;', 'Rail'))->toBeTrue()
        ->and(shellSeamReferences("import { Drawer } from '@/components/shell/drawer';", '@/components/shell/drawer'))->toBeTrue()
        ->and(shellSeamReferences('<nav aria-label="Breadcrumb">', 'Breadcrumb'))->toBeFalse()
        ->and(shellSeamReferences('const guardrail = 1;', 'Rail'))->toBeFalse();
});

it('finds the page tree it is asserting over', function () {
    // A rename that empties this list would make every assertion below vacuously true.
    expect(shellPageFiles())->not->toBeEmpty();
});

it('enters the shell through AppShell alone', function () {
    $layouts = [];

    foreach (shellPageFiles() as $file) {
        $source = (string) file_get_contents($file);

        if (preg_match('/\.layout\s*=\s*\(page[^)]*\)\s*=>\s*<(\w+)>/', $source, $match)) {
            $layouts[$file] = $match[1];
        }
    }

    // Seam 2: the 12 `Page.layout` lines are the entire coupling between pages and chrome, so they
    // all name the one shell-resolution boundary.
    expect($layouts)->not->toBeEmpty()
        ->and(array_values(array_unique($layouts)))->toBe(['AppShell']);
});

it('keeps pages chrome-agnostic', function (string $forbidden) {
    foreach (shellPageFiles() as $file) {
        // Seam 3: a page composes primitives and page frames, never shell parts. Importing one would
        // couple that page to the Operational geometry and break the second presentation before it
        // is written.
        //
        // Asserted with the shellSeamReferences() regex helper rather than
        // `->not->toContain($forbidden, $message)`: Pest's `toContain` is variadic, so a second
        // argument is a second NEEDLE, not a message, and the negation then passes whenever either
        // needle is absent — i.e. always. (Found in WP5: the earlier form of this guard could not fail.)
        //
        // "Reference" means code: importing a shell module or rendering a shell component. A page may
        // still use the plain word in its own content — `projects/tasks/show.tsx` has carried its own
        // `<nav aria-label="Breadcrumb">` since before this epic, which is page content, not chrome.
        expect(shellSeamReferences((string) file_get_contents($file), $forbidden))
            ->toBeFalse("{$file} must not reference {$forbidden}");
    }
})->with([
    'Rail',
    'Drawer',
    'UtilityBar',
    'Breadcrumb',
    'ViewSwitcher',
    'NavSheet',
    'AccountMenu',
    'TimerPill',
    '@/components/shell/rail',
    '@/components/shell/drawer',
    '@/components/shell/utility-bar',
    '@/components/shell/operator-shell',
    // WP6: the pill is shell chrome mounted by the utility bar. A page reaching for it would be
    // building a second global timer affordance, which Direction D §12.1 permits exactly one of.
    '@/components/time/timer-pill',
    '@/components/time/timer-tray',
]);

it('lets no page branch on the presentation family', function () {
    foreach (shellPageFiles() as $file) {
        // Seam 1: `shell.presentation` names a presentation, never a role, and only AppShell reads
        // it. A page that branched on it would be treating geometry as authorization (L17).
        expect((string) file_get_contents($file))->not->toContain('shell.presentation');
    }
});

it('reads the presentation discriminator in exactly one component', function () {
    $readers = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js')));

    foreach ($iterator as $file) {
        if (! $file->isFile() || ! preg_match('/\.tsx?$/', $file->getFilename())) {
            continue;
        }

        if (str_contains($file->getFilename(), '.test.')) {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), 'shell.presentation')) {
            $readers[] = str_replace(resource_path('js').'/', '', $file->getPathname());
        }
    }

    expect($readers)->toBe(['components/shell/app-shell.tsx']);
});

it('no longer ships the pre-WP4 shell', function () {
    // WP4 replaces the sticky header outright rather than leaving two shells alive (§13.4).
    expect(file_exists(resource_path('js/layouts/app-layout.tsx')))->toBeFalse()
        ->and(file_exists(resource_path('js/components/navigation/navigation-link.tsx')))->toBeFalse()
        ->and(file_exists(resource_path('js/types/navigation-legacy.ts')))->toBeFalse();
});

it('no longer ships the pre-WP5 Blade shell or its compatibility projection', function () {
    // WP5 replaces the flat Blade bar with the Direction D shell partials, so the bar, the
    // authenticated footer and the flattened `navigationLegacy` projection that only the bar read are
    // all removed rather than kept "just in case" (EPIC-013 §14.4, A7.10).
    expect(file_exists(resource_path('views/layouts/partials/nav.blade.php')))->toBeFalse()
        ->and(file_exists(resource_path('views/layouts/partials/footer.blade.php')))->toBeFalse()
        ->and(class_exists('App\\Shared\\Navigation\\LegacyShellNavigation'))->toBeFalse();

    $sources = [];

    foreach (['app', 'resources/js', 'resources/views'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory)));

        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/\.(php|ts|tsx|js)$/', $file->getFilename())) {
                $contents = (string) file_get_contents($file->getPathname());

                if (str_contains($contents, 'navigationLegacy') || str_contains($contents, 'LegacyShellNavigation')) {
                    $sources[] = $file->getPathname();
                }
            }
        }
    }

    expect($sources)->toBe([]);
});

it('includes the one shared pre-paint bootstrap from both root views, before any asset', function () {
    foreach (['views/app.blade.php', 'views/layouts/app.blade.php'] as $root) {
        $view = (string) file_get_contents(resource_path($root));
        $include = strpos($view, "@include('layouts.partials.shell.bootstrap')");

        // One bootstrap for both renderers (§14.4), ahead of @vite so no stylesheet blocks it (A1.7).
        expect(substr_count($view, "@include('layouts.partials.shell.bootstrap')"))->toBe(1)
            ->and($include)->toBeLessThan((int) strpos($view, '@vite('))
            // The per-root theme scripts it replaced must not come back beside it.
            ->and(str_contains($view, 'localStorage'))->toBeFalse();
    }
});
