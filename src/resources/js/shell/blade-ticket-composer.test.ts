import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { initTicketComposer } from './blade-ticket-composer';

/**
 * Release 1.0 (HD-C1) — the operator composer's runtime behaviour, in jsdom.
 *
 * The fixture carries the hooks `blade-ticket-composer.ts` reads, as `components/ui/visibility-toggle.blade.php` and
 * `due-date-control.blade.php` render them. The real markup and the contract with the server are asserted on the rendered HTML in
 * `tests/Feature/Tickets/OperatorComposerControlsTest.php`; the native picker, focus order and layout in real Chromium in
 * `tests/Browser/operator-composer-controls.spec.ts` (jsdom has no picker and no layout).
 */

// 2026-11-04 10:00 UTC. The SLA deadline is 24 hours later; the application zone is UTC.
const NOW = Date.UTC(2026, 10, 4, 10, 0, 0);
const HOUR = 3_600_000;

interface Options {
    internal?: boolean;
    original?: string | null; // wall clock in the app zone
    hasOverride?: boolean;
    sla?: string | null;
    action?: string;
    at?: string;
    timeZone?: string;
    slaText?: string;
    /** A refused post marks the date button invalid (the server rendered `aria-invalid="true"` on it). */
    serverInvalid?: boolean;
}

function epochOf(wall: string | null | undefined, zone = 'UTC'): string {
    if (!wall) {
        return '';
    }

    // UTC fixtures only; the zone tests below build their own markup.
    return zone === 'UTC' ? String(Date.parse(`${wall}:00Z`)) : '';
}

function mount(options: Options = {}) {
    const {
        internal = false,
        original = '2026-11-05T10:00',
        hasOverride = false,
        sla = '2026-11-05T10:00',
        action = 'keep',
        at = '',
        timeZone = 'UTC',
        slaText = sla ? '05 Nov 2026 10:00' : 'none set',
        serverInvalid = false,
    } = options;
    const shown = action === 'restore' ? sla : action === 'set' ? at : original;

    document.body.innerHTML = `
        <form id="reply" action="/reply" method="post">
            <textarea name="body">Hello</textarea>
            <div role="group" aria-label="Update options">
                <div data-visibility-toggle data-tooltip-host>
                    <input type="hidden" name="is_internal" value="${internal ? '1' : '0'}" data-visibility-input>
                    <button type="button" data-visibility-button data-state="${internal ? 'hidden' : 'visible'}">
                        <span>Visible<span class="sr-only"> to submitter. Activate to hide it.</span></span>
                        <span>Hidden<span class="sr-only"> from submitter. Activate to make it visible.</span></span>
                    </button>
                    <span id="visibility-toggle-tip" role="tooltip">tip</span>
                </div>
                <div data-due-control data-tooltip-host
                     data-timezone="${timeZone}" data-zone-label="UTC" data-server-now="${NOW}" data-max-days="30"
                     data-original-wall="${original ?? ''}" data-original-epoch="${epochOf(original)}"
                     data-sla-wall="${sla ?? ''}" data-sla-epoch="${epochOf(sla)}" data-sla-text="${slaText}"
                     data-has-override="${hasOverride ? '1' : '0'}">
                    <input type="hidden" name="due_action" value="${action}" data-due-action>
                    <input type="hidden" name="due_at" value="${at}" data-due-at>
                    <button type="button" data-due-trigger data-urgency="none"${action === 'keep' ? '' : ` data-pending="${action}"`}${serverInvalid ? ' aria-invalid="true"' : ''}
                            aria-expanded="false" aria-controls="due-date-editor" aria-describedby="due-date-tip due-date-editor-message">
                        <span class="sr-only">Due date:</span>
                        <span data-due-text>${shown || 'No due date'}</span><span data-due-zone${shown ? '' : ' hidden'}>UTC</span><span data-due-pending-mark${action === 'keep' ? ' hidden' : ''}>Pending</span>
                    </button>
                    <div id="due-date-editor" data-due-editor hidden role="group" aria-labelledby="due-date-editor-title">
                        <p id="due-date-editor-title">Change due date</p>
                        <input type="datetime-local" data-due-input form="due-date-editor-detached" aria-label="Due date and time (UTC)"
                               aria-describedby="due-date-editor-help due-date-editor-message">
                        <p id="due-date-editor-help" data-due-relative></p>
                        <p id="due-date-editor-message" role="alert" hidden></p>
                        <div data-due-actions${action === 'keep' && !hasOverride ? ' hidden' : ''}>
                            ${hasOverride ? `<button type="button" data-due-restore${action === 'restore' ? ' hidden' : ''}>Restore SLA</button>` : ''}
                            <button type="button" data-due-discard${action === 'keep' ? ' hidden' : ''}>Undo change</button>
                        </div>
                    </div>
                    <span id="due-date-tip" role="tooltip" data-due-tip></span>
                </div>
            </div>
            <div data-composer-announcer role="status"></div>
            <button type="submit">Post Update</button>
        </form>`;

    initTicketComposer();

    const q = <T extends Element>(selector: string) => document.querySelector<T>(selector)!;

    return {
        form: q<HTMLFormElement>('#reply'),
        toggle: q<HTMLButtonElement>('[data-visibility-button]'),
        isInternal: q<HTMLInputElement>('[data-visibility-input]'),
        trigger: q<HTMLButtonElement>('[data-due-trigger]'),
        editor: q<HTMLElement>('[data-due-editor]'),
        input: q<HTMLInputElement>('[data-due-input]'),
        action: q<HTMLInputElement>('[data-due-action]'),
        at: q<HTMLInputElement>('[data-due-at]'),
        text: q<HTMLElement>('[data-due-text]'),
        mark: q<HTMLElement>('[data-due-pending-mark]'),
        tip: q<HTMLElement>('[data-due-tip]'),
        error: q<HTMLElement>('#due-date-editor-message'),
        announcer: q<HTMLElement>('[data-composer-announcer]'),
        restore: document.querySelector<HTMLButtonElement>('[data-due-restore]'),
        discard: q<HTMLButtonElement>('[data-due-discard]'),
        actions: q<HTMLElement>('[data-due-actions]'),
    };
}

