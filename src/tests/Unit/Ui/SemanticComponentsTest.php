<?php

use Illuminate\Support\Facades\DB;

/*
 * EPIC-016 WP2 §18.3: the Blade <x-ui.status>, <x-ui.priority>, <x-ui.alert> and <x-ui.tag> contracts, the
 * twins of resources/js/components/ui/{status,priority,alert,tag}.tsx. They render the anonymous
 * components straight from a template string, with no database and no request. Assertions are on parsed
 * DOM (class tokens, attributes, text), never whole-markup snapshots.
 *
 * The invariant under test is the epic's: a status is glyph + visible label + tone, and colour is never the
 * only signal.
 */

require_once __DIR__.'/../../Support/UiHtmlHelpers.php';

/** The status mark's own parts: root, glyph svg, label text. */
function uiStatusParts(string $template): array
{
    $xpath = uiDom(uiRender($template));
    $root = uiOne($xpath, '//span[svg]');
    $svg = uiOne($xpath, '//span[svg]/svg');
    $label = uiOne($xpath, '//span[svg]/span');

    return [$root, $svg, $label];
}

// ── x-ui.status ──────────────────────────────────────────────────────────────

dataset('status tones', [
    'neutral' => ['neutral', 'text-text-muted', 'text-text-muted', 'circle'],
    'info' => ['info', 'text-accent', 'text-accent', 'half'],
    'success' => ['success', 'text-success', 'text-success-glyph', 'check'],
    'warning' => ['warning', 'text-warning', 'text-warning-glyph', 'triangle'],
    'danger' => ['danger', 'text-danger', 'text-danger', 'square'],
    'live' => ['live', 'text-live-text', 'text-live', 'dot'],
]);

it('draws every tone with the text-safe label token, the shape-only glyph token and its default glyph', function (string $tone, string $label, string $glyphColour, string $defaultGlyph) {
    [$root, $svg, $text] = uiStatusParts('<x-ui.status tone="'.$tone.'">Some state</x-ui.status>');

    expect(uiClasses($root))->toContain('inline-flex', 'items-center', 'gap-1.5', 'text-sm', 'font-medium', $label)
        ->and(uiClasses($svg))->toContain('h-3', 'w-3', 'shrink-0', $glyphColour)
        ->and($svg->getAttribute('data-glyph'))->toBe($defaultGlyph)
        // The label stays visible text and the glyph is decoration: colour is never the only signal.
        ->and($text->textContent)->toBe('Some state')
        ->and($svg->getAttribute('aria-hidden'))->toBe('true')
        ->and($svg->getAttribute('focusable'))->toBe('false');
})->with('status tones');

it('defaults to the neutral tone', function () {
    [$root, $svg] = uiStatusParts('<x-ui.status>Plain</x-ui.status>');

    expect(uiClasses($root))->toContain('text-text-muted')
        ->and($svg->getAttribute('data-glyph'))->toBe('circle');
});

it('draws a distinct shape for every glyph in the shared vocabulary', function () {
    $shapes = [];
    foreach (['circle', 'half', 'dot', 'check', 'triangle', 'square', 'dashed'] as $glyph) {
        [, $svg] = uiStatusParts('<x-ui.status glyph="'.$glyph.'">X</x-ui.status>');
        $shapes[$glyph] = preg_replace('/\s+/', ' ', trim($svg->ownerDocument->saveHTML($svg)));
        expect($svg->getAttribute('data-glyph'))->toBe($glyph);
    }

    // Seven glyphs, seven different drawings: two states never differ by colour alone.
    expect(count(array_unique($shapes)))->toBe(7)
        ->and($shapes['dashed'])->toContain('stroke-dasharray')
        ->and($shapes['circle'])->not->toContain('stroke-dasharray')
        ->and($shapes['half'])->toContain('<path')
        ->and($shapes['check'])->toContain('stroke-linecap')
        ->and($shapes['square'])->toContain('<rect')
        ->and($shapes['triangle'])->toContain('M6 1.5 11 10.5H1z');
});

