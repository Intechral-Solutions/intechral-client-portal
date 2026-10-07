<?php

/*
 * EPIC-016 WP4: the permanent two-level architecture guard (§17), and the alias-retirement absence pins (§16).
 *
 * It keeps the theme-first architecture (O8) from regressing, without banning `var(--…)` as a language feature
 * (O9). It is a SOURCE scan of Blade, React and `app.css` text, not a Tailwind or HTML parser:
 *   - B3 and B4 see text, not intent. A colour reaching a view by an indirect route is left to review and to the
 *     browser palette-conformance check (`blade-theme-controls.spec.ts`), which catches it by computed VALUE.
 *   - B7 is a deny-list of clearly visual caller overrides on `<x-ui.*>`. The rule "x-ui.* owns visual styling;
 *     callers add layout classes only" (§7.2 rule 1) remains a contract enforced by review. Blade cannot merge
 *     conflicting Tailwind classes the way `tailwind-merge` does, and this guard does not claim to understand
 *     every Tailwind class.
 *
 * LEVEL A  all views (except `welcome`), React source (without tests), and `app.css`.
 * LEVEL B  the explicit target inventory below: the four workspaces, the shared components, `errors/403`.
 *
 * It supersedes EPIC-016 WP3's `BladeThemeNormalizationTest` (a migration-completion check). That file's rules and
 * all of its fixtures live here now, plus the permanent rules it did not carry (B5, B7, A1-A5), and its known
 * gaps are closed: single-quoted and bound `style`, `white` / `black` with every colour-bearing prefix, and an
 * EXACT target membership instead of `>= 59`.
 *
 * Each rule has fixtures it MUST catch and near-misses it must NOT, so no rule can be vacuous (EPIC-013 A13.13).
 */

// ── Scope ────────────────────────────────────────────────────────────────────────────────────────────────

function guardRoot(): string
{
    return dirname(__DIR__, 3);
}

/**
 * The Level B target inventory: EXACT, deliberate membership (§17.2). A view added to, renamed in or removed from
 * a target path changes this list in a reviewed commit; `guardDiscoverTargets()` must equal it.
 *
 * @return list<string> paths relative to resources/views
 */
function guardTargetInventory(): array
{
    return [
        'admin/roles/create.blade.php',
        'admin/roles/edit.blade.php',
        'admin/roles/index.blade.php',
        'admin/users/index.blade.php',
        'admin/users/show.blade.php',
        'billing/_invoice_status.blade.php',
        'billing/client/index.blade.php',
        'billing/client/show.blade.php',
        'billing/invoices/_form.blade.php',
        'billing/invoices/_line_item.blade.php',
        'billing/invoices/create.blade.php',
        'billing/invoices/edit.blade.php',
        'billing/invoices/index.blade.php',
        'billing/invoices/show.blade.php',
        'billing/payment/show.blade.php',
        'components/time-tracker.blade.php',
        'components/time-tracker/running.blade.php',
        'components/ui/alert.blade.php',
        'components/ui/button.blade.php',
        'components/ui/checkbox.blade.php',
        'components/ui/field-error.blade.php',
        'components/ui/input.blade.php',
        'components/ui/label.blade.php',
        'components/ui/link.blade.php',
        'components/ui/priority.blade.php',
        'components/ui/select.blade.php',
        'components/ui/status.blade.php',
        'components/ui/tag.blade.php',
        'components/ui/textarea.blade.php',
        'crm/companies/_form.blade.php',
        'crm/companies/create.blade.php',
        'crm/companies/edit.blade.php',
        'crm/companies/index.blade.php',
        'crm/companies/show.blade.php',
        'crm/contacts/_form.blade.php',
        'crm/contacts/create.blade.php',
        'crm/contacts/edit.blade.php',
        'crm/contacts/index.blade.php',
        'crm/contacts/show.blade.php',
        'errors/403.blade.php',
        'operator/cms/_form.blade.php',
        'operator/cms/_state.blade.php',
        'operator/cms/create.blade.php',
        'operator/cms/edit.blade.php',
        'operator/cms/index.blade.php',
        'operator/tickets/index.blade.php',
        'operator/tickets/reports.blade.php',
        'operator/tickets/show.blade.php',
        'organizations/index.blade.php',
        'organizations/show.blade.php',
        'pagination/direction-d.blade.php',
        'pagination/simple-direction-d.blade.php',
        'tickets/_internal_note_label.blade.php',
        'tickets/_overdue_status.blade.php',
        'tickets/_priority_badge.blade.php',
        'tickets/_status_badge.blade.php',
        'tickets/create.blade.php',
        'tickets/index.blade.php',
        'tickets/show.blade.php',
    ];
}

/** The §17.2 path roots: directories (recursive) and single files, relative to resources/views. */
function guardTargetRoots(): array
{
    return [
        'directories' => ['tickets', 'operator/tickets', 'crm', 'organizations', 'billing', 'admin', 'operator/cms', 'components/ui', 'components/time-tracker', 'pagination'],
        'files' => ['components/time-tracker.blade.php', 'errors/403.blade.php'],
    ];
}

/** @return list<string> every `*.blade.php` under a directory, relative to `$base`, sorted */
function guardBladeFiles(string $base, string $directory = ''): array
{
    $path = rtrim($base.'/'.$directory, '/');
    $found = [];

    if (! is_dir($path)) {
        return $found;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            $found[] = ltrim(str_replace($base, '', $file->getPathname()), '/');
        }
    }

    sort($found);

    return $found;
}