/** Type a value into the editor the way the browser reports a completed one. */
function type(input: HTMLInputElement, value: string) {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

function key(target: Element, name: string) {
    const event = new KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true });
    target.dispatchEvent(event);

    return event;
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(NOW);
    vi.spyOn(document, 'hasFocus').mockReturnValue(true);
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('the visibility toggle', () => {
    it('starts Visible: data-state visible, is_internal = 0', () => {
        const { toggle, isInternal } = mount();

        expect(toggle.dataset.state).toBe('visible');
        expect(isInternal.value).toBe('0');
    });

    it('starts Hidden when a refused post had an internal note: data-state hidden, is_internal = 1', () => {
        const { toggle, isInternal } = mount({ internal: true });

        expect(toggle.dataset.state).toBe('hidden');
        expect(isInternal.value).toBe('1');
    });

    it('toggles on click and keeps the posted value equal to the state', () => {
        const { toggle, isInternal } = mount();

        toggle.click();
        expect(toggle.dataset.state).toBe('hidden');
        expect(isInternal.value).toBe('1');

        toggle.click();
        expect(toggle.dataset.state).toBe('visible');
        expect(isInternal.value).toBe('0');
    });

    it('never sets aria-pressed (the accessible name carries the state), before or after a click', () => {
        const { toggle } = mount();

        expect(toggle.hasAttribute('aria-pressed')).toBe(false);
        toggle.click();
        expect(toggle.hasAttribute('aria-pressed')).toBe(false);
        toggle.click();
        expect(toggle.hasAttribute('aria-pressed')).toBe(false);
    });

    it('says the new state in the polite live region, since the focused name changing is not always re-read', () => {
        const { toggle, announcer } = mount();

        toggle.click();
        expect(announcer.textContent).toBe('Hidden from submitter. This will be an internal note.');
        toggle.click();
        expect(announcer.textContent).toBe('Visible to submitter.');
    });

    it('is a button that never submits the form by itself', () => {
        const { form, toggle } = mount();
        const submitted = vi.fn((event: Event) => event.preventDefault());
        form.addEventListener('submit', submitted);

        toggle.click();

        expect(toggle.type).toBe('button');
        expect(submitted).not.toHaveBeenCalled();
    });

    it('dismisses its tooltip with Escape and shows it again on the next hover', () => {
        const { toggle } = mount();
        const host = toggle.closest<HTMLElement>('[data-tooltip-host]')!;

        key(toggle, 'Escape');
        expect(host.dataset.dismissed).toBe('');

        host.dispatchEvent(new MouseEvent('mouseenter'));
        expect(host.dataset.dismissed).toBeUndefined();
    });
});

