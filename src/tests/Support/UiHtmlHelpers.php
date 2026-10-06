<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\ViewException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\ExpectationFailedException;

/*
 * EPIC-016 WP1: shared helpers for the Blade `x-ui` component tests and the target-form accessibility
 * contract. Loaded with require_once from each test file; the file name deliberately does not end in
 * "Test" so Pest does not treat it as a test. Function names carry a "ui" prefix so they never collide
 * with the global helpers of the other suites.
 *
 * Tests assert on parsed DOM (attributes and class tokens), never on whitespace or whole-markup
 * snapshots.
 */

/** Render a Blade template, with the shared `$errors` bag set from `$errors` (key => messages). */
function uiRender(string $template, array $errors = [], array $data = []): string
{
    view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag($errors)));

    try {
        return Blade::render($template, $data);
    } catch (ViewException $exception) {
        // Blade wraps what a component throws at render; the contract is about the original exception.
        $cause = $exception;
        while ($cause instanceof ViewException && $cause->getPrevious() !== null) {
            $cause = $cause->getPrevious();
        }

        throw $cause;
    }
}

/** Parse an HTML fragment or document. Parser warnings (HTML5 tags, inline svg) are not failures. */
function uiDom(string $html): DOMXPath
{
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($dom);
}

/** The single element matching the XPath, failing loudly when it is missing or ambiguous. */
function uiOne(DOMXPath $xpath, string $query): DOMElement
{
    $nodes = $xpath->query($query);

    if ($nodes === false || $nodes->length !== 1) {
        throw new RuntimeException("Expected exactly one node for [{$query}], found ".($nodes === false ? 'an invalid query' : $nodes->length).'.');
    }

    /** @var DOMElement $node */
    $node = $nodes->item(0);

    return $node;
}