it('lets a glyph override the tone default without changing the tone colours', function () {
    [$root, $svg] = uiStatusParts('<x-ui.status tone="neutral" glyph="dashed">Draft</x-ui.status>');

    expect($svg->getAttribute('data-glyph'))->toBe('dashed')
        ->and(uiClasses($root))->toContain('text-text-muted')
        ->and(uiClasses($svg))->toContain('text-text-muted');
});

it('escapes the label, so a hostile string is text and never markup', function () {
    $xpath = uiDom(uiRender('<x-ui.status>{{ $text }}</x-ui.status>', [], ['text' => '<b onclick="x()">Hi</b>']));

    expect($xpath->query('//b'))->toHaveCount(0)
        ->and(uiOne($xpath, '//span[svg]/span')->textContent)->toBe('<b onclick="x()">Hi</b>');
});

it('passes attributes through and appends caller layout classes after its own', function () {
    $root = uiOne(uiDom(uiRender('<x-ui.status tone="danger" data-overdue-status class="ml-1" id="s1">Overdue</x-ui.status>')), '//span[svg]');

    expect($root->hasAttribute('data-overdue-status'))->toBeTrue()
        ->and($root->getAttribute('id'))->toBe('s1')
        ->and(uiClasses($root))->toContain('ml-1', 'text-danger');
});

it('never draws a filled pill, a hex colour or an inline style', function (string $tone) {
    $html = uiRender('<x-ui.status tone="'.$tone.'">X</x-ui.status>');

    expect($html)->not->toMatch('/rounded-full|bg-|style=|#[0-9a-fA-F]{3,8}\b|var\(--(?!ds-)/');
})->with(array_keys(['neutral' => 1, 'info' => 1, 'success' => 1, 'warning' => 1, 'danger' => 1, 'live' => 1]));

it('rejects an unknown tone or glyph instead of drawing something misleading', function () {
    expect(fn () => uiRender('<x-ui.status tone="purple">X</x-ui.status>'))->toThrow(InvalidArgumentException::class, 'tone')
        ->and(fn () => uiRender('<x-ui.status glyph="hourglass">X</x-ui.status>'))->toThrow(InvalidArgumentException::class, 'glyph');
});

// ── x-ui.priority ────────────────────────────────────────────────────────────

/** @return array{0: DOMElement, 1: list<string>, 2: DOMElement} root, bar states in order, label */
function uiPriorityParts(string $template): array
{
    $xpath = uiDom(uiRender($template));
    $bars = [];
    foreach ($xpath->query('//span[svg]/svg/rect') as $rect) {
        $bars[] = $rect->getAttribute('data-bar');
    }

    return [uiOne($xpath, '//span[svg]'), $bars, uiOne($xpath, '//span[svg]/span')];
}

it('draws one, two or three filled bars and always the visible label', function (int $bars, array $expected) {
    [$root, $states, $label] = uiPriorityParts('<x-ui.priority :bars="'.$bars.'">Some priority</x-ui.priority>');

    expect($states)->toBe($expected)
        ->and($label->textContent)->toBe('Some priority')
        ->and(uiClasses($root))->toContain('text-text-secondary', 'text-sm')
        ->and(uiClasses($root))->not->toContain('text-danger');
})->with([
    'one bar' => [1, ['filled', 'empty', 'empty']],
    'two bars' => [2, ['filled', 'filled', 'empty']],
    'three bars' => [3, ['filled', 'filled', 'filled']],
]);

it('draws the danger tone as the same three bars with the label in danger', function () {
    [$root, $states] = uiPriorityParts('<x-ui.priority :bars="3" tone="danger">Critical</x-ui.priority>');

    expect($states)->toBe(['filled', 'filled', 'filled'])
        ->and(uiClasses($root))->toContain('text-danger', 'font-medium')
        ->and(uiClasses($root))->not->toContain('text-text-secondary');
});