describe('the due-date control — inference of the server fields', () => {
    it('starts as keep: no due_at, no pending mark, the effective date shown', () => {
        const { action, at, text, mark, trigger } = mount();

        expect(action.value).toBe('keep');
        expect(at.value).toBe('');
        expect(text.textContent).toBe('05 Nov 2026 10:00');
        expect(mark.hidden).toBe(true);
        expect(trigger.dataset.pending).toBeUndefined();
    });

    it('opens the editor panel beneath the button, which stays put, and puts focus in the field', () => {
        const { trigger, editor, input } = mount();

        trigger.click();

        expect(trigger.hidden).toBe(false);
        expect(editor.hidden).toBe(false);
        expect(trigger.getAttribute('aria-expanded')).toBe('true');
        expect(trigger.closest('[data-due-control]')!.hasAttribute('data-editing')).toBe(true);
        expect(document.activeElement).toBe(input);
        expect(input.value).toBe('2026-11-05T10:00');
    });

    it('closes again, handing focus back to the button, when the button is pressed while the panel is open', () => {
        const { trigger, editor, input, action } = mount();

        trigger.click();
        type(input, '2026-11-08T16:45');
        trigger.click();

        expect(editor.hidden).toBe(true);
        expect(trigger.getAttribute('aria-expanded')).toBe('false');
        expect(action.value).toBe('set');
        expect(document.activeElement).toBe(trigger);
    });

    it('does not let a press on the button pull focus out of the field while the panel is open', () => {
        const { trigger } = mount();
        const closed = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
        trigger.dispatchEvent(closed);
        expect(closed.defaultPrevented).toBe(false);

        trigger.click();
        const open = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
        trigger.dispatchEvent(open);
        expect(open.defaultPrevented).toBe(true);
    });

    it('leaves the panel open, with the reason, when the button is pressed over a value that cannot be taken', () => {
        const { trigger, editor, input, error } = mount();

        trigger.click();
        type(input, '2026-11-03T10:00');
        trigger.click();

        expect(editor.hidden).toBe(false);
        expect(error.hidden).toBe(false);
    });

    it('keeps the panel inside a narrow viewport by shifting and capping it with custom properties', () => {
        const { trigger, editor } = mount();
        Object.defineProperty(document.documentElement, 'clientWidth', {
            value: 360,
            configurable: true,
        });
        editor.getBoundingClientRect = () =>
            ({ left: 180, right: 500, top: 0, bottom: 100, width: 320, height: 100 }) as DOMRect;

        trigger.click();

        expect(editor.style.getPropertyValue('--due-editor-max')).toBe('344px');
        expect(editor.style.getPropertyValue('--due-editor-x')).toBe('-148px');
    });

    it('nudges the panel up by its overhang instead of scrolling the page when the composer is near the bottom of the screen', () => {
        const { trigger, editor } = mount();
        const scroll = vi.fn();
        editor.scrollIntoView = scroll;
        Object.defineProperty(window, 'innerHeight', { value: 800, configurable: true });
        Object.defineProperty(document.documentElement, 'clientWidth', {
            value: 1200,
            configurable: true,
        });
        // Laid over the button it would run to 820, 28px past the bottom edge less the 8px margin.
        const lifted = () => Number.parseFloat(editor.style.getPropertyValue('--due-editor-y'));
        editor.getBoundingClientRect = () =>
            ({
                left: 100,
                right: 420,
                top: 620 + lifted(),
                bottom: 820 + lifted(),
                width: 320,
                height: 200,
            }) as DOMRect;

        trigger.click();

        expect(editor.style.getPropertyValue('--due-editor-y')).toBe('-28px');
        expect(scroll).not.toHaveBeenCalled();
    });

    it('leaves the panel where it lies over the button when it fits, and scrolls only when it fits nowhere', () => {
        const { trigger, editor } = mount();
        const scroll = vi.fn();
        editor.scrollIntoView = scroll;
        Object.defineProperty(window, 'innerHeight', { value: 800, configurable: true });
        editor.getBoundingClientRect = () =>
            ({ left: 100, right: 420, top: 300, bottom: 500, width: 320, height: 200 }) as DOMRect;

        trigger.click();
        expect(editor.style.getPropertyValue('--due-editor-y')).toBe('0px');
        expect(scroll).not.toHaveBeenCalled();

        key(editor, 'Escape');
        // Taller than the room: it is pushed as far as the top margin allows and the browser scrolls the rest into view.
        Object.defineProperty(window, 'innerHeight', { value: 150, configurable: true });
        trigger.click();
        expect(editor.style.getPropertyValue('--due-editor-y')).toBe('-292px');
        expect(scroll).toHaveBeenCalledWith({ block: 'nearest' });
    });

    it('does not move the panel when it already fits', () => {
        const { trigger, editor } = mount();
        Object.defineProperty(document.documentElement, 'clientWidth', {
            value: 1200,
            configurable: true,
        });
        editor.getBoundingClientRect = () =>
            ({ left: 300, right: 620, top: 0, bottom: 100, width: 320, height: 100 }) as DOMRect;

        trigger.click();

        expect(editor.style.getPropertyValue('--due-editor-x')).toBe('0px');
    });

    it('keeps when the date is left unchanged', () => {
        const { trigger, input, action, at, editor } = mount();

        trigger.click();
        key(input, 'Enter');

        expect(editor.hidden).toBe(true);
        expect(action.value).toBe('keep');
        expect(at.value).toBe('');
    });

    it('sets when a new date is entered with Enter, which collapses without submitting the form', () => {
        const { form, trigger, input, action, at, text, mark, editor } = mount();
        const submitted = vi.fn((event: Event) => event.preventDefault());
        form.addEventListener('submit', submitted);

        trigger.click();
        type(input, '2026-11-08T16:45');
        const event = key(input, 'Enter');

        expect(event.defaultPrevented).toBe(true);
        expect(submitted).not.toHaveBeenCalled();
        expect(editor.hidden).toBe(true);
        expect(action.value).toBe('set');
        expect(at.value).toBe('2026-11-08T16:45');
        expect(text.textContent).toBe('08 Nov 2026 16:45');
        expect(mark.hidden).toBe(false);
        expect(document.activeElement).toBe(trigger);
    });

    it('does not collapse while the value is only being changed (the native picker fires change per pick)', () => {
        const { trigger, input, editor, action } = mount();

        trigger.click();
        type(input, '2026-11-08T16:45');
        type(input, '2026-11-09T16:45');
        vi.runOnlyPendingTimers();

        expect(editor.hidden).toBe(false);
        expect(action.value).toBe('keep');
    });

    it('commits and collapses when focus leaves the editor, without stealing focus back', () => {
        const { trigger, input, action, at, editor } = mount();
        const other = document.createElement('button');
        document.body.append(other);

        trigger.click();
        type(input, '2026-11-08T16:45');
        other.focus();
        vi.runOnlyPendingTimers();

        expect(editor.hidden).toBe(true);
        expect(action.value).toBe('set');
        expect(at.value).toBe('2026-11-08T16:45');
        expect(document.activeElement).toBe(other);
    });

    it('does not collapse when focus only moves between the field and a button inside the editor', () => {
        const { trigger, input, editor, restore } = mount({ hasOverride: true });

        trigger.click();
        type(input, '2026-11-08T16:45');
        restore!.focus();
        vi.runOnlyPendingTimers();

        expect(editor.hidden).toBe(false);
        input.focus();
    });

    it('does not collapse because the browser window lost focus (the native picker must not be closed under the user)', () => {
        const { trigger, input, editor } = mount();
        vi.spyOn(document, 'hasFocus').mockReturnValue(false);

        trigger.click();
        type(input, '2026-11-08T16:45');
        input.blur();
        vi.runOnlyPendingTimers();

        expect(editor.hidden).toBe(false);
    });

    it('cancels the edit with Escape: the previous state stands and nothing is pending', () => {
        const { trigger, input, action, at, editor, text } = mount();

        trigger.click();
        type(input, '2026-11-08T16:45');
        const event = key(input, 'Escape');

        expect(event.defaultPrevented).toBe(true);
        expect(editor.hidden).toBe(true);
        expect(action.value).toBe('keep');
        expect(at.value).toBe('');
        expect(text.textContent).toBe('05 Nov 2026 10:00');
        expect(document.activeElement).toBe(trigger);
    });

    it('Escape keeps an earlier pending date rather than clearing it', () => {
        const { trigger, input, action, at } = mount();

        trigger.click();
        type(input, '2026-11-08T16:45');
        key(input, 'Enter');
        trigger.click();
        type(input, '2026-11-20T08:00');
        key(input, 'Escape');

        expect(action.value).toBe('set');
        expect(at.value).toBe('2026-11-08T16:45');
    });

    it('clears the pending change when the operator returns to the original date', () => {
        const { trigger, input, action, at, mark } = mount();

        trigger.click();
        type(input, '2026-11-08T16:45');
        key(input, 'Enter');
        expect(action.value).toBe('set');

        trigger.click();
        type(input, '2026-11-05T10:00');
        key(input, 'Enter');

        expect(action.value).toBe('keep');
        expect(at.value).toBe('');
        expect(mark.hidden).toBe(true);
    });

    it('never sends conflicting fields: restore carries no due_at, keep carries no due_at', () => {
        const { trigger, input, action, at, restore } = mount({
            hasOverride: true,
            original: '2026-11-06T09:00',
        });

        trigger.click();
        type(input, '2026-11-08T16:45');
        key(input, 'Enter');
        expect(action.value).toBe('set');
        expect(at.value).toBe('2026-11-08T16:45');

        trigger.click();
        restore!.click();

        expect(action.value).toBe('restore');
        expect(at.value).toBe('');
    });

    it('shows the SLA date for a pending restore and offers no second restore', () => {
        const { trigger, restore, text, mark, editor } = mount({
            hasOverride: true,
            original: '2026-11-06T09:00',
        });

        trigger.click();
        restore!.click();

        expect(editor.hidden).toBe(true);
        expect(text.textContent).toBe('05 Nov 2026 10:00');
        expect(mark.hidden).toBe(false);
        expect(restore!.hidden).toBe(true);
    });

    it('lets the operator undo a pending change', () => {
        const { trigger, input, action, discard, at, actions, editor } = mount();

        expect(actions.hidden).toBe(true);
        trigger.click();
        type(input, '2026-11-08T16:45');
        key(input, 'Enter');
        trigger.click();
        expect(discard.hidden).toBe(false);
        expect(actions.hidden).toBe(false);
        discard.click();

        expect(action.value).toBe('keep');
        expect(at.value).toBe('');
        expect(editor.hidden).toBe(true);
        expect(actions.hidden).toBe(true);
        expect(document.activeElement).toBe(trigger);
    });

    it('offers Restore SLA while an override exists and Undo change only while something is pending: two distinct actions', () => {
        const { trigger, input, restore, discard, actions } = mount({
            hasOverride: true,
            original: '2026-11-06T09:00',
        });

        expect(actions.hidden).toBe(false);
        expect(restore!.hidden).toBe(false);
        expect(discard.hidden).toBe(true);

        trigger.click();
        restore!.click();
        trigger.click();
        // A pending restore: nothing left to restore, but the pending change can be undone.
        expect(restore!.hidden).toBe(true);
        expect(discard.hidden).toBe(false);
        expect(actions.hidden).toBe(false);

        discard.click();
        expect(restore!.hidden).toBe(false);
        expect(discard.hidden).toBe(true);
        expect(input.value).not.toBe('');
    });

    it('undoing a pending date and restoring the SLA end in different states', () => {
        const { trigger, input, action, discard, restore } = mount({
            hasOverride: true,
            original: '2026-11-06T09:00',
        });

        trigger.click();
        type(input, '2026-11-08T16:45');
        key(input, 'Enter');
        trigger.click();
        discard.click();
        expect(action.value).toBe('keep');

        trigger.click();
        restore!.click();
        expect(action.value).toBe('restore');
    });

    it('refills a refused post: a pending set stays pending', () => {
        const { action, at, text, mark, trigger } = mount({
            action: 'set',
            at: '2026-11-08T16:45',
        });

        expect(action.value).toBe('set');
        expect(at.value).toBe('2026-11-08T16:45');
        expect(text.textContent).toBe('08 Nov 2026 16:45');
        expect(mark.hidden).toBe(false);
        expect(trigger.dataset.pending).toBe('set');
    });

    it('refills a refused post: a pending restore stays pending', () => {
        const { action, text, trigger } = mount({
            hasOverride: true,
            original: '2026-11-06T09:00',
            action: 'restore',
        });

        expect(action.value).toBe('restore');
        expect(text.textContent).toBe('05 Nov 2026 10:00');
        expect(trigger.dataset.pending).toBe('restore');
    });

    it('does not trust an unparseable refill', () => {
        const { action, at } = mount({ action: 'set', at: '2026-02-31T10:00' });

        expect(action.value).toBe('keep');
        expect(at.value).toBe('');
    });
});

