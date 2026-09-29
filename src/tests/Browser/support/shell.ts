import { expect } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Helpers for the Direction D operator shell (EPIC-013 WP4).
 *
 * The rail's brand mark is decorative — the account trigger carries identity and the rail is already
 * a labelled landmark — so there is no "home" brand link to click any more. Navigation goes through
 * the rail, which is what a real user does.
 */

/** The rail account control: a named menu button, per Direction D §13.1. */
export function accountTrigger(page: Page) {
    return page.getByRole('button', { name: /^Account menu:/ });
}

export async function openAccountMenu(page: Page) {
    await accountTrigger(page).click();
    await expect(page.getByRole('menu')).toBeVisible();
}

/** A workspace link in the rail. Labels come from the server, so they are the product's own words. */
export function railLink(page: Page, workspace: string) {
    return page
        .getByRole('navigation', { name: 'Workspaces' })
        .getByRole('link', { name: workspace, exact: true });
}

/** A contextual view inside the open drawer. */
export function drawerLink(page: Page, workspace: string, view: string) {
    return page
        .getByRole('navigation', { name: `${workspace} views` })
        .getByRole('link', { name: view, exact: true });
}

/** True when the page scrolls horizontally, which the shell must never cause. */
export function hasHorizontalOverflow(page: Page) {
    return page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    );
}

/*
 * EPIC-013 WP6 — the global timer pill and tray (Direction D §12.1, §12.2).
 *
 * Deliberately renderer-agnostic. React and Blade draw different markup but expose the same hooks and
 * the same accessible names, so one set of helpers drives both — which is also what makes a crossing
 * assertion meaningful: the same selector must find the same timer on either side of a document
 * navigation. `data-timer-count` and `data-timer-running` carry the pill's *state* rather than its
 * pixels, so the crossing tests can assert identity instead of appearance.
 */

/** The one global timer affordance. There must never be more than one on a page. */
export function timerPill(page: Page) {
    return page.locator('[data-shell-timer]');
}

export function timerTrigger(page: Page) {
    return page.locator('[data-shell-timer-trigger]');
}

/** The pill's own inline Stop, which acts on the timer the pill is showing. */
export function timerStop(page: Page) {
    return page.locator('[data-shell-timer-stop]');
}

export function timerTray(page: Page) {
    return page.getByRole('dialog', { name: 'Running timers' });
}

/** How many timers the server-reconciled pill currently knows about. */
export async function runningTimerCount(page: Page): Promise<number> {
    await expect(timerPill(page)).toBeAttached();

    return Number(await timerPill(page).getAttribute('data-timer-count'));
}

export async function expectRunningTimers(page: Page, count: number) {
    await expect(timerPill(page)).toHaveAttribute('data-timer-count', String(count));
    await expect(timerPill(page)).toHaveAttribute(
        'data-timer-running',
        count > 0 ? 'true' : 'false',
    );
}

export async function openTimerTray(page: Page) {
    await timerTrigger(page).click();
    await expect(timerTray(page)).toBeVisible();

    return timerTray(page);
}

/** Leaves the actor with no running timer, whichever renderer is showing. */
export async function stopAllTimers(page: Page) {
    if ((await runningTimerCount(page)) === 0) {
        return;
    }

    const tray = await openTimerTray(page);
    const stops = tray.getByRole('button', { name: /^Stop timer/ });

    for (let remaining = await stops.count(); remaining > 0; remaining--) {
        await stops.first().click();
        await expect(stops).toHaveCount(remaining - 1);
    }

    await expectRunningTimers(page, 0);
}

/**
 * Replays, for every rail workspace item, the half-built states a first paint passes through when
 * the HTML parser pauses inside the item (hosted CI caught one as shell shift 1.687885802469136e-6):
 *
 * - icon parsed, label not yet: the icon must already be at its final position;
 * - label element parsed, its text not yet: the label box must already be at its final position.
 *
 * Each state is compared with the finished item, and again after restoring it. Returns a description
 * of every movement; `[]` means the rail's geometry does not depend on how much of an item exists.
 */