it('draws filled bars in the current colour and unfilled bars on rule-control, as decoration only', function () {
    $xpath = uiDom(uiRender('<x-ui.priority :bars="2">Medium</x-ui.priority>'));
    $svg = uiOne($xpath, '//span[svg]/svg');
    $rects = $xpath->query('//span[svg]/svg/rect');

    expect($svg->getAttribute('aria-hidden'))->toBe('true')
        ->and(uiClasses($rects->item(0)))->toContain('fill-current')
        ->and(uiClasses($rects->item(1)))->toContain('fill-current')
        ->and(uiClasses($rects->item(2)))->toContain('fill-rule-control');
});

it('rejects a bar count outside 1-3 and an unknown tone', function () {
    expect(fn () => uiRender('<x-ui.priority :bars="0">X</x-ui.priority>'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => uiRender('<x-ui.priority :bars="4">X</x-ui.priority>'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => uiRender('<x-ui.priority :bars="1" tone="warning">X</x-ui.priority>'))->toThrow(InvalidArgumentException::class);
});

// ── x-ui.alert ───────────────────────────────────────────────────────────────

dataset('alert variants', [
    'info' => ['info', 'status', 'Notice', 'text-accent', ['border-accent-line/40', 'bg-accent-soft']],
    'success' => ['success', 'status', 'Success', 'text-success', ['border-rule-control', 'bg-surface']],
    'warning' => ['warning', 'status', 'Warning', 'text-warning', ['border-warning-glyph/50', 'bg-warning-soft']],
    'danger' => ['danger', 'alert', 'Error', 'text-danger', ['border-danger/50', 'bg-danger-soft']],
]);

it('gives every semantic variant its own glyph, sr-only kind, tint and the right live-region role', function (string $variant, string $role, string $kind, string $glyphClass, array $surface) {
    $xpath = uiDom(uiRender('<x-ui.alert variant="'.$variant.'">The message</x-ui.alert>'));
    $root = uiOne($xpath, '//div[@data-variant]');

    expect($root->getAttribute('role'))->toBe($role)
        ->and(uiClasses($root))->toContain('rounded-control', 'border', 'px-4', 'py-3', 'text-sm', 'text-text', 'flex', 'items-start', 'gap-3', ...$surface)
        ->and(uiClasses(uiOne($xpath, '//div[@data-variant]/svg')))->toContain($glyphClass)
        ->and(uiOne($xpath, '//div[@data-variant]/svg')->getAttribute('aria-hidden'))->toBe('true')
        ->and(uiOne($xpath, '//div[@data-variant]/span[@class="sr-only"]')->textContent)->toBe($kind.': ')
        ->and(uiOne($xpath, '//div[@data-variant]/div[@data-alert-body]')->textContent)->toBe('The message');
})->with('alert variants');

it('uses role=alert for danger only; informational, success and warning are polite status regions', function () {
    $roles = [];
    foreach (['neutral', 'info', 'success', 'warning', 'danger'] as $variant) {
        $root = uiOne(uiDom(uiRender('<x-ui.alert variant="'.$variant.'">m</x-ui.alert>')), '//div[@data-variant]');
        $roles[$variant] = $root->hasAttribute('role') ? $root->getAttribute('role') : null;
    }

    expect($roles)->toBe(['neutral' => null, 'info' => 'status', 'success' => 'status', 'warning' => 'status', 'danger' => 'alert']);
});

it('draws neutral as a plain panel: no glyph, no kind label, no role', function () {
    $xpath = uiDom(uiRender('<x-ui.alert>Nothing here</x-ui.alert>'));
    $root = uiOne($xpath, '//div[@data-variant="neutral"]');

    expect($xpath->query('//div[@data-variant]/svg'))->toHaveCount(0)
        ->and($xpath->query('//span[@class="sr-only"]'))->toHaveCount(0)
        ->and($root->hasAttribute('role'))->toBeFalse()
        ->and(uiClasses($root))->toContain('border-rule-control', 'bg-surface')
        ->and(uiClasses($root))->not->toContain('flex')
        ->and($root->textContent)->toContain('Nothing here');
});

it('lets the caller override the role, pass attributes and add layout classes', function () {
    $root = uiOne(uiDom(uiRender('<x-ui.alert variant="success" role="alert" id="payment-message" class="mb-6 hidden" data-x="1">m</x-ui.alert>')), '//div[@data-variant]');

    expect($root->getAttribute('role'))->toBe('alert')
        ->and($root->getAttribute('id'))->toBe('payment-message')
        ->and($root->getAttribute('data-x'))->toBe('1')
        ->and(uiClasses($root))->toContain('mb-6', 'hidden', 'bg-surface');
});

it('renders an action slot outside the message body', function () {
    $xpath = uiDom(uiRender('<x-ui.alert variant="info">Body<x-slot:action><button type="button">Dismiss</button></x-slot:action></x-ui.alert>'));

    expect(uiOne($xpath, '//div[@data-alert-body]')->textContent)->toBe('Body')
        ->and($xpath->query('//div[@data-alert-body]//button'))->toHaveCount(0)
        ->and(uiOne($xpath, '//div[@data-variant]/button')->textContent)->toBe('Dismiss');
});

it('escapes the message and uses no hex, inline style or legacy variable', function () {
    $html = uiRender('<x-ui.alert variant="danger">{{ $text }}</x-ui.alert>', [], ['text' => '<script>x()</script>']);

    expect(uiDom($html)->query('//script'))->toHaveCount(0)
        ->and($html)->not->toMatch('/style=|#[0-9a-fA-F]{3,8}\b|var\(--|bg-red|text-red|border-red/');
});

it('rejects an unknown variant', function () {
    expect(fn () => uiRender('<x-ui.alert variant="loud">m</x-ui.alert>'))->toThrow(InvalidArgumentException::class, 'variant');
});

// ── x-ui.tag ─────────────────────────────────────────────────────────────────

it('draws a tag as a mono uppercase marker on the rule-control edge, not as a status or an accent pill', function () {
    $tag = uiOne(uiDom(uiRender('<x-ui.tag>Built-in</x-ui.tag>')), '//span');

    expect($tag->textContent)->toBe('Built-in')
        ->and(uiClasses($tag))->toContain('inline-block', 'rounded-tag', 'border', 'border-rule-control', 'font-mono', 'uppercase', 'text-text-muted')
        // A tag is a KIND, never state: no accent, no soft fill, no pill, no glyph.
        ->and(uiClasses($tag))->not->toContain('rounded-full', 'text-accent', 'bg-accent-soft', 'text-success', 'text-warning', 'text-danger')
        ->and($tag->getElementsByTagName('svg'))->toHaveCount(0);
});

it('passes tag attributes through and appends layout classes', function () {
    $tag = uiOne(uiDom(uiRender('<x-ui.tag class="ml-2" data-role-kind="custom">Custom</x-ui.tag>')), '//span');

    expect($tag->getAttribute('data-role-kind'))->toBe('custom')
        ->and(uiClasses($tag))->toContain('ml-2', 'font-mono');
});

// ── Shared ───────────────────────────────────────────────────────────────────

it('keeps the four semantic components free of the database, the request, hex and legacy palettes', function () {
    DB::enableQueryLog();
    foreach ([
        '<x-ui.status tone="success">A</x-ui.status>',
        '<x-ui.priority :bars="2">B</x-ui.priority>',
        '<x-ui.alert variant="warning">C</x-ui.alert>',
        '<x-ui.tag>D</x-ui.tag>',
    ] as $template) {
        uiRender($template);
    }

    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();

    foreach (['status', 'priority', 'alert', 'tag'] as $component) {
        $source = (string) file_get_contents(resource_path("views/components/ui/{$component}.blade.php"));
        $code = preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        expect($code)->not->toMatch('/\b(DB::|Request::|request\(\)|auth\(\)|::query\(|->where\()/')
            ->and($code)->not->toMatch('/#[0-9a-fA-F]{3,8}\b|var\(--(?!ds-)|style=|\b(?:bg|text|border)-(?:red|green|blue|amber|gray|indigo|slate|stone)-\d/');
    }
});