describe('the due-date control — unfinished or refused values are never dropped silently', () => {
    it('keeps the editor open with a message for a date in the past', () => {
        const { trigger, input, editor, error, action } = mount();

        trigger.click();
        type(input, '2026-11-03T10:00');
        const event = key(input, 'Enter');

        expect(event.defaultPrevented).toBe(true);
        expect(editor.hidden).toBe(false);
        expect(error.hidden).toBe(false);
        expect(error.textContent).toContain('future');
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(action.value).toBe('keep');
    });

    it('keeps the editor open for a date beyond the allowed horizon', () => {
        const { trigger, input, editor, error } = mount();

        trigger.click();
        type(input, '2027-01-01T10:00');
        key(input, 'Enter');

        expect(editor.hidden).toBe(false);
        expect(error.textContent).toContain('30 days');
    });

    it('keeps the editor open for a half-typed value (the browser reports it as empty with badInput)', () => {
        const { trigger, input, editor, error } = mount();

        trigger.click();
        Object.defineProperty(input, 'validity', { value: { badInput: true }, configurable: true });
        input.value = '';
        key(input, 'Enter');

        expect(editor.hidden).toBe(false);
        expect(error.textContent).toContain('complete');
    });

    it('stops a post while the editor holds a value that cannot be taken, and says why', () => {
        const { form, trigger, input, error } = mount();
        const submitted = vi.fn();
        form.addEventListener('submit', submitted);

        trigger.click();
        type(input, '2026-11-03T10:00');
        const event = new Event('submit', { bubbles: true, cancelable: true });
        form.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(error.hidden).toBe(false);
        expect(document.activeElement).toBe(input);
    });

    it('finishes a valid value when the post is made with the editor still open', () => {
        const { form, trigger, input, action, at } = mount();

        trigger.click();
        type(input, '2026-11-08T16:45');
        const event = new Event('submit', { bubbles: true, cancelable: true });
        form.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(action.value).toBe('set');
        expect(at.value).toBe('2026-11-08T16:45');
    });

    it('treats a deliberately emptied field as "no change"', () => {
        const { trigger, input, action, editor } = mount();

        trigger.click();
        type(input, '');
        key(input, 'Enter');

        expect(editor.hidden).toBe(true);
        expect(action.value).toBe('keep');
    });
});

