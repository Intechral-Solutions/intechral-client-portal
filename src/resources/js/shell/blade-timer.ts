/**
 * EPIC-013 WP6 — runtime behaviour for the Blade timer pill and tray (§18.4, Direction D §12.1–§12.2).
 *
 * Replaces `resources/js/timer-overlay.js`, which rendered a full-width strip of coloured tiles below
 * the header. Gone with it: the eight-entry decorative `PALETTE` and its four hard-coded hexes
 * (`#8b5cf6`, `#ec4899`, `#14b8a6`, `#f97316`), which Direction D does not sanction — the pill and tray
 * use `live` / `live-soft` / `live-text` like every other surface in the system.
 *
 * **It owns presentation, never rules.** Every derivation it draws with — elapsed seconds, the
 * `H:MM:SS` and `H:MM` forms, which timer the pill shows, row order, the labels, the clock offset —
 * comes from `lib/timer-state.ts`, the same module the React pill imports. That is the point of the
 * module: the two renderers draw different markup but cannot disagree about what they are drawing.
 * Before WP6 they did disagree, because the strip carried its own `formatElapsed` and never applied
 * the clock offset at all.
 *
 * Server authority is unchanged. Laravel decides which timers exist and whether a stop is permitted;
 * this file only asks (`GET /time/timers/active`) and acts (`POST /time/timer/{id}/stop`,
 * `PATCH /time/timer/{id}/description`) through the endpoints that already existed, with the CSRF token
 * on every mutation. No timer rule is re-implemented here, and a rejected request is resolved by
 * re-reading the server rather than by guessing locally.
 *
 * The `timerStarted` / `timerStopped` window events are preserved exactly as the strip defined them,
 * because `resources/views/components/time-tracker.blade.php` — the embedded tracker on ticket pages —
 * is a real consumer and WP6 does not touch it (§18.4).
 */

import {
    clockOffsetFrom,
    contextLine,
    elapsedSeconds,
    formatElapsed,
    formatElapsedNarrow,
    newestFirst,
    newestTimer,
    pillAccessibleName,
    pillLabel,
    trayHeading,
} from '@/lib/timer-state';
import type { ActiveTimer } from '@/types/time';

const ACTIVE_URL = '/time/timers/active';

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function show(element: Element | null, visible: boolean): void {
    if (element instanceof HTMLElement) {
        element.hidden = !visible;
    }
}

function setText(element: Element | null, text: string): void {
    if (element instanceof HTMLElement) {
        element.textContent = text;
    }
}

/** A timer payload is only usable if the server gave it an id and a start instant. */
function isTimer(value: unknown): value is ActiveTimer {
    const candidate = value as ActiveTimer | null;

    return (
        typeof candidate === 'object' &&
        candidate !== null &&
        typeof candidate.id === 'number' &&
        typeof candidate.started_at === 'string'
    );
}

