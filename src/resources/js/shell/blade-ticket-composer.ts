/**
 * Release 1.0 (HD-C1) — runtime behaviour for the operator Helpdesk composer on the Blade ticket page.
 *
 * **It owns presentation and inference, never rules.** Laravel still authorizes the reply and the due-date change, validates the
 * date, and applies reply + change in one transaction. This file only:
 *
 * - flips the visibility toggle and the hidden `is_internal` input it stands for (pressed = Hidden = `1`);
 * - turns what the operator did with the due-date control into the EXISTING `due_action` / `due_at` fields:
 *   nothing → `keep` (and no `due_at`); a different date → `set` + that wall-clock text; the explicit button → `restore`;
 * - draws the effective or pending date with its urgency, and keeps the urgency current while the page stays open;
 * - opens the editor as a small panel anchored beneath the date button (it never resizes or moves the joined controls) and
 *   keeps that panel inside the viewport.
 *
 * Nothing is saved here: no fetch, no endpoint. A change stays pending in the two hidden inputs until the operator posts the
 * update, and a refused post is refilled from `old()` by the Blade view, which this file reads back on load.
 *
 * Time. A date the operator types is wall-clock text in the APPLICATION's zone (`data-timezone`), never the browser's; see
 * `lib/due-deadline.ts`. "Now" is the browser clock corrected by the server's clock at page load, so a wrong device clock
 * cannot shift a threshold.
 */

import {
    formatWall,
    isWallClock,
    relativeLabel,
    type Urgency,
    urgencyFor,
    wallToEpoch,
} from '@/lib/due-deadline';

/** How often the urgency is re-read while the page is open. The thresholds are minutes wide, so this is plenty. */
const REFRESH_MS = 30_000;

// ── Tooltips: Escape dismisses (WCAG 1.4.13); hover and focus show, in CSS ────────────────────────────

function initTooltips(root: ParentNode): void {
    for (const host of root.querySelectorAll<HTMLElement>('[data-tooltip-host]')) {
        const dismiss = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && !host.hasAttribute('data-editing')) {
                host.dataset.dismissed = '';
            }
        };

        // Focus inside the host: its own keydown. Pointer only (focus is elsewhere, e.g. in the message): the document's, for as
        // long as the pointer is over it, so Escape can always put a tooltip away without moving focus.
        host.addEventListener('keydown', dismiss);
        host.addEventListener('mouseenter', () => {
            delete host.dataset.dismissed;
            document.addEventListener('keydown', dismiss);
        });
        host.addEventListener('mouseleave', () => {
            delete host.dataset.dismissed;
            document.removeEventListener('keydown', dismiss);
        });
        host.addEventListener('focusout', () => {
            delete host.dataset.dismissed;
        });
    }
}

// ── Visibility toggle ────────────────────────────────────────────────────────────────────────────────

function initVisibility(root: ParentNode): void {
    for (const host of root.querySelectorAll<HTMLElement>('[data-visibility-toggle]')) {
        const button = host.querySelector<HTMLButtonElement>('[data-visibility-button]');
        const input = host.querySelector<HTMLInputElement>('[data-visibility-input]');

        if (!button || !input) {
            continue;
        }

        // The hidden input is the value that is posted; the button must show it, whatever the markup said.
        const apply = (hidden: boolean) => {
            button.setAttribute('aria-pressed', hidden ? 'true' : 'false');
            input.value = hidden ? '1' : '0';
        };

        apply(input.value === '1');

        button.addEventListener('click', () => {
            apply(button.getAttribute('aria-pressed') !== 'true');
        });
    }
}

// ── Due-date control ─────────────────────────────────────────────────────────────────────────────────

type Pending = { kind: 'set'; wall: string; epoch: number } | { kind: 'restore' } | null;

interface Dated {
    wall: string;
    epoch: number;
}

function numberOrNull(value: string | undefined): number | null {
    if (value === undefined || value === '') {
        return null;
    }

    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : null;
}

function datedFrom(wall: string | undefined, epoch: string | undefined): Dated | null {
    const ms = numberOrNull(epoch);

    return wall && ms !== null ? { wall, epoch: ms } : null;
}

