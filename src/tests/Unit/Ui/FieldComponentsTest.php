<?php

use Illuminate\Support\Facades\DB;

/*
 * EPIC-016 WP1 §18.3: <x-ui.input>, <x-ui.select>, <x-ui.textarea>, <x-ui.checkbox>, <x-ui.label> and
 * <x-ui.field-error>, and the field identity contract (§7.5) they share: name / id / error-key / error
 * element id kept separate, `aria-describedby` merged and never replaced.
 */

require_once __DIR__.'/../../Support/UiHtmlHelpers.php';

dataset('field tags', [
    'input' => ['input', '<x-ui.input %s />', '//input'],
    'select' => ['select', '<x-ui.select %s><option value="a">A</option></x-ui.select>', '//select'],
    'textarea' => ['textarea', '<x-ui.textarea %s>body</x-ui.textarea>', '//textarea'],
]);

it('gives every field the Direction D boundary, surface, hover, focus and no outline-none', function (string $tag, string $template, string $query) {
    $field = uiOne(uiDom(uiRender(sprintf($template, 'name="title"'))), $query);
    $classes = uiClasses($field);

    expect($classes)->toContain('border', 'border-control-edge', 'bg-surface', 'text-text', 'rounded-control', 'hover:border-text-muted', 'aria-invalid:border-danger', 'text-sm')
        ->and($classes)->toContain(...UI_FOCUS_RING)
        ->and($classes)->toContain('disabled:bg-surface-sunken', 'disabled:text-text-muted', 'disabled:border-rule-control')
        // None of the legacy treatment that left 44 field sites with no keyboard focus.
        ->and($classes)->not->toContain('outline-none', 'focus:ring-2', 'focus:ring', 'rounded-lg')
        ->and(implode(' ', $classes))->not->toMatch('/surface-input|border-base|var\(--/');
})->with('field tags');

it('uses the shared control heights on input and select, and a minimum height on textarea', function () {
    $input = uiClasses(uiOne(uiDom(uiRender('<x-ui.input name="a" />')), '//input'));
    $select = uiClasses(uiOne(uiDom(uiRender('<x-ui.select name="a"></x-ui.select>')), '//select'));
    $textarea = uiClasses(uiOne(uiDom(uiRender('<x-ui.textarea name="a"></x-ui.textarea>')), '//textarea'));

    expect($input)->toContain('h-9', 'pointer-coarse:h-11', 'placeholder:text-text-muted')
        ->and($select)->toContain('h-9', 'pointer-coarse:h-11')
        ->and($textarea)->toContain('min-h-24', 'resize-y', 'py-2');
});

it('derives id and error-key from a simple name and forwards type, value, required and placeholder', function () {
    $input = uiOne(uiDom(uiRender('<x-ui.input type="email" name="first_name" value="Ada" required placeholder="Name" class="w-full" />')), '//input');

    expect($input->getAttribute('id'))->toBe('first_name')
        ->and($input->getAttribute('name'))->toBe('first_name')
        ->and($input->getAttribute('type'))->toBe('email')
        ->and($input->getAttribute('value'))->toBe('Ada')
        ->and($input->hasAttribute('required'))->toBeTrue()
        ->and($input->getAttribute('placeholder'))->toBe('Name')
        ->and(uiClasses($input))->toContain('w-full')
        ->and($input->hasAttribute('aria-invalid'))->toBeFalse()
        ->and($input->hasAttribute('aria-describedby'))->toBeFalse();
});

it('renders the old() value the caller passes, HTML-escaped', function () {
    $session = app('session.store');
    $session->put('_old_input', ['title' => 'A "quoted" <b>value</b>']);
    request()->setLaravelSession($session);

    $input = uiOne(uiDom(uiRender('<x-ui.input name="title" :value="old(\'title\')" />')), '//input');

    expect($input->getAttribute('value'))->toBe('A "quoted" <b>value</b>');
});

it('lets an explicit id win over the name', function () {
    $input = uiOne(uiDom(uiRender('<x-ui.input name="email" id="invite-email" />')), '//input');

    expect($input->getAttribute('id'))->toBe('invite-email')
        ->and($input->getAttribute('name'))->toBe('email');
});

it('refuses a nested or array name without an explicit id', function (string $template) {
    uiRender($template);
})->with([
    'nested' => '<x-ui.input name="items[3][description]" />',
    'array' => '<x-ui.input name="attachments[]" type="file" />',
    'select' => '<x-ui.select name="items[0][kind]"></x-ui.select>',
    'textarea' => '<x-ui.textarea name="notes[0]"></x-ui.textarea>',
])->throws(InvalidArgumentException::class, 'explicit id');