export function initBladeTimer(): void {
    const root = document.querySelector<HTMLElement>('[data-shell-timer]');

    if (!root) {
        // No `time.log`, or an unauthenticated page: there is no pill to drive.
        return;
    }

    const trigger = root.querySelector<HTMLButtonElement>('[data-shell-timer-trigger]');
    const tray = root.querySelector<HTMLElement>('[data-shell-timer-tray]');
    const rows = root.querySelector<HTMLElement>('[data-timer-rows]');
    const stopButton = root.querySelector<HTMLButtonElement>('[data-shell-timer-stop]');

    if (!trigger || !tray || !rows || !stopButton) {
        return;
    }

    let timers: ActiveTimer[] = [];
    let offsetMs = 0;
    let tick: number | null = null;
    let pending: 'stopping' | null = null;
    let stopFailed = false;
    let unavailable: string | null = null;

    // ── Requests ────────────────────────────────────────────────────────────

    async function request<T>(url: string, init?: RequestInit): Promise<T> {
        const response = await fetch(url, {
            ...init,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
                ...(init?.method && init.method !== 'GET' ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
            },
        });

        // An expired session or a rotated token is resolved by reloading, exactly as the React
        // provider resolves it: the server, not this file, decides what the user may still do.
        if (response.status === 401 || response.status === 419) {
            window.location.reload();
            throw new Error('Session expired.');
        }

        if (!response.ok) {
            throw new Error(`Request failed (${response.status})`);
        }

        return (await response.json()) as T;
    }

    async function refresh(): Promise<void> {
        const startedAt = Date.now();

        try {
            const payload = await request<unknown>(ACTIVE_URL);
            const received = Date.now();

            timers = Array.isArray(payload) ? payload.filter(isTimer) : [];
            offsetMs = clockOffsetFrom(timers[0], startedAt, received);
            unavailable = null;
        } catch {
            // The pill keeps its last confirmed state and says so in the tray, rather than silently
            // pretending no timer runs — which is what the old strip did on a failed fetch.
            unavailable = 'Active timers are temporarily unavailable.';
        }

        render();
    }

    // ── Rendering ───────────────────────────────────────────────────────────

    function renderPill(): void {
        const shown = newestTimer(timers);
        const others = Math.max(0, timers.length - 1);
        const seconds = shown ? elapsedSeconds(shown.started_at, Date.now() + offsetMs) : null;
        const isPending = pending !== null;

        root!.dataset.timerRunning = shown ? 'true' : 'false';
        root!.dataset.timerCount = String(timers.length);

        show(root!.querySelector('[data-timer-idle-glyph]'), !shown);
        show(root!.querySelector('[data-timer-dot]'), Boolean(shown));
        show(root!.querySelector('[data-timer-idle]'), !shown && !isPending);
        show(root!.querySelector('[data-timer-chevron]'), timers.length > 1);

        const dot = root!.querySelector<HTMLElement>('[data-timer-dot]');
        if (dot) {
            // Hollow ring while a mutation is in flight, filled once the server confirms (§12.1).
            dot.classList.toggle('bg-live', !isPending);
            dot.classList.toggle('animate-live-pulse', !isPending);
            dot.classList.toggle('border', isPending);
            dot.classList.toggle('border-live', isPending);
        }

        const pendingLabel = root!.querySelector('[data-timer-pending]');
        show(pendingLabel, isPending);
        setText(pendingLabel, isPending ? 'Stopping…' : '');

        const wide = root!.querySelector('[data-timer-elapsed="wide"]');
        const narrow = root!.querySelector('[data-timer-elapsed="narrow"]');
        const label = root!.querySelector('[data-timer-label]');

        // No elapsed value at all until the server confirms, and none for a malformed timestamp.
        show(wide, Boolean(shown) && !isPending);
        show(narrow, Boolean(shown) && !isPending);
        show(label, Boolean(shown) && !isPending);

        if (shown && !isPending) {
            setText(wide, seconds === null ? '—' : formatElapsed(seconds));
            setText(narrow, seconds === null ? '—' : formatElapsedNarrow(seconds));
            setText(label, pillLabel(shown));
            label?.setAttribute('title', pillLabel(shown));
        }

        const badge = root!.querySelector('[data-timer-badge]');
        show(badge, others > 0);
        setText(badge, `+${others}`);

        trigger!.setAttribute('aria-label', pillAccessibleName(timers));
        trigger!.classList.toggle('border-danger', stopFailed || unavailable !== null);
        trigger!.classList.toggle('border-control-edge', !stopFailed && !unavailable && !!shown);
        trigger!.classList.toggle('bg-surface', !stopFailed && !unavailable && !!shown);
        trigger!.classList.toggle(
            'border-transparent',
            !stopFailed && unavailable === null && !shown,
        );

        show(stopButton, Boolean(shown) && !isPending);
        setText(
            stopButton!.querySelector('[data-timer-stop-label]'),
            stopFailed ? 'Retry stop' : 'Stop',
        );

        if (shown) {
            stopButton!.setAttribute('aria-label', `Stop timer: ${pillLabel(shown)}`);
        }

        show(root!.querySelector('[data-shell-timer-error]'), stopFailed);
    }

    function renderTray(): void {
        setText(tray!.querySelector('[data-timer-heading]'), trayHeading(timers.length));
        show(tray!.querySelector('[data-timer-retry]'), unavailable !== null);

        const message = tray!.querySelector('[data-timer-unavailable]');
        show(message, unavailable !== null && timers.length === 0);
        setText(message, unavailable ?? '');

        show(
            tray!.querySelector('[data-timer-empty]'),
            timers.length === 0 && unavailable === null,
        );
        show(rows, timers.length > 0);

        if (timers.length === 0) {
            rows!.replaceChildren();

            return;
        }

        const ordered = newestFirst(timers);
        const existing = new Map(
            Array.from(rows!.children).map((child) => [
                (child as HTMLElement).dataset.timerId,
                child as HTMLElement,
            ]),
        );

        // Rows are reused rather than rebuilt, so a description the user is part-way through typing
        // is not destroyed by the next refresh or the next tick.
        rows!.replaceChildren(
            ...ordered.map((timer) => {
                const row = existing.get(String(timer.id)) ?? buildRow(timer);

                updateRow(row, timer);

                return row;
            }),
        );
    }

    function render(): void {
        renderPill();
        renderTray();
        syncTicking();
    }

    // ── Tray rows ───────────────────────────────────────────────────────────

    function buildRow(timer: ActiveTimer): HTMLElement {
        const row = document.createElement('li');
        row.dataset.timerId = String(timer.id);
        row.className = 'border-b border-rule px-3 py-2.5 last:border-b-0';

        const line = document.createElement('div');
        line.className = 'flex items-start gap-2';

        const dot = document.createElement('span');
        dot.className = 'mt-1.5 size-2 shrink-0 rounded-full bg-live animate-live-pulse';
        dot.setAttribute('aria-hidden', 'true');
        dot.dataset.timerDot = '';
        line.appendChild(dot);

        const main = document.createElement('div');
        main.className = 'min-w-0 flex-1';

        const title = document.createElement('p');
        title.className = 'truncate text-sm font-medium text-text';
        title.dataset.timerTitle = '';
        main.appendChild(title);

        const field = document.createElement('input');
        field.type = 'text';
        field.maxLength = 500;
        field.placeholder = 'Add a description';
        field.dataset.timerDescription = '';
        field.className =
            'mt-1.5 flex h-8 w-full min-w-0 rounded-control border border-control-edge bg-surface px-3 text-xs text-text placeholder:text-text-muted hover:border-text-muted aria-invalid:border-danger focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';
        main.appendChild(field);
        line.appendChild(main);

        const side = document.createElement('div');
        side.className = 'flex shrink-0 items-center gap-1.5';

        const clock = document.createElement('span');
        clock.className = 'font-mono text-sm tabular-nums text-live-text';
        clock.dataset.timerClock = '';
        side.appendChild(clock);

        const stop = document.createElement('button');
        stop.type = 'button';
        stop.dataset.timerRowStop = '';
        stop.className =
            'inline-flex h-8 items-center rounded-control border border-transparent px-2.5 text-xs font-medium text-text hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';
        stop.textContent = 'Stop';
        side.appendChild(stop);

        line.appendChild(side);
        row.appendChild(line);

        const error = document.createElement('p');
        error.setAttribute('role', 'alert');
        error.hidden = true;
        error.dataset.timerRowError = '';
        error.className = 'mt-1.5 pl-4 text-xs text-danger';
        row.appendChild(error);

        // ── Row interaction ──
        field.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                // Escape reverts an UNSAVED DRAFT, and only then does it belong to the field: with
                // nothing to lose it must fall through to the document listener and close the tray,
                // exactly as it does on React (`timer-tray.tsx`'s `onEscapeKeyDown` veto). Swallowing
                // it unconditionally left the Blade tray stuck open — Chromium caught it.
                if (field.value === (field.dataset.committed ?? '')) {
                    return;
                }

                event.stopPropagation();
                field.value = field.dataset.committed ?? '';
                field.removeAttribute('aria-invalid');
                show(error, false);
            }

            if (event.key === 'Enter') {
                event.preventDefault();
                field.blur();
            }
        });

        field.addEventListener('blur', () => void saveDescription(row, field));

        stop.addEventListener('click', () => {
            void stopTimer(Number(row.dataset.timerId), (message) => {
                setText(error, message);
                show(error, true);
            });
        });

        return row;
    }

    function updateRow(row: HTMLElement, timer: ActiveTimer): void {
        const seconds = elapsedSeconds(timer.started_at, Date.now() + offsetMs);
        const label = contextLine(timer) ?? timer.description ?? 'Untitled timer';

        setText(row.querySelector('[data-timer-title]'), label);
        setText(
            row.querySelector('[data-timer-clock]'),
            seconds === null ? '—' : formatElapsed(seconds),
        );

        const stop = row.querySelector<HTMLButtonElement>('[data-timer-row-stop]');
        if (stop) {
            stop.setAttribute('aria-label', `Stop timer: ${label}`);
        }

        const field = row.querySelector<HTMLInputElement>('[data-timer-description]');
        if (field) {
            const canonical = timer.description ?? '';

            field.setAttribute(
                'aria-label',
                `Description for ${contextLine(timer) ?? 'this timer'}`,
            );

            // Adopt the server's value, but never over what the user is currently typing.
            if (field.dataset.committed !== canonical) {
                field.dataset.committed = canonical;

                if (document.activeElement !== field) {
                    field.value = canonical;
                }
            }
        }
    }

    async function saveDescription(row: HTMLElement, field: HTMLInputElement): Promise<void> {
        const next = field.value.trim();
        const error = row.querySelector<HTMLElement>('[data-timer-row-error]');

        if (next === (field.dataset.committed ?? '')) {
            return;
        }

        try {
            await request(`/time/timer/${row.dataset.timerId}/description`, {
                method: 'PATCH',
                body: JSON.stringify({ description: next || null }),
            });

            field.dataset.committed = next;
            field.removeAttribute('aria-invalid');
            show(error, false);
            await refresh();
        } catch {
            // The draft stays in the field; the server is re-read so the row cannot drift.
            field.setAttribute('aria-invalid', 'true');
            setText(error, 'Unable to save description.');
            show(error, true);
            await refresh();
        }
    }

    async function stopTimer(id: number, onError: (message: string) => void): Promise<void> {
        pending = 'stopping';
        stopFailed = false;
        renderPill();

        try {
            await request(`/time/timer/${id}/stop`, { method: 'POST' });
            pending = null;

            // Preserved contract: components/time-tracker.blade.php listens for this (§18.4).
            window.dispatchEvent(new CustomEvent('timerStopped', { detail: { id } }));
            announce('Timer stopped.');
            await refresh();
        } catch {
            pending = null;
            stopFailed = true;
            onError('Unable to stop timer.');
            // Whatever the server now says is the truth, including "it was already stopped".
            await refresh();
        }
    }

    // ── Ticking ─────────────────────────────────────────────────────────────

    /**
     * One interval for the whole pill, started only when there is something to count and cleared the
     * moment there is not. It recomputes from `started_at` every tick rather than incrementing a
     * counter, so it cannot accumulate drift over a long-lived page.
     */
    function syncTicking(): void {
        const shouldTick = timers.length > 0;

        if (shouldTick && tick === null) {
            tick = window.setInterval(() => {
                renderPill();

                if (!tray!.hidden) {
                    newestFirst(timers).forEach((timer) => {
                        const row = rows!.querySelector<HTMLElement>(
                            `[data-timer-id="${timer.id}"]`,
                        );

                        if (row) updateRow(row, timer);
                    });
                }
            }, 1000);
        }

        if (!shouldTick && tick !== null) {
            window.clearInterval(tick);
            tick = null;
        }
    }

    function announce(message: string): void {
        setText(root!.querySelector('[data-shell-timer-announce]'), message);
    }

    // ── Tray open / close ───────────────────────────────────────────────────

    function openTray(): void {
        tray!.hidden = false;
        trigger!.setAttribute('aria-expanded', 'true');
        renderTray();

        // The tray itself, not its first control: Tab then walks the rows in order, and nothing
        // lands in a description field before the user asked for one. React does the same.
        tray!.focus();
    }

    function closeTray(returnFocus: boolean): void {
        if (tray!.hidden) return;

        tray!.hidden = true;
        trigger!.setAttribute('aria-expanded', 'false');

        if (returnFocus) {
            trigger!.focus();
        }
    }

    trigger.addEventListener('click', () => (tray.hidden ? openTray() : closeTray(true)));

    stopButton.addEventListener('click', () => {
        const shown = newestTimer(timers);

        if (shown) {
            void stopTimer(shown.id, () => {
                stopFailed = true;
                renderPill();
            });
        }
    });

    tray.querySelector('[data-timer-retry]')?.addEventListener('click', () => void refresh());

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !tray.hidden) {
            closeTray(true);
        }
    });

    document.addEventListener('pointerdown', (event) => {
        if (!root.contains(event.target as Node)) {
            // The user chose somewhere else to be; taking focus back would fight them.
            closeTray(false);
        }
    });

    // The embedded Blade tracker announces its own starts; adopt the new timer without a reload.
    window.addEventListener('timerStarted', (event) => {
        const detail = (event as CustomEvent<unknown>).detail;

        if (isTimer(detail) && !timers.some((timer) => timer.id === detail.id)) {
            timers = [...timers, detail];
            announce('Timer started.');
            render();
        }

        // Then reconcile against the server, which stays the authority on the active set.
        void refresh();
    });

    void refresh();
}