describe('the due-date control — review hardening', () => {
    it('keeps a pending restore when the override date is the same minute as the SLA date', () => {
        const { trigger, input, action, restore } = mount({
            hasOverride: true,
            original: '2026-11-05T10:00',
            sla: '2026-11-05T10:00',
        });

        trigger.click();
        restore!.click();
        expect(action.value).toBe('restore');

        trigger.click();
        key(input, 'Enter');

        expect(action.value).toBe('restore');
    });

    it('treats an emptied field as "no change" for a pending restore too', () => {
        const { trigger, input, action, restore } = mount({
            hasOverride: true,
            original: '2026-11-06T09:00',
        });

        trigger.click();
        restore!.click();
        trigger.click();
        type(input, '');
        key(input, 'Enter');

        expect(action.value).toBe('keep');
    });

    it('keeps focus in the field when the press lands on anything inside the editor but the field itself', () => {
        const { trigger, input, editor } = mount({ hasOverride: true });
        const label = document.createElement('span');
        editor.append(label);

        trigger.click();
        const onLabel = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
        label.dispatchEvent(onLabel);
        const onField = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
        input.dispatchEvent(onField);

        expect(onLabel.defaultPrevented).toBe(true);
        expect(onField.defaultPrevented).toBe(false);
    });

    it('commits at once but waits for the press to end before collapsing, so Post Update does not move under the pointer', () => {
        const { trigger, input, action, at, editor } = mount();
        const post = document.createElement('button');
        document.body.append(post);

        trigger.click();
        type(input, '2026-11-08T16:45');
        document.dispatchEvent(new Event('pointerdown', { bubbles: true }));
        post.focus();
        vi.runOnlyPendingTimers();

        // Committed, still laid out as it was.
        expect(action.value).toBe('set');
        expect(at.value).toBe('2026-11-08T16:45');
        expect(editor.hidden).toBe(false);

        document.dispatchEvent(new Event('pointerup', { bubbles: true }));
        vi.advanceTimersByTime(60);

        expect(editor.hidden).toBe(true);
    });

    it('shows the exact relative time of the field inside the editor, for touch screens without a tooltip', () => {
        const { trigger, input } = mount();
        const relative = document.querySelector<HTMLElement>('[data-due-relative]')!;

        trigger.click();
        expect(relative.textContent).toBe('Due in 1 day');

        type(input, '2026-11-04T12:00');
        expect(relative.textContent).toBe('Due in 2 hours');

        type(input, '2026-11-04T09:30');
        expect(relative.textContent).toBe('Overdue by 30 minutes');

        type(input, '');
        expect(relative.textContent).toBe('');
    });

    it('dismisses a hover-only tooltip with Escape while focus is elsewhere', () => {
        const { toggle } = mount();
        const host = toggle.closest<HTMLElement>('[data-tooltip-host]')!;

        host.dispatchEvent(new MouseEvent('mouseenter'));
        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(host.dataset.dismissed).toBe('');

        host.dispatchEvent(new MouseEvent('mouseleave'));
        expect(host.dataset.dismissed).toBeUndefined();
    });
});