it('accepts an array name with an explicit id and error-key, and takes the id as given', function () {
    $input = uiOne(uiDom(uiRender('<x-ui.input name="items[3][description]" id="items-3-description" error-key="items.3.description" />')), '//input');

    expect($input->getAttribute('id'))->toBe('items-3-description')
        ->and($input->getAttribute('name'))->toBe('items[3][description]');
});

it('marks a field invalid from a server error: aria-invalid, danger edge and the error id in aria-describedby', function (string $tag, string $template, string $query) {
    $field = uiOne(uiDom(uiRender(sprintf($template, 'name="title"'), ['title' => ['The title field is required.']])), $query);

    expect($field->getAttribute('aria-invalid'))->toBe('true')
        ->and($field->getAttribute('aria-describedby'))->toBe('title-error')
        ->and(uiClasses($field))->toContain('aria-invalid:border-danger');
})->with('field tags');

it('merges the error id after the caller tokens, de-duplicated, and keeps the caller tokens when valid', function () {
    $invalid = uiOne(uiDom(uiRender('<x-ui.input name="name" aria-describedby="name-hint other name-hint" />', ['name' => ['Taken.']])), '//input');
    $valid = uiOne(uiDom(uiRender('<x-ui.input name="name" aria-describedby="name-hint" />')), '//input');
    $already = uiOne(uiDom(uiRender('<x-ui.input name="name" aria-describedby="name-hint name-error" />', ['name' => ['Taken.']])), '//input');

    expect($invalid->getAttribute('aria-describedby'))->toBe('name-hint other name-error')
        ->and($valid->getAttribute('aria-describedby'))->toBe('name-hint')
        ->and($valid->hasAttribute('aria-invalid'))->toBeFalse()
        ->and($already->getAttribute('aria-describedby'))->toBe('name-hint name-error');
});

it('does not mark a field invalid for another field\'s error', function () {
    $input = uiOne(uiDom(uiRender('<x-ui.input name="title" />', ['description' => ['Required.']])), '//input');

    expect($input->hasAttribute('aria-invalid'))->toBeFalse();
});

it('looks the error up by error-key, which may differ from the name and the id', function () {
    $html = uiRender(
        '<x-ui.input name="items[2][description]" id="items-2-description" error-key="items.2.description" /><x-ui.input name="items[1][description]" id="items-1-description" error-key="items.1.description" />',
        ['items.2.description' => ['The items.2.description field is required.']],
    );
    $xpath = uiDom($html);

    expect(uiOne($xpath, '//input[@id="items-2-description"]')->getAttribute('aria-invalid'))->toBe('true')
        ->and(uiOne($xpath, '//input[@id="items-2-description"]')->getAttribute('aria-describedby'))->toBe('items-2-description-error')
        ->and(uiOne($xpath, '//input[@id="items-1-description"]')->hasAttribute('aria-invalid'))->toBeFalse();
});

it('treats an ordered error-key list, including a wildcard, as invalid when any key has an error', function () {
    $template = '<x-ui.input type="file" name="attachments[]" id="attachments" :error-key="[\'attachments\', \'attachments.*\']" />';

    $perFile = uiOne(uiDom(uiRender($template, ['attachments.1' => ['The attachments.1 failed to upload.']])), '//input');
    $array = uiOne(uiDom(uiRender($template, ['attachments' => ['Too many files.']])), '//input');
    $none = uiOne(uiDom(uiRender($template)), '//input');

    expect($perFile->getAttribute('aria-invalid'))->toBe('true')
        ->and($perFile->getAttribute('aria-describedby'))->toBe('attachments-error')
        ->and($array->getAttribute('aria-invalid'))->toBe('true')
        ->and($none->hasAttribute('aria-invalid'))->toBeFalse();
});

it('forces the invalid state with the invalid prop', function () {
    $input = uiOne(uiDom(uiRender('<x-ui.input name="x" :invalid="true" />')), '//input');

    expect($input->getAttribute('aria-invalid'))->toBe('true')
        ->and($input->getAttribute('aria-describedby'))->toBe('x-error');
});

it('disables a field with the disabled attribute and keeps its value muted, never faint', function () {
    $input = uiOne(uiDom(uiRender('<x-ui.input name="x" disabled value="v" />')), '//input');

    expect($input->hasAttribute('disabled'))->toBeTrue()
        ->and(uiClasses($input))->toContain('disabled:text-text-muted')
        ->and(uiClasses($input))->not->toContain('disabled:text-text-faint');
});