function initDueDate(root: ParentNode): void {
    const control = root.querySelector<HTMLElement>('[data-due-control]');

    if (!control) {
        return;
    }

    const trigger = control.querySelector<HTMLButtonElement>('[data-due-trigger]');
    const editor = control.querySelector<HTMLElement>('[data-due-editor]');
    const input = control.querySelector<HTMLInputElement>('[data-due-input]');
    const actionField = control.querySelector<HTMLInputElement>('[data-due-action]');
    const atField = control.querySelector<HTMLInputElement>('[data-due-at]');
    const text = control.querySelector<HTMLElement>('[data-due-text]');
    const zoneText = control.querySelector<HTMLElement>('[data-due-zone]');
    const pendingMark = control.querySelector<HTMLElement>('[data-due-pending-mark]');
    const tip = control.querySelector<HTMLElement>('[data-due-tip]');
    const relative = control.querySelector<HTMLElement>('[data-due-relative]');
    const restore = control.querySelector<HTMLButtonElement>('[data-due-restore]');
    const discard = control.querySelector<HTMLButtonElement>('[data-due-discard]');
    const actions = control.querySelector<HTMLElement>('[data-due-actions]');

    if (!trigger || !editor || !input || !actionField || !atField || !text || !tip) {
        return;
    }

    const clientError = document.getElementById('due-date-editor-message');
    const announcer = document.querySelector<HTMLElement>('[data-composer-announcer]');

    const timeZone = control.dataset.timezone || 'UTC';
    const zone = control.dataset.zoneLabel || timeZone;
    const maxDays = numberOrNull(control.dataset.maxDays) ?? 30;
    const original = datedFrom(control.dataset.originalWall, control.dataset.originalEpoch);
    const sla = datedFrom(control.dataset.slaWall, control.dataset.slaEpoch);
    const slaText = control.dataset.slaText || 'none set';

    // The server's clock at render, against this device's: `now()` below is the server's time, whatever the device says.
    const serverNow = numberOrNull(control.dataset.serverNow);
    const offset = serverNow === null ? 0 : serverNow - Date.now();
    const now = () => Date.now() + offset;

    let pending: Pending = null;
    let editing = false;
    /** A mouse or touch press is in progress, and an editor commit that wants to collapse must wait for it to end. */
    let pressed = false;
    let closeAfterPress = false;
    /** The pending change as it stood when the editor opened, so Escape can put it back. */
    let before: Pending = null;

    // Read back what a refused post refilled.
    if (actionField.value === 'restore') {
        pending = { kind: 'restore' };
    } else if (actionField.value === 'set' && isWallClock(atField.value)) {
        const epoch = wallToEpoch(atField.value, timeZone);

        if (epoch !== null) {
            pending = { kind: 'set', wall: atField.value, epoch };
        }
    }

    const shown = (): Dated | null => {
        if (pending?.kind === 'set') {
            return pending;
        }

        return pending?.kind === 'restore' ? sla : original;
    };

    const sameAsOriginal = (wall: string) => original !== null && original.wall === wall;

    function announce(message: string): void {
        if (announcer) {
            // Clear first so an identical message is announced again.
            announcer.textContent = '';
            announcer.textContent = message;
        }
    }

    function setError(message: string | null): void {
        if (clientError) {
            clientError.hidden = message === null;
            clientError.textContent = message ?? '';
        }

        if (message === null) {
            input?.removeAttribute('aria-invalid');
        } else {
            input?.setAttribute('aria-invalid', 'true');
        }
    }

    /** Write the inferred server fields: only the pair the contract needs, never a conflicting due_at. */
    function syncFields(): void {
        actionField!.value = pending?.kind ?? 'keep';
        atField!.value = pending?.kind === 'set' ? pending.wall : '';
    }

    function describePending(): string | null {
        if (pending?.kind === 'set') {
            return `Due date will change to ${formatWall(pending.wall)} ${zone} when you post the update.`;
        }

        if (pending?.kind === 'restore') {
            return `Due date will return to the SLA deadline (${slaText}${sla ? ` ${zone}` : ''}) when you post the update.`;
        }

        return null;
    }

    function render(): void {
        const date = shown();
        const remaining = date === null ? null : date.epoch - now();
        const urgency: Urgency = urgencyFor(remaining);

        trigger!.dataset.urgency = urgency;
        text!.textContent = date ? formatWall(date.wall) : 'No due date';

        if (zoneText) {
            zoneText.hidden = date === null;
        }

        if (pending) {
            trigger!.dataset.pending = pending.kind;
        } else {
            delete trigger!.dataset.pending;
        }

        if (pendingMark) {
            pendingMark.hidden = pending === null;
        }

        // The exact relative time is the description, whatever the colour; a pending change says when it takes effect.
        const note = describePending();

        tip!.textContent = note
            ? `${relativeLabel(remaining)}. Pending: applied only when you post the update, and your message is recorded as the reason.`
            : relativeLabel(remaining);

        if (restore) {
            restore.hidden = pending?.kind === 'restore';
        }

        if (discard) {
            discard.hidden = pending === null;
        }

        if (actions) {
            // The footer is only there while it has something to offer.
            actions.hidden = (restore?.hidden ?? true) && (discard?.hidden ?? true);
        }

        syncFields();
    }

    /** In the editor, on a touch screen where the tooltip is out of reach: the exact relative time of what the field holds. */
    function describeEditorValue(): void {
        if (!relative) {
            return;
        }

        const wall = input!.value;
        const epoch = wall === '' ? null : wallToEpoch(wall, timeZone);

        relative.textContent = epoch === null ? '' : relativeLabel(epoch - now());
    }

    /**
     * Keep the panel inside the viewport. It is `absolute` under the button, so on a wide screen it simply sits there; on a narrow one
     * it is shifted left by exactly as much as it overhangs, and capped to the viewport's width. Only two custom properties are set:
     * the look is static classes. If there is no room beneath the button but there is above it, the panel opens upward instead of
     * making the page scroll (`data-placement="top"`).
     */
    function place(): void {
        const margin = 8;
        const viewport = document.documentElement.clientWidth;

        delete editor!.dataset.placement;

        editor!.style.setProperty('--due-editor-max', `${Math.max(viewport - 2 * margin, 0)}px`);
        editor!.style.setProperty('--due-editor-x', '0px');

        const box = editor!.getBoundingClientRect();
        let shift = 0;

        if (box.right > viewport - margin) {
            shift = viewport - margin - box.right;
        }

        if (box.left + shift < margin) {
            shift = margin - box.left;
        }

        editor!.style.setProperty('--due-editor-x', `${shift}px`);

        const anchor = control!.getBoundingClientRect();
        const room = {
            below: window.innerHeight - anchor.bottom - margin,
            above: anchor.top - margin,
        };

        if (box.height > room.below && room.above >= box.height) {
            editor!.dataset.placement = 'top';
        }
    }

    function openEditor(): void {
        editing = true;
        before = pending;
        control!.setAttribute('data-editing', '');
        delete control!.dataset.dismissed;
        trigger!.setAttribute('aria-expanded', 'true');
        editor!.hidden = false;
        input!.value = shown()?.wall ?? '';
        setError(null);
        describeEditorValue();
        place();
        // The panel is anchored, not in the flow: focusing must not scroll the page unless the panel still runs off the screen
        // (neither below nor above the button had room for it).
        input!.focus({ preventScroll: true });

        const box = editor!.getBoundingClientRect();

        if (box.bottom > window.innerHeight || box.top < 0) {
            editor!.scrollIntoView({ block: 'nearest' });
        }
    }

    function closeEditor(returnFocus: boolean): void {
        editing = false;
        closeAfterPress = false;
        control!.removeAttribute('data-editing');
        editor!.hidden = true;
        trigger!.setAttribute('aria-expanded', 'false');
        setError(null);
        render();

        if (returnFocus) {
            trigger!.focus();
        }
    }

    /**
     * Try to take the editor's value as the pending change. Returns true when the editor may close. An incomplete or
     * refused value keeps the editor open with the reason; it is never dropped silently.
     */
    function commit(): boolean {
        const value = input!.value;

        if (value === '') {
            if (input!.validity.badInput) {
                setError('Enter a complete date and time, or press Escape to cancel.');

                return false;
            }

            // An emptied field is a deliberate "no change": whatever was pending is cleared and the original date stands again.
            if (pending !== null) {
                pending = null;
                announce('Due date change cleared.');
            }

            return true;
        }

        const epoch = wallToEpoch(value, timeZone);

        if (epoch === null) {
            setError(`Enter a real date and time in ${zone}.`);

            return false;
        }

        if (shown()?.wall === value) {
            // Untouched since the editor opened (a pending date, or the SLA date of a pending restore): keep it as it is. This comes
            // first so a pending restore is never read as "back to the original" when the two dates happen to be the same minute.
            return true;
        }

        if (sameAsOriginal(value)) {
            // Back to the date it already has: nothing to change, so nothing stays pending.
            if (pending !== null) {
                announce('Due date change cleared.');
            }

            pending = null;

            return true;
        }

        if (epoch <= now()) {
            setError('The new due date must be in the future.');

            return false;
        }

        if (epoch > now() + maxDays * 24 * 60 * 60 * 1000) {
            setError(`The new due date can be at most ${maxDays} days ahead.`);

            return false;
        }

        pending = { kind: 'set', wall: value, epoch };
        announce(`Due date will change to ${formatWall(value)} ${zone} when you post the update.`);

        return true;
    }

    // The button stays in place while the panel is open and works as its toggle: a click takes what the field holds (or, if that
    // cannot be taken, leaves the panel open with the reason) and closes it.
    trigger.addEventListener('click', () => {
        if (!editing) {
            openEditor();

            return;
        }

        if (commit()) {
            closeEditor(true);
        }
    });

    // Pressing the button while the panel is open must not pull focus out of the field first (which would commit and collapse the
    // panel before the click arrives, only for the click to reopen it).
    trigger.addEventListener('mousedown', (event) => {
        if (editing) {
            event.preventDefault();
        }
    });

    window.addEventListener('resize', () => {
        if (editing) {
            place();
        }
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            // Finish the local edit; never submit the whole form from here.
            event.preventDefault();

            if (commit()) {
                closeEditor(true);
            }
        }
    });

    // The editor closes on Escape from anywhere inside it (the input or one of its buttons).
    editor.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            pending = before;
            closeEditor(true);
        }
    });

    input.addEventListener('input', describeEditorValue);

    // Leaving the editor commits it. A press inside the editor that is not on the field (a button, the zone label, padding) is not
    // leaving: its mousedown keeps focus on the field, so Safari, which does not focus buttons on click, cannot close the editor
    // before the click lands. Switching windows does not count either: the native picker's own popup must not close it under the user.
    editor.addEventListener('mousedown', (event) => {
        if (event.target !== input) {
            event.preventDefault();
        }
    });

    // The editor collapses to a shorter control. If that happened between pressing and releasing the mouse on "Post Update", the
    // button would move out from under the pointer and the click would be lost. So a value is committed at once, but the layout
    // only changes after the press is over.
    const released = () => {
        pressed = false;

        if (closeAfterPress && editing) {
            closeAfterPress = false;
            closeEditor(false);
        }
    };

    document.addEventListener(
        'pointerdown',
        () => {
            pressed = true;
        },
        true,
    );
    document.addEventListener('pointerup', () => window.setTimeout(released, 50), true);
    document.addEventListener('pointercancel', released, true);

    editor.addEventListener('focusout', () => {
        window.setTimeout(() => {
            if (!editing || editor.contains(document.activeElement) || !document.hasFocus()) {
                return;
            }

            if (!commit()) {
                return;
            }

            // What will be posted is settled now, whether or not the layout has caught up.
            syncFields();

            if (pressed) {
                closeAfterPress = true;
            } else {
                closeEditor(false);
            }
        }, 0);
    });

    // Posting with the editor still open and its value unfinished must not silently drop it: finish it, or stop and say why.
    control.closest('form')?.addEventListener('submit', (event) => {
        if (!editing) {
            return;
        }

        if (commit()) {
            closeEditor(false);
        } else {
            event.preventDefault();
            input.focus();
        }
    });

    restore?.addEventListener('click', () => {
        pending = { kind: 'restore' };
        announce(`Due date will return to the SLA deadline when you post the update.`);
        closeEditor(true);
    });

    discard?.addEventListener('click', () => {
        pending = null;
        announce('Due date change cleared.');
        closeEditor(true);
    });

    render();

    // Keep the urgency honest while the page stays open. Cheap: one text/attribute pass, only while the tab is visible.
    const refresh = () => {
        if (!document.hidden && !editing) {
            render();
        }
    };

    window.setInterval(refresh, REFRESH_MS);
    document.addEventListener('visibilitychange', refresh);
}

export function initTicketComposer(): void {
    const form = document
        .querySelector<HTMLElement>('[data-visibility-toggle], [data-due-control]')
        ?.closest('form');

    if (!form) {
        return;
    }

    initTooltips(form);
    initVisibility(form);
    initDueDate(form);
}