describe('the due-date control — a ticket with no due date', () => {
    it('is neutral and says "No due date"', () => {
        const { trigger, text, tip } = mount({ original: null, sla: null });

        expect(trigger.dataset.urgency).toBe('none');
        expect(text.textContent).toBe('No due date');
        expect(tip.textContent).toBe('No due date');
    });

    it('can still be given a date', () => {
        const { trigger, input, action, at } = mount({ original: null, sla: null });

        trigger.click();
        expect(input.value).toBe('');
        type(input, '2026-11-08T16:45');
        key(input, 'Enter');

        expect(action.value).toBe('set');
        expect(at.value).toBe('2026-11-08T16:45');
    });
});

describe('the due-date control — urgency and the live clock', () => {
    it('is green with more than 4 hours left, with the exact wording in the description', () => {
        const { trigger, tip } = mount();

        expect(trigger.dataset.urgency).toBe('ok');
        expect(tip.textContent).toBe('Due in 1 day');
    });

    it('turns yellow at exactly 4 hours, red after more than 1 hour overdue, while the page stays open', () => {
        const { trigger, tip } = mount();

        vi.advanceTimersByTime(20 * HOUR - 30_000); // 4 h 30 s left
        expect(trigger.dataset.urgency).toBe('ok');

        vi.advanceTimersByTime(30_000); // exactly 4 h left
        expect(trigger.dataset.urgency).toBe('soon');
        expect(tip.textContent).toBe('Due in 4 hours');

        vi.advanceTimersByTime(4 * HOUR + HOUR); // exactly 1 h overdue
        expect(trigger.dataset.urgency).toBe('soon');
        expect(tip.textContent).toBe('Overdue by 1 hour');

        vi.advanceTimersByTime(30_000);
        expect(trigger.dataset.urgency).toBe('late');
    });

    it('colours a pending date by the pending deadline before it is posted', () => {
        const { trigger, input, tip } = mount();

        trigger.click();
        type(input, '2026-11-04T12:00'); // 2 h from now
        key(input, 'Enter');

        expect(trigger.dataset.urgency).toBe('soon');
        expect(tip.textContent).toContain('Due in 2 hours');
        expect(tip.textContent).toContain('Pending');
    });

    it('uses the server clock, not a device clock that runs ahead', () => {
        // The deadline is 6 hours away on the server's clock (green). A device 3 hours ahead would see only 3 hours (yellow).
        vi.setSystemTime(NOW + 3 * HOUR);
        const { trigger, tip } = mount({ original: '2026-11-04T16:00', sla: '2026-11-04T16:00' });

        expect(trigger.dataset.urgency).toBe('ok');
        expect(tip.textContent).toBe('Due in 6 hours');
    });

    it('uses the server clock, not a device clock that runs behind', () => {
        // The deadline is 3 hours away on the server's clock (yellow). A device 3 hours behind would see 6 hours (green).
        vi.setSystemTime(NOW - 3 * HOUR);
        const { trigger, tip } = mount({ original: '2026-11-04T13:00', sla: '2026-11-04T13:00' });

        expect(trigger.dataset.urgency).toBe('soon');
        expect(tip.textContent).toBe('Due in 3 hours');
    });
});