it('styles a file input so the picker reads as part of the field', function () {
    $input = uiOne(uiDom(uiRender('<x-ui.input type="file" name="attachments[]" id="attachments" multiple />')), '//input');

    expect($input->hasAttribute('multiple'))->toBeTrue()
        ->and(uiClasses($input))->toContain('file:border-0', 'file:bg-transparent');
});

it('puts the select options and the textarea value in the slot', function () {
    $xpath = uiDom(uiRender('<x-ui.select name="role"><option value="m">Member</option><option value="a" selected>Admin</option></x-ui.select><x-ui.textarea name="notes" rows="3">line one</x-ui.textarea>'));

    expect($xpath->query('//select/option')->length)->toBe(2)
        ->and(uiOne($xpath, '//select/option[@selected]')->getAttribute('value'))->toBe('a')
        ->and(uiOne($xpath, '//textarea')->textContent)->toBe('line one')
        ->and(uiOne($xpath, '//textarea')->getAttribute('rows'))->toBe('3');
});

// ── label ───────────────────────────────────────────────────────────────────

it('renders a label whose for is the DOM id, with the Label typography', function () {
    $label = uiOne(uiDom(uiRender('<x-ui.label for="items-2-description">Description</x-ui.label>')), '//label');

    expect($label->getAttribute('for'))->toBe('items-2-description')
        ->and($label->textContent)->toBe('Description')
        ->and(uiClasses($label))->toContain('text-sm', 'font-medium', 'text-text');
});

it('renders the required marker in danger, and a label with no for', function () {
    $xpath = uiDom(uiRender('<x-ui.label for="title" required>Subject</x-ui.label><x-ui.label>Wrapper</x-ui.label>'));
    $required = uiOne($xpath, '//label[@for="title"]');
    $marker = uiOne($xpath, '//label[@for="title"]/span');

    expect(preg_replace('/\s+/', ' ', $required->textContent))->toBe('Subject *')
        ->and(uiClasses($marker))->toContain('text-danger')
        ->and($xpath->query('//label[not(@for)]')->length)->toBe(1);
});

it('lets a label be visually hidden and still name its field', function () {
    $html = uiRender('<x-ui.label for="q" class="sr-only">Search</x-ui.label><x-ui.input name="q" />');

    expect(uiClasses(uiOne(uiDom($html), '//label')))->toContain('sr-only')
        ->and(uiAccessibilityViolations($html))->toBe([]);
});

// ── field-error ─────────────────────────────────────────────────────────────

it('renders the error with the deterministic id, an alert role and the first message', function () {
    $error = uiOne(uiDom(uiRender('<x-ui.field-error for="title" />', ['title' => ['First problem.', 'Second problem.']])), '//p');

    expect($error->getAttribute('id'))->toBe('title-error')
        ->and($error->getAttribute('role'))->toBe('alert')
        ->and($error->textContent)->toBe('First problem.')
        ->and(uiClasses($error))->toContain('text-sm', 'text-danger');
});

it('renders nothing when there is no error for the key', function () {
    expect(trim(uiRender('<x-ui.field-error for="title" />', ['other' => ['x']])))->toBe('')
        ->and(trim(uiRender('<x-ui.field-error for="title" />')))->toBe('');
});

it('looks the message up by error-key and builds the id from for', function () {
    $error = uiOne(uiDom(uiRender('<x-ui.field-error for="items-2-unit_price" error-key="items.2.unit_price" />', ['items.2.unit_price' => ['Bad price.']])), '//p');

    expect($error->getAttribute('id'))->toBe('items-2-unit_price-error')
        ->and($error->textContent)->toBe('Bad price.');
});

it('shows the first message in key order across an ordered list with a wildcard fallback', function () {
    $template = '<x-ui.field-error for="attachments" :error-key="[\'attachments\', \'attachments.*\']" />';

    $both = uiOne(uiDom(uiRender($template, ['attachments.0' => ['File too large.'], 'attachments' => ['Too many files.']])), '//p');
    $wildcardOnly = uiOne(uiDom(uiRender($template, ['attachments.1' => ['File 2 failed.']])), '//p');

    expect($both->textContent)->toBe('Too many files.')
        ->and($wildcardOnly->textContent)->toBe('File 2 failed.')
        ->and($wildcardOnly->getAttribute('id'))->toBe('attachments-error');
});

