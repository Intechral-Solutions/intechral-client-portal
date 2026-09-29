import type { ActiveTimer } from '@/types/time';

import { initBladeTimer } from './blade-timer';

/**
 * EPIC-013 WP6 — the Blade timer pill and tray's runtime behaviour.
 *
 * The fixture carries only the hooks `blade-timer.ts` reads; the real markup is
 * `layouts/partials/shell/timer.blade.php`, asserted on the rendered HTML in
 * `tests/Feature/BladeShellTest.php` and in real Chromium in `tests/Browser/timer-shell.spec.ts`.
 *
 * The derivations these tests observe — elapsed time, which timer the pill shows, the labels — are the
 * shared module's and are unit-tested directly in `lib/timer-state.test.ts`. What matters here is that
 * the Blade renderer *applies* them to the DOM, and that it reaches the same endpoints React does.
 *
 * `[hidden]` is honoured by assertion rather than by layout: jsdom applies no stylesheet, so the
 * unlayered `display: none` rule that gives the attribute authority over Tailwind's display utilities
 * is a browser concern, covered in the Playwright spec.
 */

const NOW = Date.parse('2026-09-28T12:00:00.000Z');

function timer(overrides: Partial<ActiveTimer> = {}): ActiveTimer {
    return {
        id: 1,
        started_at: '2026-09-28T11:59:50.000Z',
        server_now: '2026-09-28T12:00:00.000Z',
        description: 'Original',
        context: { type: 'Ticket', id: 7, label: 'TKT-42', url: '/tickets/7' },
        ...overrides,
    };
}

function mountTimer() {
    document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    document.body.innerHTML = `
        <div data-shell="operational" data-shell-renderer="blade">
            <header data-shell-utility>
                <div class="relative" data-shell-timer data-timer-running="false" data-timer-count="0">
                    <p class="sr-only" aria-live="polite" data-shell-timer-announce></p>
                    <div>
                        <button type="button" data-shell-timer-trigger aria-haspopup="dialog" aria-expanded="false" aria-label="Start timer">
                            <span data-timer-idle-glyph></span>
                            <span data-timer-dot hidden class="bg-live animate-live-pulse"></span>
                            <span data-timer-idle>Start timer</span>
                            <span data-timer-pending hidden></span>
                            <span data-timer-elapsed="wide" hidden></span>
                            <span data-timer-elapsed="narrow" hidden></span>
                            <span data-timer-label hidden></span>
                            <span data-timer-badge hidden></span>
                            <span data-timer-chevron hidden></span>
                        </button>
                        <button type="button" data-shell-timer-stop hidden><span data-timer-stop-label>Stop</span></button>
                        <span role="alert" hidden data-shell-timer-error>Couldn&rsquo;t stop</span>
                    </div>
                    <div id="shell-timer-tray" role="dialog" aria-label="Running timers" data-shell-timer-tray tabindex="-1" hidden>
                        <p data-timer-heading>0 running</p>
                        <button type="button" data-timer-retry hidden>Retry</button>
                        <p data-timer-unavailable hidden role="alert"></p>
                        <div data-timer-empty><p>No timers running.</p></div>
                        <ul data-timer-rows hidden></ul>
                        <a href="/time">Open Time &rarr;</a>
                    </div>
                </div>
            </header>
            <main id="main-content"><p id="outside">Page content</p></main>
        </div>`;

    initBladeTimer();
}

const pill = () => document.querySelector<HTMLElement>('[data-shell-timer]')!;
const trigger = () => document.querySelector<HTMLButtonElement>('[data-shell-timer-trigger]')!;
const tray = () => document.querySelector<HTMLElement>('[data-shell-timer-tray]')!;
const rows = () => Array.from(document.querySelectorAll<HTMLElement>('[data-timer-rows] > li'));
const text = (selector: string) =>
    document.querySelector<HTMLElement>(selector)?.textContent?.trim() ?? '';