describe('the due-date control — a pending change that the open editor must not disturb', () => {
    /** A legacy ticket: an override date, but no SLA deadline to go back to. A pending restore shows "No due date". */
    function legacyRestore() {
        const parts = mount({ hasOverride: true, original: '2026-11-06T09:00', sla: null });

        parts.trigger.click();
        parts.restore!.click();

        expect(parts.action.value).toBe('restore');
        expect(parts.text.textContent).toBe('No due date');

        parts.trigger.click();
        expect(parts.input.value).toBe('');

        return parts;
    }

    function expectRestoreStands({ action, at, mark, text }: ReturnType<typeof mount>) {
        expect(action.value).toBe('restore');
        expect(at.value).toBe('');
        expect(mark.hidden).toBe(false);
        expect(text.textContent).toBe('No due date');
    }

    it('keeps a pending restore with no SLA deadline when the untouched editor is closed with Enter', () => {
        const parts = legacyRestore();

        key(parts.input, 'Enter');

        expect(parts.editor.hidden).toBe(true);
        expectRestoreStands(parts);
    });

    it('keeps a pending restore with no SLA deadline when focus leaves the untouched editor', () => {
        const parts = legacyRestore();
        const other = document.createElement('button');
        document.body.append(other);

        other.focus();
        vi.runOnlyPendingTimers();

        expect(parts.editor.hidden).toBe(true);
        expectRestoreStands(parts);
    });

    it('keeps a pending restore with no SLA deadline when the form is posted with the untouched editor open', () => {
        const parts = legacyRestore();
        const event = new Event('submit', { bubbles: true, cancelable: true });

        parts.form.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expectRestoreStands(parts);
    });

    it('keeps a pending restore with no SLA deadline when the date button is pressed over the untouched editor', () => {
        const parts = legacyRestore();

        parts.trigger.click();

        expect(parts.editor.hidden).toBe(true);
        expectRestoreStands(parts);
    });

    it('does not take a half-typed field for an untouched one: the reason is shown and nothing changes', () => {
        const parts = legacyRestore();

        // The browser reports a half-typed datetime-local as an empty value with badInput.
        Object.defineProperty(parts.input, 'validity', {
            value: { badInput: true },
            configurable: true,
        });
        key(parts.input, 'Enter');

        expect(parts.editor.hidden).toBe(false);
        expect(parts.error.hidden).toBe(false);
        expect(parts.error.textContent).toContain('complete');
        expect(parts.input.getAttribute('aria-invalid')).toBe('true');
        expectRestoreStands(parts);
    });

    it('still clears a pending date when the operator empties the field on purpose', () => {
        const { trigger, input, action, at, mark, editor } = mount();

        trigger.click();
        type(input, '2026-11-08T16:45');
        key(input, 'Enter');
        expect(action.value).toBe('set');

        trigger.click();
        expect(input.value).toBe('2026-11-08T16:45');
        type(input, '');
        key(input, 'Enter');

        expect(editor.hidden).toBe(true);
        expect(action.value).toBe('keep');
        expect(at.value).toBe('');
        expect(mark.hidden).toBe(true);
    });

    it('keeps a pending restore on a ticket that has an SLA date through an untouched Enter, whatever the override date', () => {
        const { trigger, input, action, at, restore, text } = mount({
            hasOverride: true,
            original: '2026-11-06T09:00',
        });

        trigger.click();
        restore!.click();
        trigger.click();
        expect(input.value).toBe('2026-11-05T10:00');
        key(input, 'Enter');

        expect(action.value).toBe('restore');
        expect(at.value).toBe('');
        expect(text.textContent).toBe('05 Nov 2026 10:00');
    });

    it('keeps a pending restore on a ticket that has an SLA date when focus leaves the untouched editor', () => {
        const { trigger, restore, action } = mount({
            hasOverride: true,
            original: '2026-11-06T09:00',
        });
        const other = document.createElement('button');
        document.body.append(other);

        trigger.click();
        restore!.click();
        trigger.click();
        other.focus();
        vi.runOnlyPendingTimers();

        expect(action.value).toBe('restore');
    });

    it('still turns a pending restore into a set when a different date is typed', () => {
        const { trigger, input, restore, action, at } = mount({
            hasOverride: true,
            original: '2026-11-06T09:00',
        });

        trigger.click();
        restore!.click();
        trigger.click();
        type(input, '2026-11-08T16:45');
        key(input, 'Enter');

        expect(action.value).toBe('set');
        expect(at.value).toBe('2026-11-08T16:45');
    });
});

