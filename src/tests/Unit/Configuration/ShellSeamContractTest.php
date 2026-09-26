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
        expect((string) file_get_contents($file))
            ->not->toContain($forbidden, "{$file} must not reference {$forbidden}");
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