/** Lets the module's `refresh()` promise chain settle. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

function respondWith(...responses: Array<{ ok?: boolean; status?: number; body?: unknown }>) {
    const fetchMock = vi.fn();

    responses.forEach(({ ok = true, status = 200, body = [] }) => {
        fetchMock.mockResolvedValueOnce({
            ok,
            status,
            json: vi.fn().mockResolvedValue(body),
        } as unknown as Response);
    });

    // Anything beyond the scripted responses re-reads an empty active set.
    fetchMock.mockResolvedValue({
        ok: true,
        status: 200,
        json: vi.fn().mockResolvedValue([]),
    } as unknown as Response);

    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.setSystemTime(NOW);
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
    document.head.innerHTML = '';
});

it('reads the active set from the canonical endpoint on init', async () => {
    const fetchMock = respondWith({ body: [] });
    mountTimer();
    await settle();

    expect(fetchMock).toHaveBeenCalledWith('/time/timers/active', expect.any(Object));
    expect(pill().dataset.timerRunning).toBe('false');
    expect(trigger().getAttribute('aria-label')).toBe('Start timer');
});

it('draws the running state with the shared elapsed and label derivations', async () => {
    respondWith({ body: [timer()] });
    mountTimer();
    await settle();

    expect(pill().dataset.timerRunning).toBe('true');
    expect(pill().dataset.timerCount).toBe('1');
    expect(text('[data-timer-elapsed="wide"]')).toBe('0:00:10');
    expect(text('[data-timer-elapsed="narrow"]')).toBe('0:00');
    expect(text('[data-timer-label]')).toBe('Ticket: TKT-42');
    expect(trigger().getAttribute('aria-label')).toBe('Running timer: Ticket: TKT-42. Show timers');
    // The idle affordance is gone and the Stop control has arrived, named for its context.
    expect(document.querySelector<HTMLElement>('[data-timer-idle]')!.hidden).toBe(true);
    const stop = document.querySelector<HTMLElement>('[data-shell-timer-stop]')!;
    expect(stop.hidden).toBe(false);
    expect(stop.getAttribute('aria-label')).toBe('Stop timer: Ticket: TKT-42');
});

it('shows the newest timer inline and counts the others', async () => {
    respondWith({
        body: [
            timer({ id: 1, started_at: '2026-09-28T10:00:00.000Z' }),
            timer({
                id: 2,
                started_at: '2026-09-28T11:59:00.000Z',
                context: { type: 'Task', id: 9, label: 'Newest', url: null },
            }),
        ],
    });
    mountTimer();
    await settle();

    expect(text('[data-timer-label]')).toBe('Task: Newest');
    expect(text('[data-timer-badge]')).toBe('+1');
    expect(document.querySelector<HTMLElement>('[data-timer-chevron]')!.hidden).toBe(false);
});

it('ticks the elapsed time every second without re-reading the server', async () => {
    const fetchMock = respondWith({ body: [timer()] });
    mountTimer();
    await settle();

    const reads = fetchMock.mock.calls.length;
    expect(text('[data-timer-elapsed="wide"]')).toBe('0:00:10');

    await vi.advanceTimersByTimeAsync(3000);

    expect(text('[data-timer-elapsed="wide"]')).toBe('0:00:13');
    expect(fetchMock).toHaveBeenCalledTimes(reads);
});

it('runs no interval while nothing is running', async () => {
    respondWith({ body: [] });
    mountTimer();
    await settle();

    // Nothing to count, so an idle Blade page pays for no timer at all.
    expect(vi.getTimerCount()).toBe(0);
});

it('lists rows newest-first in the tray and opens on the trigger', async () => {
    respondWith({
        body: [
            timer({ id: 1, started_at: '2026-09-28T10:00:00.000Z', description: 'Older' }),
            timer({
                id: 2,
                started_at: '2026-09-28T11:00:00.000Z',
                description: 'Newer',
                context: { type: 'Task', id: 9, label: 'Newer task', url: null },
            }),
        ],
    });
    mountTimer();
    await settle();

    expect(tray().hidden).toBe(true);
    trigger().click();

    expect(tray().hidden).toBe(false);
    expect(trigger().getAttribute('aria-expanded')).toBe('true');
    expect(text('[data-timer-heading]')).toBe('2 running');
    expect(rows()).toHaveLength(2);
    expect(rows().map((row) => row.dataset.timerId)).toEqual(['2', '1']);
});

it('focuses the tray itself on open, not a row description field', async () => {
    respondWith({ body: [timer()] });
    mountTimer();
    await settle();

    trigger().click();

    // Someone opening the tray to press Stop must not land in a text box.
    expect(document.activeElement).toBe(tray());
    expect(document.activeElement?.tagName).not.toBe('INPUT');
});

it('closes the tray on Escape and returns focus to the pill', async () => {
    respondWith({ body: [timer()] });
    mountTimer();
    await settle();

    trigger().click();
    expect(tray().hidden).toBe(false);

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

    expect(tray().hidden).toBe(true);
    expect(document.activeElement).toBe(trigger());
});

it('closes the tray on an outside pointer press without taking focus back', async () => {
    respondWith({ body: [timer()] });
    mountTimer();
    await settle();

    trigger().click();
    document
        .querySelector('#outside')!
        .dispatchEvent(new MouseEvent('pointerdown', { bubbles: true }));

    expect(tray().hidden).toBe(true);
    // The user chose somewhere else to be; focus is not dragged back to the pill.
    expect(document.activeElement).not.toBe(trigger());
});

it('stops the shown timer through the canonical endpoint, with the CSRF token', async () => {
    const fetchMock = respondWith({ body: [timer()] }, { body: { id: 1 } }, { body: [] });
    mountTimer();
    await settle();

    document.querySelector<HTMLButtonElement>('[data-shell-timer-stop]')!.click();
    await settle();

    const [url, init] = fetchMock.mock.calls[1] as [string, RequestInit];
    expect(url).toBe('/time/timer/1/stop');
    expect(init.method).toBe('POST');
    expect((init.headers as Record<string, string>)['X-CSRF-TOKEN']).toBe('test-token');
    // And it reconciles from the server rather than assuming the stop worked.
    expect(fetchMock.mock.calls[2]?.[0]).toBe('/time/timers/active');
});

it('dispatches timerStopped so the embedded Blade tracker keeps working', async () => {
    respondWith({ body: [timer()] }, { body: { id: 1 } }, { body: [] });
    mountTimer();
    await settle();

    const stopped = vi.fn();
    window.addEventListener('timerStopped', stopped);

    document.querySelector<HTMLButtonElement>('[data-shell-timer-stop]')!.click();
    await settle();

    // The event contract components/time-tracker.blade.php depends on is preserved (§18.4).
    expect(stopped).toHaveBeenCalledTimes(1);
    expect((stopped.mock.calls[0]?.[0] as CustomEvent).detail).toEqual({ id: 1 });

    window.removeEventListener('timerStopped', stopped);
});

it('adopts a timer announced by timerStarted, then reconciles with the server', async () => {
    const fetchMock = respondWith({ body: [] });
    mountTimer();
    await settle();

    expect(pill().dataset.timerRunning).toBe('false');

    window.dispatchEvent(new CustomEvent('timerStarted', { detail: timer({ id: 3 }) }));

    // Adopted immediately, so the pill does not wait a round trip to admit the timer exists.
    expect(pill().dataset.timerRunning).toBe('true');
    expect(text('[data-shell-timer-announce]')).toBe('Timer started.');

    // ...and then the server is asked, because it is the authority on the active set.
    await settle();
    expect(fetchMock.mock.calls.at(-1)?.[0]).toBe('/time/timers/active');
});

it('keeps a Stop failure visible and offers a retry', async () => {
    respondWith({ body: [timer()] }, { ok: false, status: 500, body: {} }, { body: [timer()] });
    mountTimer();
    await settle();

    document.querySelector<HTMLButtonElement>('[data-shell-timer-stop]')!.click();
    await settle();

    expect(document.querySelector<HTMLElement>('[data-shell-timer-error]')!.hidden).toBe(false);
    expect(text('[data-timer-stop-label]')).toBe('Retry stop');
    // The timer is still running, so its last confirmed state stays drawn.
    expect(pill().dataset.timerRunning).toBe('true');
});

it('reports an unavailable active set in the tray instead of pretending nothing runs', async () => {
    respondWith({ ok: false, status: 503, body: {} });
    mountTimer();
    await settle();

    trigger().click();

    const message = document.querySelector<HTMLElement>('[data-timer-unavailable]')!;
    expect(message.hidden).toBe(false);
    expect(message.textContent).toContain('temporarily unavailable');
    expect(document.querySelector<HTMLElement>('[data-timer-retry]')!.hidden).toBe(false);
});

it('reloads on an expired session rather than guessing what the user may still do', async () => {
    respondWith({ ok: false, status: 419, body: {} });
    const reload = vi.fn();
    Object.defineProperty(window, 'location', {
        configurable: true,
        value: { ...window.location, reload },
    });

    mountTimer();
    await settle();

    expect(reload).toHaveBeenCalled();
});

it('ignores a malformed payload rather than drawing a broken timer', async () => {
    respondWith({ body: [{ nonsense: true }, timer({ id: 4 })] });
    mountTimer();
    await settle();

    // Only the well-formed entry survives the filter.
    expect(pill().dataset.timerCount).toBe('1');
    expect(text('[data-timer-elapsed="wide"]')).toBe('0:00:10');
});

it('shows no elapsed value for an unparseable start instant', async () => {
    respondWith({ body: [timer({ started_at: 'not-a-date' })] });
    mountTimer();
    await settle();

    expect(text('[data-timer-elapsed="wide"]')).toBe('—');
});

describe('description editing', () => {
    async function openRow() {
        trigger().click();

        return document.querySelector<HTMLInputElement>('[data-timer-description]')!;
    }

    it('saves through the canonical endpoint on blur', async () => {
        const fetchMock = respondWith(
            { body: [timer()] },
            { body: { id: 1, description: 'Rewritten' } },
            { body: [timer({ description: 'Rewritten' })] },
        );
        mountTimer();
        await settle();

        const field = await openRow();
        expect(field.value).toBe('Original');

        field.value = 'Rewritten';
        field.dispatchEvent(new FocusEvent('blur'));
        await settle();

        const [url, init] = fetchMock.mock.calls[1] as [string, RequestInit];
        expect(url).toBe('/time/timer/1/description');
        expect(init.method).toBe('PATCH');
        expect(init.body).toBe(JSON.stringify({ description: 'Rewritten' }));
    });

    it('sends null rather than an empty string when cleared', async () => {
        const fetchMock = respondWith(
            { body: [timer()] },
            { body: { id: 1, description: null } },
            { body: [timer({ description: null })] },
        );
        mountTimer();
        await settle();

        const field = await openRow();
        field.value = '   ';
        field.dispatchEvent(new FocusEvent('blur'));
        await settle();

        expect((fetchMock.mock.calls[1]?.[1] as RequestInit).body).toBe(
            JSON.stringify({ description: null }),
        );
    });

    it('sends nothing when the value is unchanged', async () => {
        const fetchMock = respondWith({ body: [timer()] });
        mountTimer();
        await settle();

        const field = await openRow();
        const reads = fetchMock.mock.calls.length;
        field.dispatchEvent(new FocusEvent('blur'));
        await settle();

        expect(fetchMock).toHaveBeenCalledTimes(reads);
    });

    it('reverts on Escape and leaves the tray open', async () => {
        respondWith({ body: [timer()] });
        mountTimer();
        await settle();

        const field = await openRow();
        field.value = 'Abandoned';
        field.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(field.value).toBe('Original');
        // Escape in the field belongs to the field: it must not also close the tray.
        expect(tray().hidden).toBe(false);
    });

    it('lets Escape close the tray when the field holds no draft', async () => {
        respondWith({ body: [timer()] });
        mountTimer();
        await settle();

        const field = await openRow();
        field.focus();
        // Nothing typed, so Escape means what it means everywhere else. Only a field with an unsaved
        // draft swallows it — the same rule the React tray applies.
        field.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(tray().hidden).toBe(true);
    });

    it('keeps the draft and marks the field invalid when a save fails', async () => {
        respondWith({ body: [timer()] }, { ok: false, status: 422, body: {} }, { body: [timer()] });
        mountTimer();
        await settle();

        const field = await openRow();
        field.value = 'Unsaved words';
        field.dispatchEvent(new FocusEvent('blur'));
        await settle();

        expect(field.value).toBe('Unsaved words');
        expect(field.getAttribute('aria-invalid')).toBe('true');
    });

    it('does not overwrite what the user is typing when the server reconciles', async () => {
        respondWith({ body: [timer()] }, { body: [timer({ description: 'From another tab' })] });
        mountTimer();
        await settle();

        const field = await openRow();
        field.focus();
        field.value = 'Mid-edit';

        // A refresh lands while the field has focus.
        window.dispatchEvent(new CustomEvent('timerStarted', { detail: null }));
        await settle();

        expect(field.value).toBe('Mid-edit');
    });
});