/** @return list<string> */
function uiClasses(DOMElement $element): array
{
    return preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/** @return list<string> */
function uiTokens(DOMElement $element, string $attribute): array
{
    return preg_split('/\s+/', trim($element->getAttribute($attribute)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/** The exact focus-ring string every interactive component carries (control-metrics.ts `focusRing`). */
const UI_FOCUS_RING = ['focus-visible:outline-2', 'focus-visible:outline-offset-2', 'focus-visible:outline-focus'];

/**
 * The accessibility contract for one rendered page or fragment (EPIC-016 §10.1, §18.3). Returns a list
 * of human-readable violations; an empty list means the page satisfies it.
 *
 *   - every user-facing labelable control (textarea, select, and input of every type except hidden,
 *     submit, button, reset and image) has an accessible name: an associated label, a wrapping label,
 *     `aria-labelledby` or `aria-label`; a placeholder counts as a name (§7.2 rule 4);
 *   - no DOM id is rendered twice;
 *   - every `label[for]` and every `aria-labelledby` / `aria-describedby` token resolves to an element;
 *   - every field error (`role=alert`, id ending `-error`) is referenced by some `aria-describedby`;
 *   - every control that references a field error through `aria-describedby` is `aria-invalid="true"`
 *     (a `role=group` container is exempt: aria-invalid is not defined for it).
 *
 * @return list<string>
 */
function uiAccessibilityViolations(string $html): array
{
    $xpath = uiDom($html);
    $violations = [];

    // Duplicate ids.
    $seen = [];
    foreach ($xpath->query('//*[@id]') as $node) {
        $id = $node->getAttribute('id');
        $seen[$id] = ($seen[$id] ?? 0) + 1;
    }
    foreach ($seen as $id => $count) {
        if ($count > 1) {
            $violations[] = "duplicate id \"{$id}\" ({$count} times)";
        }
    }

    $ids = array_fill_keys(array_keys($seen), true);
    $textOf = fn (DOMNode $node): string => trim(preg_replace('/\s+/', ' ', $node->textContent) ?? '');

    // Dangling label[for].
    foreach ($xpath->query('//label[@for]') as $label) {
        if (! isset($ids[$label->getAttribute('for')])) {
            $violations[] = 'label[for="'.$label->getAttribute('for').'"] points at no element';
        }
    }

    // Accessible name of every user-facing labelable control.
    $controls = [];
    foreach ($xpath->query('//input | //textarea | //select') as $candidate) {
        /** @var DOMElement $candidate */
        $type = strtolower($candidate->getAttribute('type'));

        if ($candidate->tagName === 'input' && in_array($type, ['hidden', 'submit', 'button', 'reset', 'image'], true)) {
            continue;
        }

        $controls[] = $candidate;
    }
    foreach ($controls as $control) {
        /** @var DOMElement $control */
        $named = trim($control->getAttribute('aria-label')) !== ''
            || trim($control->getAttribute('placeholder')) !== '';

        foreach (uiTokens($control, 'aria-labelledby') as $token) {
            $target = $xpath->query('//*[@id="'.$token.'"]')->item(0);
            $named = $named || ($target !== null && $textOf($target) !== '');
        }

        $id = $control->getAttribute('id');
        if (! $named && $id !== '') {
            foreach ($xpath->query('//label[@for="'.$id.'"]') as $label) {
                $named = $named || $textOf($label) !== '';
            }
        }

        if (! $named) {
            for ($ancestor = $control->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
                if ($ancestor->tagName === 'label' && $textOf($ancestor) !== '') {
                    $named = true;
                    break;
                }
            }
        }

        if (! $named) {
            $violations[] = 'unnamed <'.$control->tagName.'> name="'.$control->getAttribute('name').'" id="'.$id.'"';
        }
    }

    // Dangling aria-labelledby / aria-describedby.
    foreach ($xpath->query('//*[@aria-labelledby or @aria-describedby]') as $node) {
        /** @var DOMElement $node */
        foreach (array_merge(uiTokens($node, 'aria-labelledby'), uiTokens($node, 'aria-describedby')) as $token) {
            if (! isset($ids[$token])) {
                $violations[] = '<'.$node->tagName.' id="'.$node->getAttribute('id').'"> references missing id "'.$token.'"';
            }
        }
    }

    // Field errors must be associated with a field, and the field must be marked invalid.
    $describedErrors = [];
    foreach ($xpath->query('//*[@aria-describedby]') as $node) {
        /** @var DOMElement $node */
        foreach (uiTokens($node, 'aria-describedby') as $token) {
            if (str_ends_with($token, '-error')) {
                $describedErrors[$token] = true;
                $isGroup = $node->getAttribute('role') === 'group';

                if (! $isGroup && $node->getAttribute('aria-invalid') !== 'true') {
                    $violations[] = '<'.$node->tagName.' id="'.$node->getAttribute('id').'"> is described by the error "'.$token.'" but is not aria-invalid="true"';
                }
            }
        }
    }
    foreach ($xpath->query('//*[@role="alert"][@id]') as $alert) {
        /** @var DOMElement $alert */
        $id = $alert->getAttribute('id');
        if (str_ends_with($id, '-error') && ! isset($describedErrors[$id])) {
            $violations[] = "field error \"{$id}\" is not referenced by any aria-describedby";
        }
    }

    return $violations;
}

/** The user-facing labelable controls of a page (the controls the name contract applies to). */
function uiLabelableCount(string $html): int
{
    $count = 0;

    foreach (uiDom($html)->query('//input | //textarea | //select') as $node) {
        /** @var DOMElement $node */
        $type = strtolower($node->getAttribute('type'));

        if ($node->tagName === 'input' && in_array($type, ['hidden', 'submit', 'button', 'reset', 'image'], true)) {
            continue;
        }

        $count++;
    }

    return $count;
}

/**
 * `expect($violations)->toHaveViolation('unnamed')`: some violation message contains the needle. The
 * violations are descriptive sentences, so the contract tests match on the stable part of each.
 */
expect()->extend('toHaveViolation', function (string $needle) {
    foreach ($this->value as $violation) {
        if (str_contains($violation, $needle)) {
            Assert::assertStringContainsString($needle, $violation);

            return $this;
        }
    }

    throw new ExpectationFailedException(
        'Failed asserting that a violation contains "'.$needle.'". Violations: '.json_encode($this->value),
    );
});
