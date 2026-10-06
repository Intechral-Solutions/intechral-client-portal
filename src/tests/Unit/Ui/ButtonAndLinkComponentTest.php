<?php

use Illuminate\Support\Facades\DB;

/*
 * EPIC-016 WP1 §18.3: the Blade <x-ui.button> and <x-ui.link> contracts. These render the anonymous
 * components straight from a template string; they use no database and no request, which is itself part
 * of the contract (a component renders only from passed data and the shared error bag).
 */

require_once __DIR__.'/../../Support/UiHtmlHelpers.php';

it('draws the primary button as ink on both themes, with the exact focus ring', function () {
    $button = uiOne(uiDom(uiRender('<x-ui.button type="submit">Save</x-ui.button>')), '//button');
    $classes = uiClasses($button);

    expect($button->textContent)->toBe('Save')
        ->and($button->getAttribute('type'))->toBe('submit')
        ->and($classes)->toContain('bg-ink', 'text-on-ink', 'border-transparent', 'rounded-control', 'h-9', 'pointer-coarse:h-11', 'px-3')
        ->and($classes)->toContain(...UI_FOCUS_RING)
        // The retired treatments never come back through a component.
        ->and($classes)->not->toContain('outline-none', 'focus:ring-2', 'bg-brand-600', 'bg-teal-600', 'bg-accent');
});

it('defaults type to button and the variant to primary', function () {
    $button = uiOne(uiDom(uiRender('<x-ui.button>Go</x-ui.button>')), '//button');

    expect($button->getAttribute('type'))->toBe('button')
        ->and(uiClasses($button))->toContain('bg-ink');
});

it('draws secondary on surface with the control-edge boundary', function () {
    $classes = uiClasses(uiOne(uiDom(uiRender('<x-ui.button variant="secondary">Filter</x-ui.button>')), '//button'));

    expect($classes)->toContain('bg-surface', 'border-control-edge', 'text-text', 'hover:bg-surface-hover')
        ->and($classes)->not->toContain('bg-ink', 'border-rule-control');
});

it('draws ghost with no resting chrome', function () {
    $classes = uiClasses(uiOne(uiDom(uiRender('<x-ui.button variant="ghost">Cancel</x-ui.button>')), '//button'));

    expect($classes)->toContain('border-transparent', 'text-text', 'hover:bg-surface-hover')
        ->and($classes)->not->toContain('bg-ink', 'bg-surface');
});

it('draws the solid destructive variant on the danger tokens', function () {
    $classes = uiClasses(uiOne(uiDom(uiRender('<x-ui.button variant="destructive">Confirm</x-ui.button>')), '//button'));

    expect($classes)->toContain('bg-destructive', 'text-destructive-foreground');
});

it('draws the destructive TRIGGER as secondary or ghost with danger semantics, never as ink or solid danger', function () {
    $secondary = uiClasses(uiOne(uiDom(uiRender('<x-ui.button variant="secondary" tone="danger">Delete</x-ui.button>')), '//button'));
    $ghost = uiClasses(uiOne(uiDom(uiRender('<x-ui.button variant="ghost" tone="danger">Remove</x-ui.button>')), '//button'));

    expect($secondary)->toContain('border-danger', 'text-danger', 'bg-surface')
        ->and($secondary)->not->toContain('bg-ink', 'bg-destructive', 'border-control-edge', 'text-text')
        ->and($ghost)->toContain('text-danger', 'border-transparent')
        ->and($ghost)->not->toContain('bg-ink', 'bg-destructive', 'text-text');
});

it('rejects tone danger on primary and destructive, and unknown variants and sizes', function (string $template) {
    uiRender($template);
})->with([
    'primary' => '<x-ui.button tone="danger">x</x-ui.button>',
    'destructive' => '<x-ui.button variant="destructive" tone="danger">x</x-ui.button>',
    'unknown variant' => '<x-ui.button variant="teal">x</x-ui.button>',
    'unknown size' => '<x-ui.button size="huge">x</x-ui.button>',
    'tone other than danger' => '<x-ui.button variant="secondary" tone="success">x</x-ui.button>',
])->throws(InvalidArgumentException::class);

it('requires an aria-label on an icon button and keeps it', function () {
    expect(fn () => uiRender('<x-ui.button size="icon">&times;</x-ui.button>'))->toThrow(InvalidArgumentException::class);

    $button = uiOne(uiDom(uiRender('<x-ui.button size="icon" variant="ghost" tone="danger" aria-label="Remove line item">&times;</x-ui.button>')), '//button');

    expect($button->getAttribute('aria-label'))->toBe('Remove line item')
        ->and(uiClasses($button))->toContain('size-9', 'pointer-coarse:size-11');
});

it('scales the sizes with the shared control heights', function () {
    $sm = uiClasses(uiOne(uiDom(uiRender('<x-ui.button size="sm">x</x-ui.button>')), '//button'));
    $lg = uiClasses(uiOne(uiDom(uiRender('<x-ui.button size="lg">x</x-ui.button>')), '//button'));

    expect($sm)->toContain('h-8', 'pointer-coarse:h-10', 'text-xs')
        ->and($lg)->toContain('h-10', 'pointer-coarse:h-12', 'rounded-control-lg');
});