describe('the due-date control — a value that cannot be taken keeps the operator in the field', () => {
    const PAST = '2026-11-03T10:00';

    it('keeps focus in the field, marked invalid, when Enter is pressed over a value that cannot be taken', () => {
        const { trigger, input, editor, error } = mount();

        trigger.click();
        type(input, PAST);
        key(input, 'Enter');

        expect(editor.hidden).toBe(false);
        expect(error.hidden).toBe(false);
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(document.activeElement).toBe(input);
    });

    it('returns focus to the field, and keeps the panel open, when focus moves to the covered date button over such a value', () => {
        const { trigger, input, editor, error, action } = mount();

        trigger.click();
        type(input, PAST);
        trigger.focus();
        expect(document.activeElement).toBe(trigger);
        vi.runOnlyPendingTimers();

        expect(editor.hidden).toBe(false);
        expect(error.hidden).toBe(false);
        expect(document.activeElement).toBe(input);
        expect(action.value).toBe('keep');
    });

    it('does not take focus back from a click elsewhere on the page, and leaves the panel open with the reason', () => {
        const { trigger, input, editor, error } = mount();
        const other = document.createElement('button');
        document.body.append(other);

        trigger.click();
        type(input, PAST);
        other.focus();
        vi.runOnlyPendingTimers();

        expect(editor.hidden).toBe(false);
        expect(error.hidden).toBe(false);
        expect(document.activeElement).toBe(other);
    });

    it('keeps focus in the field when the date button is pressed over such a value', () => {
        const { trigger, input, editor, error } = mount();

        trigger.click();
        type(input, PAST);
        trigger.focus();
        trigger.click();

        expect(editor.hidden).toBe(false);
        expect(error.hidden).toBe(false);
        expect(document.activeElement).toBe(input);
    });

    it('still commits and closes when a valid value is left for somewhere else', () => {
        const { trigger, input, editor, action, at } = mount();
        const other = document.createElement('button');
        document.body.append(other);

        trigger.click();
        type(input, '2026-11-08T16:45');
        other.focus();
        vi.runOnlyPendingTimers();

        expect(editor.hidden).toBe(true);
        expect(action.value).toBe('set');
        expect(at.value).toBe('2026-11-08T16:45');
        expect(document.activeElement).toBe(other);
    });

    it('cancels the edit with Escape pressed on the covered date button: the earlier pending change stands', () => {
        const { trigger, input, editor, action, at, text } = mount();

        trigger.click();
        type(input, '2026-11-08T16:45');
        key(input, 'Enter');

        trigger.click();
        type(input, PAST);
        key(input, 'Enter');
        trigger.focus();
        const event = key(trigger, 'Escape');

        expect(event.defaultPrevented).toBe(true);
        expect(editor.hidden).toBe(true);
        expect(trigger.getAttribute('aria-expanded')).toBe('false');
        expect(action.value).toBe('set');
        expect(at.value).toBe('2026-11-08T16:45');
        expect(text.textContent).toBe('08 Nov 2026 16:45');
        expect(document.activeElement).toBe(trigger);
    });

    it('does nothing for Escape on the date button while the panel is closed', () => {
        const { trigger, editor, action } = mount();

        const event = key(trigger, 'Escape');

        expect(event.defaultPrevented).toBe(false);
        expect(editor.hidden).toBe(true);
        expect(action.value).toBe('keep');
    });
});

describe('the due-date control — a refused post marks the date button invalid', () => {
    it('keeps the mark while the operator only looks at, or cancels out of, the editor', () => {
        const { trigger, input } = mount({
            serverInvalid: true,
            action: 'set',
            at: '2026-11-08T16:45',
        });

        trigger.click();
        key(input, 'Escape');

        expect(trigger.getAttribute('aria-invalid')).toBe('true');
    });

    it('clears the mark when the pending change is undone', () => {
        const { trigger, discard, action } = mount({
            serverInvalid: true,
            action: 'set',
            at: '2026-11-08T16:45',
        });

        expect(trigger.getAttribute('aria-invalid')).toBe('true');
        trigger.click();
        discard.click();

        expect(action.value).toBe('keep');
        expect(trigger.hasAttribute('aria-invalid')).toBe(false);
    });

    it('clears the mark when the SLA deadline is restored instead', () => {
        const { trigger, restore, action } = mount({
            serverInvalid: true,
            hasOverride: true,
            original: '2026-11-06T09:00',
            action: 'set',
            at: '2026-11-08T16:45',
        });

        expect(trigger.getAttribute('aria-invalid')).toBe('true');
        trigger.click();
        restore!.click();

        expect(action.value).toBe('restore');
        expect(trigger.hasAttribute('aria-invalid')).toBe(false);
    });
});