export async function railItemDrift(page: Page): Promise<string[]> {
    return page.evaluate(() => {
        const drift: string[] = [];
        const items = document.querySelectorAll('[data-shell-rail] [data-shell-workspaces] a');

        if (items.length === 0) {
            return ['no rail workspace items rendered'];
        }

        const box = (element: Element) => {
            const r = element.getBoundingClientRect();

            return `${r.x},${r.y},${r.width},${r.height}`;
        };

        for (const item of items) {
            const icon = item.querySelector('svg');
            const label = item.querySelector('span');

            if (!icon || !label) {
                drift.push(`${item.textContent?.trim()}: missing icon or label`);
                continue;
            }

            const name = label.textContent?.trim();
            const iconFinal = box(icon);
            const labelFinal = box(label);

            label.remove();
            const iconWithoutLabel = box(icon);
            item.append(label);

            const text = [...label.childNodes];
            label.replaceChildren();
            const labelWithoutText = box(label);
            label.append(...text);

            if (iconWithoutLabel !== iconFinal || box(icon) !== iconFinal) {
                drift.push(`${name} icon: ${iconFinal} -> ${iconWithoutLabel} (no label) -> ${box(icon)}`);
            }

            if (labelWithoutText !== labelFinal || box(label) !== labelFinal) {
                drift.push(`${name} label: ${labelFinal} -> ${labelWithoutText} (no text) -> ${box(label)}`);
            }
        }

        return drift;
    });
}

type ShellShift = { value: number; sources: string[] };

/**
 * Records layout shift attributed to shell chrome, from the first paint of every document the page
 * loads (an init script, so it runs before any page script). An entry counts when any of its sources
 * is inside the rail, drawer or utility bar, or is the canvas itself: page bodies reflow once when
 * the swap fonts land (`font-display: swap`, EPIC-013 A5.7), which is not a shell shift. `sources`
 * only describes what was counted, so a failure says what moved; it never changes the total.
 */
export async function installShellShiftProbe(page: Page) {
    await page.addInitScript(() => {
        type Source = { node: Node | null; previousRect: DOMRectReadOnly; currentRect: DOMRectReadOnly };
        type Entry = PerformanceEntry & { value: number; sources: Source[] };

        const probe = { value: 0, sources: [] as string[] };
        const rect = (r: DOMRectReadOnly) => [r.x, r.y, r.width, r.height].map(Math.round).join(',');
        const describe = ({ node, previousRect, currentRect }: Source) => {
            const element = node as Element | null;
            const hook = element?.getAttributeNames?.().find((name) => name.startsWith('data-'));

            return `${element?.tagName?.toLowerCase() ?? node?.nodeName}${hook ? `[${hook}]` : ''} ${rect(previousRect)} -> ${rect(currentRect)}`;
        };
        const record = (entries: PerformanceEntryList) => {
            for (const entry of entries as Entry[]) {
                const nodes = entry.sources.map((source) => source.node as Element | null);
                const inShell = nodes.some((node) =>
                    node?.closest?.('[data-shell-rail], [data-shell-drawer], [data-shell-utility]'),
                );
                const isCanvas = nodes.some((node) => node?.hasAttribute?.('data-shell-canvas'));

                if (inShell || isCanvas) {
                    probe.value += entry.value;
                    probe.sources.push(...entry.sources.map(describe));
                }
            }
        };
        const observer = new PerformanceObserver((list) => record(list.getEntries()));
        observer.observe({ type: 'layout-shift', buffered: true });

        (window as unknown as { __shellShift: unknown }).__shellShift = {
            probe,
            // Entries the observer has queued but not yet delivered are still counted.
            flush: () => record(observer.takeRecords()),
        };
    });
}

/**
 * Reads the probe for the current document without discarding anything recorded since its first
 * paint. Call it after the state under test has settled (e.g. the timer pill's first confirmation).
 * Two animation frames make sure the frame carrying the last DOM change has been painted, so any shift
 * it caused exists; `takeRecords()` then collects entries the observer has not delivered yet.
 */
export async function readShellShift(page: Page): Promise<ShellShift> {
    return page.evaluate(async () => {
        await new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done)));
        const state = (
            window as unknown as { __shellShift: { probe: ShellShift; flush: () => void } }
        ).__shellShift;
        state.flush();

        return { value: state.probe.value, sources: state.probe.sources };
    });
}