it('disables a real button with the disabled attribute and the sunken, muted treatment', function () {
    $button = uiOne(uiDom(uiRender('<x-ui.button disabled>Save</x-ui.button>')), '//button');

    expect($button->hasAttribute('disabled'))->toBeTrue()
        ->and(uiClasses($button))->toContain('disabled:bg-surface-sunken', 'disabled:text-text-muted', 'disabled:pointer-events-none')
        ->and(uiClasses($button))->not->toContain('disabled:text-text-faint');
});

it('renders href as an anchor with the same classes', function () {
    $dom = uiDom(uiRender('<x-ui.button href="/crm/contacts" variant="secondary" size="sm" target="_blank" rel="noopener">Contacts</x-ui.button>'));
    $anchor = uiOne($dom, '//a');

    expect($dom->query('//button')->length)->toBe(0)
        ->and($anchor->getAttribute('href'))->toBe('/crm/contacts')
        ->and($anchor->getAttribute('target'))->toBe('_blank')
        ->and($anchor->getAttribute('rel'))->toBe('noopener')
        ->and(uiClasses($anchor))->toContain('border-control-edge', 'h-8', ...UI_FOCUS_RING);
});

it('disables a link with aria-disabled and no href, never a fake enabled link', function () {
    $anchor = uiOne(uiDom(uiRender('<x-ui.button href="/pay" disabled>Pay</x-ui.button>')), '//a');

    expect($anchor->hasAttribute('href'))->toBeFalse()
        ->and($anchor->getAttribute('aria-disabled'))->toBe('true')
        ->and(uiClasses($anchor))->toContain('bg-surface-sunken', 'text-text-muted', 'pointer-events-none');
});

it('forwards name, value, form, data-*, aria-* and event attributes unchanged, and appends caller layout classes', function () {
    $button = uiOne(uiDom(uiRender('<x-ui.button type="submit" name="intent" value="save" form="f1" data-test="x" aria-describedby="hint" onclick="return confirm(\'Sure?\')" class="w-full mt-2">Go</x-ui.button>')), '//button');
    $classes = uiClasses($button);

    expect($button->getAttribute('name'))->toBe('intent')
        ->and($button->getAttribute('value'))->toBe('save')
        ->and($button->getAttribute('form'))->toBe('f1')
        ->and($button->getAttribute('data-test'))->toBe('x')
        ->and($button->getAttribute('aria-describedby'))->toBe('hint')
        ->and($button->getAttribute('onclick'))->toBe("return confirm('Sure?')")
        ->and($classes)->toContain('w-full', 'mt-2', 'bg-ink');
});

it('renders the accent link with the focus ring and passes href and attributes through', function () {
    $anchor = uiOne(uiDom(uiRender('<x-ui.link href="/tickets/7" data-x="1" class="text-sm">View</x-ui.link>')), '//a');
    $classes = uiClasses($anchor);

    expect($anchor->getAttribute('href'))->toBe('/tickets/7')
        ->and($anchor->getAttribute('data-x'))->toBe('1')
        ->and($anchor->textContent)->toBe('View')
        ->and($classes)->toContain('text-accent', 'hover:text-accent-hover', 'rounded-control', 'text-sm', ...UI_FOCUS_RING)
        ->and($classes)->not->toContain('outline-none');
});

it('transitions colour, background and border only, so the focus ring appears at full strength', function () {
    foreach (['<x-ui.link href="/a">x</x-ui.link>' => 'a', '<x-ui.button>x</x-ui.button>' => 'button'] as $template => $tag) {
        $element = uiOne(uiDom(uiRender($template)), "//{$tag}");
        $classes = uiClasses($element);

        expect($classes)->toContain('transition-[color,background-color,border-color]')
            ->and($classes)->not->toContain('transition-colors', 'transition-all');
    }
});

it('draws the quiet and row link variants from the reference row grammar', function () {
    $quiet = uiClasses(uiOne(uiDom(uiRender('<x-ui.link variant="quiet" href="/a">Back</x-ui.link>')), '//a'));
    $row = uiClasses(uiOne(uiDom(uiRender('<x-ui.link variant="row" href="/a">INV-1</x-ui.link>')), '//a'));

    expect($quiet)->toContain('text-text-secondary', 'underline', ...UI_FOCUS_RING)
        ->and($quiet)->not->toContain('text-accent')
        ->and($row)->toContain('text-text', 'font-medium', 'hover:underline', ...UI_FOCUS_RING)
        ->and($row)->not->toContain('text-accent');
});

it('rejects an unknown link variant', function () {
    uiRender('<x-ui.link variant="teal" href="/a">x</x-ui.link>');
})->throws(InvalidArgumentException::class);

it('renders every button and link without touching the database', function () {
    DB::enableQueryLog();

    uiRender('<x-ui.button>a</x-ui.button><x-ui.button href="/x">b</x-ui.button><x-ui.link href="/x">c</x-ui.link>');

    expect(DB::getQueryLog())->toBe([]);
});