/** @return list<string> what the §17.2 roots contain today, relative to resources/views */
function guardDiscoverTargets(): array
{
    $views = guardRoot().'/resources/views';
    $found = [];

    foreach (guardTargetRoots()['directories'] as $directory) {
        $found = array_merge($found, guardBladeFiles($views, $directory));
    }

    $found = array_values(array_unique(array_merge($found, guardTargetRoots()['files'])));
    sort($found);

    return $found;
}

/** @return list<string> every view except `welcome` (Level A), relative to resources/views */
function guardAllViews(): array
{
    return array_values(array_filter(guardBladeFiles(guardRoot().'/resources/views'), fn (string $view) => $view !== 'welcome.blade.php'));
}

/** @return list<string> React application source without test files (Level A), relative to resources/js */
function guardReactSource(): array
{
    $base = guardRoot().'/resources/js';
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
        $name = $file->getFilename();

        if (preg_match('/\.(?:tsx?|jsx?)$/', $name) === 1 && preg_match('/\.test\.[a-z]+$/', $name) !== 1) {
            $found[] = ltrim(str_replace($base, '', $file->getPathname()), '/');
        }
    }

    sort($found);

    return $found;
}

/**
 * Reviewed exceptions: `path => [rule => reason]`. Expected to be EMPTY (§11.3 C, §17.3): no documented exception
 * was needed. An entry needs a written reason, appears in the PR diff, and fails the guard once it stops matching.
 */
function guardAllowlist(): array
{
    return [];
}

// ── Source preparation ───────────────────────────────────────────────────────────────────────────────────

/** Blank a comment's text but keep its newlines, so reported line numbers stay true. */
function guardBlank(string $source, string $pattern): string
{
    return (string) preg_replace_callback($pattern, fn (array $m) => str_repeat("\n", substr_count($m[0], "\n")), $source);
}

/** Blank `/* … *\/` comments but never inside a quoted string (`@source '../**\/*.blade.php'` holds the same characters). */
function guardBlankBlockComments(string $source): string
{
    return (string) preg_replace_callback(
        '~("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')|/\*.*?\*/~s',
        fn (array $m) => ($m[1] ?? '') !== '' ? $m[1] : str_repeat("\n", substr_count($m[0], "\n")),
        $source,
    );
}

/** Comments document the retired values by naming them; only code is checked. */
function guardPrepare(string $source, string $kind): string
{
    return match ($kind) {
        'blade' => guardBlank($source, '/\{\{--.*?--\}\}/s'),
        'css', 'js' => guardBlankBlockComments($source),
        default => $source,
    };
}

function guardLine(string $source, int $offset): int
{
    return substr_count(substr($source, 0, $offset), "\n") + 1;
}

// ── Rules ────────────────────────────────────────────────────────────────────────────────────────────────

const GUARD_FAMILIES = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
const GUARD_COLOUR_PREFIXES = 'bg|text|border|ring|outline|divide|accent|fill|stroke|from|via|to|decoration|placeholder|caret|shadow';
const GUARD_RETIRED_VARIABLES = 'accent|accent-hover|accent-text|surface-accent|surface-input|surface-base|surface-elevated|surface-muted|border-muted|border-subtle|color-legacy-accent';
const GUARD_INLINE_COLOUR_PROPERTIES = 'color|background(?:-color)?|border(?:-[a-z]+)*-color|border(?:-(?:top|right|bottom|left|inline|block)(?:-(?:start|end))?)?|outline(?:-color)?|accent-color|caret-color|fill|stroke|divide-color|text-decoration-color';

/**
 * Pattern rules, by id. A hit anywhere in the prepared source is a violation.
 *
 * @return array<string, string> id => regex
 */
function guardPatterns(): array
{
    $families = GUARD_FAMILIES;
    $prefixes = GUARD_COLOUR_PREFIXES;
    $sides = '(?:[trblxyse]{1,2}-)?';

    return [
        // A1: an alias EPIC-016 retired, in any syntax (declaration, `var()`, `theme()` name, Tailwind variable).
        'A1' => '/(?<![\w-])--(?:'.GUARD_RETIRED_VARIABLES.')(?![\w-])|(?<![\w-])--color-brand-\d{2,3}(?![\w-])/',
        // A2: the legacy accent utility and the retired brand scale, as Tailwind classes.
        'A2' => '/legacy-accent|legacy-btn-accent|(?<![\w])(?:[a-z]+-)?brand-\d{2,3}(?![\w-])/',
        // A3: any dead or retired `legacy-*` utility. `legacy-text-primary` is the one that lives on (three React pages use it).
        'A3' => '/legacy-(?!text-primary(?![\w-]))[\w-]+/',
        // A5 (React only): numeric blue-family palette utilities. Direction D §2.1: components consume semantic tokens.
        'A5' => '/(?<![\w-])(?:[\w\[\]:.&>*-]+:)?(?:'.$prefixes.')-'.$sides.'(?:blue|indigo|violet|sky|purple)-\d{2,3}(?![\w-])/',
        // B1: numeric palette colour utilities of any family, any colour-bearing prefix, any variant; and named white / black.
        'B1' => '/(?<![\w-])(?:[\w\[\]:.&>*-]+:)?(?:'.$prefixes.')-'.$sides.'(?:(?:'.$families.')-\d{2,3}|white|black)(?![\w-])/',
        // B2: literal colour values anywhere they could set presentation: hex, rgb(a), hsl(a), oklch, oklab, color-mix, arbitrary class values.
        'B2' => '/(?<![&\w])#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})(?![\w-])|(?<![\w-])(?:rgba?|hsla?|oklch|oklab|color-mix)\(/',
        // B3: a legacy raw colour variable. Other custom properties (`--ds-*`, `--progress`, `--tw-*`) are NOT forbidden by this rule.
        'B3' => '/var\(\s*--(?:bg|surface|border|text|accent|danger|success|warning|info)(?![\w])[\w-]*/',
        // B5: the obsolete focus treatment (the components own `focusRing`).
        'B5' => '/(?<![\w-])(?:[\w:-]+:)?outline-(?:none|hidden)(?![\w-])|(?<![\w-])focus(?:-visible|-within)?:ring(?:-[\w\[\]\/.%-]+)?(?![\w-])/',
        // B6: retired and dead utilities, every `legacy-*` and the brand scale, in the target views.
        'B6' => '/legacy-[\w-]+|(?<![\w])(?:[a-z]+-)?brand-\d{2,3}(?![\w-])/',
        // P14 (card radius, §12.2): the 12px `rounded-xl` card is the 8px `rounded-lg` convention in the target views.
        'G1' => '/(?<![\w-])rounded-xl(?![\w-])/',
    ];
}

