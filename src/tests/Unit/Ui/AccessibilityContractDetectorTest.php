<?php

/*
 * EPIC-016 WP1 §18.3 "Target forms": the accessibility contract that every target page is held to
 * (uiAccessibilityViolations) must not be vacuous. Each case below feeds it one deliberately broken
 * fragment and requires the matching violation, so a contract that stopped detecting a defect would fail
 * here before it quietly passed every real page.
 */

require_once __DIR__.'/../../Support/UiHtmlHelpers.php';

it('passes a fully labelled, associated, truthful form', function () {
    $html = uiRender(
        '<x-ui.label for="title" required>Subject</x-ui.label><x-ui.input name="title" aria-describedby="title-hint" /><p id="title-hint">Hint</p><x-ui.field-error for="title" />',
        ['title' => ['The title field is required.']],
    );

    expect(uiAccessibilityViolations($html))->toBe([])
        ->and(uiLabelableCount($html))->toBe(1);
});

it('detects an unlabeled labelable field of every kind', function (string $field) {
    expect(uiAccessibilityViolations($field))->toHaveViolation('unnamed');
})->with([
    'input' => '<input type="text" name="q" id="q">',
    'select' => '<select name="s" id="s"><option>a</option></select>',
    'textarea' => '<textarea name="t" id="t"></textarea>',
    'checkbox' => '<input type="checkbox" name="c" id="c">',
    'file' => '<input type="file" name="f" id="f">',
]);

it('does not count hidden, submit, button, reset and image inputs as labelable', function () {
    $html = '<input type="hidden" name="a"><input type="submit" value="Go"><input type="button" value="B"><input type="reset"><input type="image" alt="x">';

    expect(uiAccessibilityViolations($html))->toBe([])
        ->and(uiLabelableCount($html))->toBe(0);
});

it('accepts each of the four name sources and the placeholder fallback', function () {
    $html = '<label for="a">A</label><input id="a">'
        .'<label>Wrapped <input id="b"></label>'
        .'<span id="t">Named by text</span><input id="c" aria-labelledby="t">'
        .'<input id="d" aria-label="Aria">'
        .'<input id="e" placeholder="Search">';

    expect(uiAccessibilityViolations($html))->toBe([]);
});

it('does not accept an empty label or an aria-labelledby that points at nothing as a name', function () {
    expect(uiAccessibilityViolations('<label for="a"> </label><input id="a">'))->toHaveViolation('unnamed')
        ->and(uiAccessibilityViolations('<input id="a" aria-labelledby="gone">'))->toHaveViolation('unnamed');
});

it('detects a duplicate id', function () {
    expect(uiAccessibilityViolations('<label for="x">X</label><input id="x"><input id="x" aria-label="again">'))
        ->toHaveViolation('duplicate id "x"');
});

it('detects a dangling label[for]', function () {
    expect(uiAccessibilityViolations('<label for="nowhere">Name</label><input id="real" aria-label="Real">'))
        ->toHaveViolation('label[for="nowhere"] points at no element');
});

it('detects an error that is not associated with its field', function () {
    $html = '<label for="t">T</label><input id="t" aria-invalid="true"><p id="t-error" role="alert">Required.</p>';

    expect(uiAccessibilityViolations($html))->toHaveViolation('field error "t-error" is not referenced by any aria-describedby');
});

it('detects a missing aria-invalid on a field described by its error', function () {
    $html = '<label for="t">T</label><input id="t" aria-describedby="t-error"><p id="t-error" role="alert">Required.</p>';

    expect(uiAccessibilityViolations($html))->toHaveViolation('is described by the error "t-error" but is not aria-invalid="true"');
});

it('detects an aria-describedby that points at nothing', function () {
    expect(uiAccessibilityViolations('<input id="t" aria-label="T" aria-invalid="true" aria-describedby="t-error">'))
        ->toHaveViolation('references missing id "t-error"');
});

it('exempts a role=group container from aria-invalid but not from association', function () {
    $ok = '<div role="group" aria-labelledby="h" aria-describedby="g-error"><p id="h">Permissions</p></div><p id="g-error" role="alert">Bad.</p>';
    $orphan = '<div role="group" aria-labelledby="h"><p id="h">Permissions</p></div><p id="g-error" role="alert">Bad.</p>';

    expect(uiAccessibilityViolations($ok))->toBe([])
        ->and(uiAccessibilityViolations($orphan))->toHaveViolation('field error "g-error" is not referenced by any aria-describedby');
});