// ── checkbox ────────────────────────────────────────────────────────────────

it('draws a native checkbox in the selection accent with the focus ring, never the legacy accent', function () {
    $input = uiOne(uiDom(uiRender('<x-ui.checkbox name="is_internal" value="1" aria-label="Internal" />')), '//input');
    $classes = uiClasses($input);

    expect($input->getAttribute('type'))->toBe('checkbox')
        ->and($input->getAttribute('id'))->toBe('is_internal')
        ->and($input->getAttribute('value'))->toBe('1')
        ->and($classes)->toContain('accent-accent', 'size-4', ...UI_FOCUS_RING)
        ->and($classes)->not->toContain('accent-legacy-accent', 'bg-ink', 'accent-ink', 'outline-none');
});

it('renders checked and disabled states', function () {
    $xpath = uiDom(uiRender('<x-ui.checkbox name="a" value="1" :checked="true" aria-label="A" /><x-ui.checkbox name="b" value="1" :checked="false" disabled aria-label="B" />'));

    expect(uiOne($xpath, '//input[@name="a"]')->hasAttribute('checked'))->toBeTrue()
        ->and(uiOne($xpath, '//input[@name="b"]')->hasAttribute('checked'))->toBeFalse()
        ->and(uiOne($xpath, '//input[@name="b"]')->hasAttribute('disabled'))->toBeTrue();
});

it('wraps a checkbox in its own label when it has slot content, naming it, with disabled kept muted', function () {
    $xpath = uiDom(uiRender('<x-ui.checkbox name="is_internal" value="1" class="mt-2">Internal note</x-ui.checkbox>'));
    $label = uiOne($xpath, '//label');

    expect($label->textContent)->toContain('Internal note')
        ->and(uiClasses($label))->toContain('mt-2', 'has-[:disabled]:text-text-muted')
        ->and(uiOne($xpath, '//label/input')->getAttribute('type'))->toBe('checkbox')
        ->and(uiClasses(uiOne($xpath, '//label/input')))->not->toContain('mt-2');
});

it('needs no id for a wrapped array-named checkbox, and a lone one needs an aria-label to be named', function () {
    $html = uiRender('<label><x-ui.checkbox name="permissions[]" value="users.view" /> view</label>');
    $input = uiOne(uiDom($html), '//input');

    expect($input->hasAttribute('id'))->toBeFalse()
        ->and($input->getAttribute('name'))->toBe('permissions[]')
        ->and(uiAccessibilityViolations($html))->toBe([])
        ->and(uiAccessibilityViolations(uiRender('<x-ui.checkbox name="ticket_ids[]" id="ticket-cb-1" value="1" />')))->not->toBe([]);
});

it('keeps a group checkbox pattern truthful: role group, labelled, one group error, described by it', function () {
    $html = uiRender(
        '<div id="permissions-group" role="group" aria-labelledby="permissions-heading" aria-describedby="permissions-group-error">'
        .'<p id="permissions-heading">Permissions</p>'
        .'<label><x-ui.checkbox name="permissions[]" value="a" /> a</label>'
        .'<label><x-ui.checkbox name="permissions[]" value="b" /> b</label>'
        .'</div><x-ui.field-error for="permissions-group" :error-key="[\'permissions\', \'permissions.*\']" />',
        ['permissions.1' => ['The selected permission is invalid.']],
    );
    $xpath = uiDom($html);

    expect($xpath->query('//*[@role="alert"]')->length)->toBe(1)
        ->and(uiOne($xpath, '//*[@role="alert"]')->getAttribute('id'))->toBe('permissions-group-error')
        ->and(uiAccessibilityViolations($html))->toBe([]);
});

it('marks a single invalid checkbox like any other field', function () {
    $input = uiOne(uiDom(uiRender('<x-ui.checkbox name="agree" value="1" aria-label="Agree" />', ['agree' => ['You must agree.']])), '//input');

    expect($input->getAttribute('aria-invalid'))->toBe('true')
        ->and($input->getAttribute('aria-describedby'))->toBe('agree-error');
});

it('renders every field component without touching the database', function () {
    DB::enableQueryLog();

    uiRender('<x-ui.input name="a" /><x-ui.select name="b"></x-ui.select><x-ui.textarea name="c"></x-ui.textarea><x-ui.checkbox name="d" aria-label="d" /><x-ui.label for="a">A</x-ui.label><x-ui.field-error for="a" />', ['a' => ['x']]);

    expect(DB::getQueryLog())->toBe([]);
});