/**
 * B4: an inline colour, background, border or outline declaration, in any `style` attribute (double or single quoted,
 * `:style`, Alpine), a PHP style string, or a JavaScript `.style` write. A `style` that is nothing but an expression is
 * opaque to a source scan, so it is reported too (allowlist it, with a reason, if it is a genuine runtime value).
 *
 * @return list<array{0: int, 1: string}> [offset, match]
 */
function guardInlineStyleHits(string $source): array
{
    $hits = [];
    $property = '/(?<![\w-])(?:'.GUARD_INLINE_COLOUR_PROPERTIES.')\s*:/i';

    if (preg_match_all('/(?<![\w-])style\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/s', $source, $attributes, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
        foreach ($attributes as $attribute) {
            $value = $attribute[1][1] >= 0 ? $attribute[1][0] : ($attribute[2][0] ?? '');
            $static = trim((string) preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', '', $value), " \t\n\r;");

            if (preg_match($property, $static, $match) === 1) {
                $hits[] = [$attribute[0][1], trim($match[0])];
            } elseif ($static === '' && trim($value) !== '') {
                $hits[] = [$attribute[0][1], 'style="'.trim($value).'" (opaque)'];
            }
        }
    }

    // A style written as a quoted PHP / JS string (`'color: red;'`), outside a `style` attribute.
    if (preg_match_all('/["\'](?:color|background(?:-color)?|border(?:-[a-z]+)*-color|outline-color)\s*:\s*[^"\'<>]*;/', $source, $strings, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
        foreach ($strings as $string) {
            $hits[] = [$string[0][1], $string[0][0]];
        }
    }

    // A JavaScript write to a colour property.
    $writes = '/\.style\.(?:color|background\w*|border\w*|outline\w*|fill|stroke|accentColor|caretColor|cssText)\b|\.style\.setProperty\(\s*["\'](?:color|background[\w-]*|border[\w-]*|outline[\w-]*|fill|stroke|accent-color)["\']/';

    if (preg_match_all($writes, $source, $js, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
        foreach ($js as $write) {
            $hits[] = [$write[0][1], $write[0][0]];
        }
    }

    return $hits;
}

/** Variants that make a class state- or theme-dependent; the components own those. Responsive variants are layout. */
function guardForbiddenVariant(string $variant): bool
{
    return preg_match('/^(?:hover|focus|focus-visible|focus-within|active|disabled|dark|visited|checked|invalid|placeholder|file|before|after|selection|group-[\w-]+|peer-[\w-]+|aria-[\w\[\]=-]+|data-[\w\[\]=-]+)$/', $variant) === 1;
}

/** A class token that is a clearly visual override of a component's own styling (B7). */
function guardVisualToken(string $token): bool
{
    $parts = explode(':', $token);
    $base = ltrim((string) array_pop($parts), '!-');
    $base = (string) preg_replace('~/[\w.%\[\]]+$~', '', $base);

    foreach ($parts as $variant) {
        if (guardForbiddenVariant($variant)) {
            return true;
        }
    }

    if (preg_match('/^(?:bg|border|ring|outline|shadow|rounded|divide|accent|fill|stroke|opacity)(?:-|$)/', $base) === 1) {
        return true;
    }

    // `text-*` is visual (a colour) unless it is a size, an alignment or a wrapping keyword.
    if (str_starts_with($base, 'text-') && preg_match('/^text-(?:xs|sm|base|lg|[2-9]xl|left|center|right|justify|start|end|wrap|nowrap|balance|pretty|ellipsis|clip)$/', $base) !== 1) {
        return true;
    }

    // Geometry the component owns: height and padding.
    return preg_match('/^(?:h|min-h|size|p|px|py|pt|pr|pb|pl|ps|pe)-/', $base) === 1;
}

/**
 * B7: clearly visual overrides on `<x-ui.*>` invocations. Walks each tag respecting quotes and Blade echoes, so an
 * attribute holding `->` or `>` does not end the tag early.
 *
 * @return list<array{0: int, 1: string}> [offset, match]
 */
function guardXUiOverrideHits(string $source): array
{
    $hits = [];
    $offset = 0;
    $length = strlen($source);

    while (preg_match('/<x-ui\.[\w.-]+/', $source, $found, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $start = $found[0][1];
        $i = $start + strlen($found[0][0]);
        $quote = null;

        for (; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;

                continue;
            }

            if ($char === '{' && ($source[$i + 1] ?? '') === '{') {
                $end = strpos($source, '}}', $i);
                $i = $end === false ? $length : $end + 1;

                continue;
            }

            if ($char === '{' && substr($source, $i, 3) === '{!!') {
                $end = strpos($source, '!!}', $i);
                $i = $end === false ? $length : $end + 2;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '>') {
                break;
            }
        }

        $tag = substr($source, $start, $i - $start + 1);
        $name = $found[0][0];

        if (preg_match('/(?<![\w-])(?:x-bind|wire):class\s*=|(?<![\w-]):class\s*=/', $tag) === 1) {
            $hits[] = [$start, "{$name} :class (a bound expression cannot be checked; use a component prop)"];
        }

        if (preg_match('/(?<![\w-]):?style\s*=/', $tag) === 1) {
            $hits[] = [$start, "{$name} style="];
        }

        if (preg_match_all('/(?<![\w:-])class\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/s', $tag, $classes, PREG_SET_ORDER) > 0) {
            foreach ($classes as $class) {
                $value = (string) preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', ' ', $class[1] !== '' ? $class[1] : ($class[2] ?? ''));

                foreach (preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                    if (guardVisualToken($token)) {
                        $hits[] = [$start, "{$name} class \"{$token}\""];
                    }
                }
            }
        }

        $offset = $start + 1;
    }

    return $hits;
}

/**
 * Scan prepared source with a set of rule ids.
 *
 * @param  list<string>  $rules
 * @return list<array{rule: string, line: int, match: string}>
 */
function guardScan(string $source, array $rules): array
{
    $violations = [];
    $patterns = guardPatterns();

    foreach ($rules as $rule) {
        if ($rule === 'B4') {
            foreach (guardInlineStyleHits($source) as [$offset, $match]) {
                $violations[] = ['rule' => 'B4', 'line' => guardLine($source, $offset), 'match' => $match];
            }

            continue;
        }

        if ($rule === 'B7') {
            foreach (guardXUiOverrideHits($source) as [$offset, $match]) {
                $violations[] = ['rule' => 'B7', 'line' => guardLine($source, $offset), 'match' => $match];
            }

            continue;
        }

        if (preg_match_all($patterns[$rule], $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$text, $offset]) {
                $violations[] = ['rule' => $rule, 'line' => guardLine($source, $offset), 'match' => $text];
            }
        }
    }

    return $violations;
}

/** @return list<string> the distinct rule ids a fixture breaks */
function guardRulesBroken(string $source, array $rules): array
{
    return array_values(array_unique(array_map(fn (array $violation) => $violation['rule'], guardScan($source, $rules))));
}

/** Apply the reviewed allowlist, report `rule file:line => match` lines, and note stale entries. */
function guardReport(string $file, array $violations): array
{
    $allowed = guardAllowlist()[$file] ?? [];

    return array_values(array_map(
        fn (array $v) => "{$v['rule']} {$file}:{$v['line']} => {$v['match']}",
        array_filter($violations, fn (array $v) => ! array_key_exists($v['rule'], $allowed)),
    ));
}

const GUARD_LEVEL_A_RULES = ['A1', 'A2', 'A3'];
const GUARD_LEVEL_B_RULES = ['B1', 'B2', 'B3', 'B4', 'B5', 'B6', 'B7', 'G1'];

// ── Scope contracts ───────────────────────────────────────────────────────────────────────────────────────

it('keeps the Level B target inventory exact, and says what to do when a view is added, renamed or removed', function () {
    $inventory = guardTargetInventory();
    $discovered = guardDiscoverTargets();

    $unclassified = array_values(array_diff($discovered, $inventory));
    $missing = array_values(array_diff($inventory, $discovered));

    $message = '';
    if ($unclassified !== []) {
        $message .= "\nA view now sits under a Level B target path and is not in guardTargetInventory(): ".implode(', ', $unclassified)
            .".\nA new view in a target workspace is classified by adding it to the inventory in BladeThemeGuardTest (a reviewed change); it is then held to every Level B rule.";
    }
    if ($missing !== []) {
        $message .= "\nguardTargetInventory() lists a view that no longer exists (renamed or deleted): ".implode(', ', $missing)
            .".\nUpdate the inventory in the same commit.";
    }

    expect($message)->toBe('')
        ->and($inventory)->toBe($discovered)
        ->and(count($inventory))->toBe(59);
});

it('draws the inventory only from the §17.2 roots, and lists every file once', function () {
    $inventory = guardTargetInventory();
    $roots = guardTargetRoots();

    expect($inventory)->toBe(array_values(array_unique($inventory)));

    foreach ($inventory as $path) {
        $inRoot = in_array($path, $roots['files'], true)
            || array_filter($roots['directories'], fn (string $directory) => str_starts_with($path, $directory.'/')) !== [];

        expect($inRoot)->toBeTrue("{$path} is outside the §17.2 target roots");
    }
});

it('covers the shared components, the pagination views, the time tracker and errors/403 as consumers', function () {
    expect(guardTargetInventory())->toContain(
        'components/ui/button.blade.php',
        'components/ui/status.blade.php',
        'pagination/direction-d.blade.php',
        'pagination/simple-direction-d.blade.php',
        'components/time-tracker.blade.php',
        'components/time-tracker/running.blade.php',
        'errors/403.blade.php',
    );
});

it('scans a meaningful Level A scope: all views except welcome, and React source without tests', function () {
    $views = guardAllViews();
    $react = guardReactSource();

    expect($views)->toContain('layouts/app.blade.php', 'cms/index.blade.php', 'tickets/show.blade.php')
        ->not->toContain('welcome.blade.php')
        ->and(count($react))->toBeGreaterThan(100)
        ->and(array_filter($react, fn (string $file) => str_contains($file, '.test.')))->toBe([]);
});

// ── The guard, applied to the real tree ──────────────────────────────────────────────────────────────────

it('Level A: no retired alias, legacy accent or brand utility, or dead legacy utility in any view', function () {
    $violations = [];

    foreach (guardAllViews() as $view) {
        $source = guardPrepare((string) file_get_contents(guardRoot()."/resources/views/{$view}"), 'blade');
        array_push($violations, ...guardReport("resources/views/{$view}", guardScan($source, GUARD_LEVEL_A_RULES)));
    }

    expect(implode("\n", $violations))->toBe('');
});

it('Level A: no retired alias, legacy accent or brand utility, dead legacy utility or blue-family palette in React source', function () {
    $violations = [];

    foreach (guardReactSource() as $file) {
        $source = guardPrepare((string) file_get_contents(guardRoot()."/resources/js/{$file}"), 'js');
        array_push($violations, ...guardReport("resources/js/{$file}", guardScan($source, [...GUARD_LEVEL_A_RULES, 'A5'])));
    }

    expect(implode("\n", $violations))->toBe('');
});

it('Level B: the target views carry no palette, literal colour, legacy variable, inline colour, obsolete focus, retired utility, x-ui visual override or 12px card', function () {
    $violations = [];

    foreach (guardTargetInventory() as $view) {
        $source = guardPrepare((string) file_get_contents(guardRoot()."/resources/views/{$view}"), 'blade');
        array_push($violations, ...guardReport("resources/views/{$view}", guardScan($source, GUARD_LEVEL_B_RULES)));
    }

    expect(implode("\n", $violations))->toBe('');
});

it('keeps every allowlist entry reasoned and still needed', function () {
    foreach (guardAllowlist() as $file => $rules) {
        $source = guardPrepare((string) file_get_contents(guardRoot()."/{$file}"), str_ends_with($file, '.php') ? 'blade' : 'js');
        $broken = guardRulesBroken($source, [...GUARD_LEVEL_A_RULES, ...GUARD_LEVEL_B_RULES]);

        foreach ($rules as $rule => $reason) {
            expect(trim($reason))->not->toBe('', "{$file} {$rule} needs a written reason")
                ->and(in_array($rule, $broken, true))->toBeTrue("{$file} {$rule} is allowlisted but no longer matches; remove the entry");
        }
    }

    expect(true)->toBeTrue();
});

// ── Alias retirement (§16, A4): `app.css` defines none of them, and the vendor pagination source is gone ────

it('A4: app.css defines none of the retired aliases, utilities or brand scale', function () {
    $css = guardPrepare((string) file_get_contents(guardRoot().'/resources/css/app.css'), 'css');
    $violations = guardScan($css, ['A1', 'A2', 'A3']);

    expect(implode("\n", guardReport('resources/css/app.css', $violations)))->toBe('');
});

it('A4: the vendor pagination @source line is gone, since every paginator renders through the Direction D views', function () {
    $css = guardPrepare((string) file_get_contents(guardRoot().'/resources/css/app.css'), 'css');

    expect($css)->not->toMatch('~@source\s+[\'"][^\'"]*Pagination/resources/views~')
        // The sources that still matter are untouched.
        ->and($css)->toContain("@source '../**/*.blade.php';")
        ->and($css)->toContain("@source '../../storage/framework/views/*.php';");
});

it('keeps the paginator on the Direction D views, so no view needs the vendor Tailwind classes', function () {
    $provider = (string) file_get_contents(guardRoot().'/app/Providers/AppServiceProvider.php');

    expect($provider)->toContain("Paginator::defaultView('pagination.direction-d')")
        ->and($provider)->toContain("Paginator::defaultSimpleView('pagination.simple-direction-d')");

    foreach (guardAllViews() as $view) {
        expect((string) file_get_contents(guardRoot()."/resources/views/{$view}"))->not->toMatch('/links\(\s*[\'"]pagination::/', $view);
    }
});

it('keeps the one legacy utility that still has consumers, and only while it has them', function () {
    $css = guardPrepare((string) file_get_contents(guardRoot().'/resources/css/app.css'), 'css');
    $consumers = array_filter(guardReactSource(), fn (string $file) => str_contains((string) file_get_contents(guardRoot()."/resources/js/{$file}"), 'legacy-text-primary'));

    // React still uses it (auth/login, auth/forgot-password, time/index), and React is out of EPIC-016's scope.
    expect($consumers)->not->toBe([], 'legacy-text-primary has no consumer left: retire it with this guard rule')
        ->and($css)->toContain('.legacy-text-primary');
});

// ── Non-vacuity: every rule catches its fixture and passes its near-miss ─────────────────────────────────

dataset('guard must-catch fixtures', [
    // A1: retired aliases, any syntax
    'A1 var(--accent)' => ['<a style="color: var(--accent);">', ['A1'], 'blade'],
    'A1 accent hover' => ['color: var(--accent-hover)', ['A1'], 'js'],
    'A1 accent text' => ['--accent-text: #fff;', ['A1'], 'css'],
    'A1 surface-accent' => ['background: var(--surface-accent)', ['A1'], 'js'],
    'A1 surface-input declaration' => ['--surface-input: theme(colors.gray.50);', ['A1'], 'css'],
    'A1 border-subtle' => ['border-color: var(--border-subtle)', ['A1'], 'js'],
    'A1 surface-elevated' => ['var(--surface-elevated)', ['A1'], 'js'],
    'A1 brand scale variable' => ['--color-brand-500: #6366f1;', ['A1'], 'css'],
    'A1 legacy accent exposure' => ['--color-legacy-accent: var(--accent);', ['A1'], 'css'],
    // A2: legacy accent and brand utilities
    'A2 legacy checkbox accent' => ['<input class="accent-legacy-accent">', ['A2'], 'blade'],
    'A2 legacy button' => ['<a class="legacy-btn-accent">', ['A2'], 'blade'],
    'A2 brand utility' => ['<p class="text-brand-600">', ['A2'], 'blade'],
    'A2 brand with a variant' => ['<p class="hover:bg-brand-50">', ['A2'], 'js'],
    // A3: dead and retired legacy utilities
    'A3 dead hover' => ['<tr class="hover:legacy-bg-surface">', ['A3'], 'blade'],
    'A3 retired border utility' => ['<div className="legacy-border-base">', ['A3'], 'js'],
    'A3 retired shadow utility' => ['legacy-shadow-theme-md', ['A3'], 'js'],
    // A5: blue-family palette in React
    'A5 blue' => ['<div className="bg-blue-500">', ['A5'], 'js'],
    'A5 indigo with a variant' => ['className="hover:text-indigo-600"', ['A5'], 'js'],
    'A5 sky border side' => ['className="border-t-sky-300"', ['A5'], 'js'],
    // B1: numeric palette and named white / black
    'B1 numeric palette' => ['<div class="bg-red-50">', ['B1'], 'blade'],
    'B1 palette with variants' => ['<td class="dark:hover:text-slate-300">', ['B1'], 'blade'],
    'B1 palette with a side' => ['<div class="border-t-gray-200">', ['B1'], 'blade'],
    'B1 palette with opacity' => ['<div class="bg-red-500/50">', ['B1'], 'blade'],
    'B1 named white text' => ['<button class="text-white">', ['B1'], 'blade'],
    'B1 white outline' => ['<a class="outline-white">', ['B1'], 'blade'],
    'B1 white accent' => ['<input class="accent-white">', ['B1'], 'blade'],
    'B1 black shadow' => ['<div class="shadow-black/50">', ['B1'], 'blade'],
    // B2: literal colours
    'B2 hex in a style' => ['<p style="color:#dc2626">x</p>', ['B2', 'B4'], 'blade'],
    'B2 short hex' => ['<p style="color:#fff">x</p>', ['B2', 'B4'], 'blade'],
    'B2 rgba overlay' => ['<div style="background-color: rgba(0,0,0,0.5);">', ['B2', 'B4'], 'blade'],
    'B2 arbitrary hex class' => ['<p class="text-[#123456]">', ['B2'], 'blade'],
    'B2 oklch' => ['<p class="text-[oklch(0.5_0.1_200)]">', ['B2'], 'blade'],
    'B2 hsl' => ['const c = "hsl(200 50% 50%)";', ['B2'], 'blade'],
    'B2 color-mix' => ['background: color-mix(in srgb, red, blue)', ['B2'], 'blade'],
    // B3: legacy raw colour variables
    'B3 raw text variable' => ['<span style="color: var(--text-secondary);">x</span>', ['B3', 'B4'], 'blade'],
    'B3 raw surface variable' => ['<div class="p-4" style="background-color: var(--surface-card);">', ['B3', 'B4'], 'blade'],
    'B3 raw border variable' => ['<tr style="border-color: var(--border-base);">', ['B3', 'B4'], 'blade'],
    'B3 raw danger variable' => ['<p style="color: var(--text-danger)">', ['B3', 'B4'], 'blade'],
    'B3 bg variable in a script' => ['el.setAttribute("x", "var(--bg-base)")', ['B3'], 'blade'],
    // B4: inline colour, in every quoting
    'B4 double-quoted background' => ['<div style="background: none;">', ['B4'], 'blade'],
    'B4 single-quoted style (the WP3 gap)' => ["<div style='background-color: x'>", ['B4'], 'blade'],
    'B4 border shorthand' => ['<div style="border: 1px solid x">', ['B4'], 'blade'],
    'B4 border colour' => ['<div style="border-top-color: x">', ['B4'], 'blade'],
    'B4 outline' => ['<div style="outline: 2px solid x">', ['B4'], 'blade'],
    'B4 divide colour' => ['<tbody style="divide-color: x;">', ['B4'], 'blade'],
    'B4 fill' => ['<svg style="fill: x">', ['B4'], 'blade'],
    'B4 multi-line style' => ["<div style=\"\n    width: 1px;\n    color: x;\n\">", ['B4'], 'blade'],
    'B4 bound style' => ['<div :style="\'color: x\'">', ['B4'], 'blade'],
    'B4 opaque style expression' => ['<span style="{{ $style }}">', ['B4'], 'blade'],
    'B4 php style string' => ["\$s = 'background-color: red;';", ['B4'], 'blade'],
    'B4 javascript write' => ['el.style.backgroundColor = "x";', ['B4'], 'blade'],
    'B4 javascript setProperty' => ["el.style.setProperty('color', x);", ['B4'], 'blade'],
    // B5: obsolete focus treatment
    'B5 outline-none' => ['<input class="outline-none">', ['B5'], 'blade'],
    'B5 focus outline-none' => ['<input class="focus:outline-none">', ['B5'], 'blade'],
    'B5 outline-hidden' => ['<input class="outline-hidden">', ['B5'], 'blade'],
    'B5 focus ring' => ['<input class="focus:ring-2">', ['B5'], 'blade'],
    'B5 focus-visible ring' => ['<input class="focus-visible:ring-1">', ['B5'], 'blade'],
    // B6: retired and dead utilities, in the target views
    'B6 legacy primary text' => ['<a class="legacy-text-primary">', ['B6'], 'blade'],
    'B6 dead hover' => ['<tr class="hover:legacy-bg-surface">', ['A3', 'B6'], 'blade'],
    'B6 brand utility' => ['<p class="text-brand-600">', ['A2', 'B6'], 'blade'],
    // B7: visual overrides on x-ui components
    'B7 background' => ['<x-ui.button class="bg-red-500">x</x-ui.button>', ['B1', 'B7'], 'blade'],
    'B7 semantic background' => ['<x-ui.button class="bg-surface">x</x-ui.button>', ['B7'], 'blade'],
    'B7 text colour' => ['<x-ui.link class="text-danger">x</x-ui.link>', ['B7'], 'blade'],
    'B7 border' => ['<x-ui.input class="w-full border-danger" />', ['B7'], 'blade'],
    'B7 radius' => ['<x-ui.input class="w-full rounded-lg" />', ['B7'], 'blade'],
    'B7 state variant' => ['<x-ui.button class="hover:underline">x</x-ui.button>', ['B7'], 'blade'],
    'B7 focus ring' => ['<x-ui.button class="focus-visible:ring-2">x</x-ui.button>', ['B7', 'B5'], 'blade'],
    'B7 shadow' => ['<x-ui.status class="shadow-md" />', ['B7'], 'blade'],
    'B7 owned height' => ['<x-ui.input class="w-full h-12" />', ['B7'], 'blade'],
    'B7 owned padding' => ['<x-ui.button class="px-6">x</x-ui.button>', ['B7'], 'blade'],
    'B7 dark variant' => ['<x-ui.link class="dark:text-text">x</x-ui.link>', ['B7'], 'blade'],
    'B7 bound class' => ['<x-ui.button :class="$classes">x</x-ui.button>', ['B7'], 'blade'],
    'B7 style attribute' => ['<x-ui.button style="width: 4rem">x</x-ui.button>', ['B7'], 'blade'],
    'B7 multi-line invocation' => ["<x-ui.button\n    type=\"submit\"\n    class=\"w-full bg-ink\">x</x-ui.button>", ['B7'], 'blade'],
    'B7 after an attribute holding an arrow' => ['<x-ui.button :disabled="$a->b" class="text-danger">x</x-ui.button>', ['B7'], 'blade'],
    // G1: the 12px card
    'G1 twelve pixel card' => ['<div class="rounded-xl border">', ['G1'], 'blade'],
]);

it('catches a reintroduced forbidden construct by its rule', function (string $fixture, array $expected, string $kind) {
    $rules = [...GUARD_LEVEL_A_RULES, 'A5', ...GUARD_LEVEL_B_RULES];
    $broken = guardRulesBroken(guardPrepare($fixture, $kind), $rules);

    foreach ($expected as $rule) {
        expect(in_array($rule, $broken, true))->toBeTrue("fixture did not trip {$rule}: {$fixture}");
    }
})->with('guard must-catch fixtures');

dataset('guard near-misses', [
    // A1
    'A1 an accent variable that is not retired' => ['<p style="width: var(--accent-success)">', 'A1'],
    'A1 shadcn accent foreground' => ['--color-accent-foreground: var(--accent-foreground);', 'A1'],
    'A1 Direction D accent' => ['--ds-accent: #0B6A73; --color-accent: var(--ds-accent);', 'A1'],
    'A1 a surviving status surface' => ['var(--surface-success) var(--border-base) var(--surface-card)', 'A1'],
    'A1 a name that merely contains a retired one' => ['--my-accent-colour: red; --color-accent-hover: x;', 'A1'],
    // A2
    'A2 the brand mark logo class' => ['<svg class="brand-mark">', 'A2'],
    'A2 semantic accent utilities' => ['<a class="text-accent accent-accent hover:text-accent-hover">', 'A2'],
    // A3
    'A3 the surviving legacy utility' => ['<a className="legacy-text-primary hover:underline">', 'A3'],
    // A5
    'A5 shadcn primary and a non-blue palette' => ['<div className="bg-primary text-accent text-red-500">', 'A5'],
    'A5 a class that merely contains the word' => ['<div className="text-blueprint bg-skyline">', 'A5'],
    // B1
    'B1 semantic text' => ['<p class="text-sm text-text-secondary">', 'B1'],
    'B1 semantic surface and rule' => ['<div class="bg-surface border border-rule hover:bg-surface-hover divide-rule">', 'B1'],
    'B1 semantic status tokens' => ['<span class="text-danger bg-danger-soft border-warning-glyph bg-scrim text-on-ink bg-ink">', 'B1'],
    'B1 a class whose name merely contains a family word' => ['<div class="grayscale text-grayish transition-red text-white-ish">', 'B1'],
    'B1 the border width alone' => ['<td class="border-t border-b-2 shadow-md ring-2">', 'B1'],
    // B2
    'B2 a viewport height' => ['<div class="min-h-[60vh]">', 'B2'],
    'B2 an anchor and an entity' => ['<a href="#">&#9654; Start &#10003;</a><script>document.getElementById("#add-row");</script>', 'B2'],
    'B2 an in-page anchor' => ['<a href="#main-content">Skip</a>', 'B2'],
    'B2 an element id' => ['<div id="select-all"></div><label for="invite-email">', 'B2'],
    // B3
    'B3 a non-colour custom property' => ['<div style="width: var(--progress);">', 'B3'],
    'B3 a ds token inside a style width' => ['<i style="width: var(--ds-control-height);">', 'B3'],
    'B3 a tailwind internal' => ['box-shadow: var(--tw-ring-shadow)', 'B3'],
    // B4
    'B4 a non-colour inline style' => ['<div style="min-width: 11.25rem; width: 50%;">', 'B4'],
    'B4 a single-quoted non-colour style' => ["<div style='min-width: 11.25rem'>", 'B4'],
    'B4 a dynamic width' => ['<div style="width: {{ $percent }}%">', 'B4'],
    'B4 a custom-property width' => ['<div style="width: var(--progress);">', 'B4'],
    'B4 radius and border width are not colours' => ['<div style="border-radius: 4px; border-width: 1px;">', 'B4'],
    'B4 display and a display write' => ['<div style="display: none"><script>el.style.display = "none"; el.style.width = x;</script>', 'B4'],
    // B5
    'B5 the component focus ring' => ['<a class="focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">', 'B5'],
    'B5 a plain ring and offset' => ['<div class="ring-2 outline-offset-2 outline-2">', 'B5'],
    // B6
    'B6 a word that only looks similar' => ['<div class="inheritance legacyless">', 'B6'],
    // B7
    'B7 layout-only classes' => ['<x-ui.button class="w-full sm:w-auto mt-2">x</x-ui.button>', 'B7'],
    'B7 screen-reader label' => ['<x-ui.label class="sr-only">Status</x-ui.label>', 'B7'],
    'B7 size and weight text' => ['<x-ui.link class="text-sm font-medium font-mono text-xs">x</x-ui.link>', 'B7'],
    'B7 spacing and alignment' => ['<x-ui.alert class="mb-6 mt-2 ml-auto shrink-0 flex-1 min-w-0 self-end hidden">x</x-ui.alert>', 'B7'],
    'B7 responsive layout' => ['<x-ui.input class="w-full md:w-64 lg:col-span-2" />', 'B7'],
    'B7 an expression in the class only' => ['<x-ui.status class="{{ $class ?? \'\' }}" />', 'B7'],
    'B7 an attribute holding an arrow' => ['<x-ui.button :disabled="$a->b" class="w-full">x</x-ui.button>', 'B7'],
    'B7 a plain element may carry visual classes' => ['<div class="bg-surface border border-rule rounded-lg p-4 text-text">x</div>', 'B7'],
    // G1
    'G1 ordinary radius' => ['<div class="rounded-lg rounded-control rounded-full rounded-tag">', 'G1'],
]);

it('leaves ordinary and semantic presentation alone, rule by rule', function (string $nearMiss, string $rule) {
    // Per rule, as §17.3 specifies: a near-miss for one rule may legitimately belong to another (`var(--accent-success)`
    // is not a RETIRED alias, but it is still a raw legacy colour variable that Level B forbids in a target view).
    expect(guardRulesBroken(guardPrepare($nearMiss, 'blade'), [$rule]))->toBe([], "near-miss tripped {$rule}: {$nearMiss}");
})->with('guard near-misses');

it('passes the semantic, layout-only markup a target view is made of, under every rule at once', function () {
    $markup = '<div class="mb-6 rounded-lg border border-rule bg-surface p-5"><x-ui.button class="w-full" variant="secondary">Save</x-ui.button>'
        .'<p class="text-sm text-text-secondary" style="width: {{ $w }}%">x</p><x-ui.link href="#" class="text-xs">y</x-ui.link>'
        .'<td class="divide-y divide-rule hover:bg-surface-hover focus-visible:outline-2 min-h-[60vh]">&#9654;</td></div>';

    expect(guardRulesBroken(guardPrepare($markup, 'blade'), [...GUARD_LEVEL_A_RULES, 'A5', ...GUARD_LEVEL_B_RULES]))->toBe([]);
});

it('reports a mixed reintroduction with every rule it breaks, and line numbers that stay true', function () {
    $source = "<p class=\"ok\">\n{{-- a comment naming var(--accent) and bg-red-50 --}}\n<div class=\"bg-red-50 rounded-xl\" style='color: var(--text-primary);'>";
    $violations = guardScan(guardPrepare($source, 'blade'), [...GUARD_LEVEL_B_RULES]);

    expect(array_values(array_unique(array_column($violations, 'rule'))))->toEqualCanonicalizing(['B1', 'B3', 'B4', 'G1'])
        ->and(array_unique(array_column($violations, 'line')))->toBe([3])
        ->and(guardScan(guardPrepare('{{-- var(--accent) bg-red-50 --}}', 'blade'), [...GUARD_LEVEL_B_RULES]))->toBe([]);
});

it('ignores comments that document a retired value, in CSS and in Blade', function () {
    expect(guardScan(guardPrepare('/* --accent was retired; bg-red-50 */ .a { color: red; }', 'css'), ['A1', 'B1']))->toBe([])
        ->and(guardScan(guardPrepare("/** uses var(--accent) */\nconst a = 1;", 'js'), ['A1']))->toBe([]);
});

it('walks an x-ui tag to its real end: quotes, echoes and arrows do not end it early', function () {
    $tag = '<x-ui.button :href="route(\'a.b\', [\'c\' => $d->e])" {{ $attributes->merge([\'class\' => \'x\']) }} class="w-full text-danger">go</x-ui.button>';
    $hits = guardXUiOverrideHits($tag);

    expect($hits)->toHaveCount(1)
        ->and($hits[0][1])->toContain('text-danger');
});
