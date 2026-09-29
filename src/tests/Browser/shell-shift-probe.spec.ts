import type { Page } from '@playwright/test';

import { expect, signedIn, test } from './support/auth';
import { installShellShiftProbe, readShellShift } from './support/shell';

/**
 * The shared shell-shift probe (support/shell.ts) decides which layout shifts are "shell" shifts.
 * A layout-shift source can be a Text node, which has no `closest()`; the probe once tested the raw
 * node and silently dropped every text source, so a real shell text shift read as `shellShift === 0`.
 *
 * These tests exercise the probe in Chromium, which is authoritative for real LayoutShift behaviour:
 * the ownership rule directly, and real text-node shifts made in the rail and in page body.
 */

type ProbeWindow = Window & {
    __shellShift: { ownedByShell: (node: Node | null) => boolean };
    __wholePage: { textSource: boolean; owner: string }[];
    __wholePageFlush: () => void;
};

async function openShell(page: Page) {
    await page.setViewportSize({ width: 1440, height: 900 });
    await installShellShiftProbe(page);
    await signedIn(page, '/operator/tickets');
    await expect(page.locator('[data-shell-timer]')).toBeVisible();
}

test('the probe attributes element and text-node sources to the shell or to the page body', async ({
    page,
}) => {
    await openShell(page);

    const owned = await page.evaluate(() => {
        const { ownedByShell } = (window as unknown as ProbeWindow).__shellShift;
        const label = document.querySelector('[data-shell-workspaces] a span')!;
        const crumb = document.querySelector('[data-shell-utility] nav')!;
        const bodyText = document.querySelector('main')!.appendChild(document.createElement('p'));
        bodyText.textContent = 'page body text';

        return {
            railIcon: ownedByShell(document.querySelector('[data-shell-workspaces] a svg')),
            railLabelElement: ownedByShell(label),
            railLabelText: ownedByShell(label.firstChild),
            utilityText: ownedByShell(crumb.querySelector('a, span')?.firstChild ?? null),
            canvas: ownedByShell(document.querySelector('[data-shell-canvas]')),
            bodyElement: ownedByShell(bodyText),
            bodyText: ownedByShell(bodyText.firstChild),
            nothing: ownedByShell(null),
        };
    });

    expect(owned).toEqual({
        railIcon: true,
        railLabelElement: true,
        railLabelText: true,
        utilityText: true,
        canvas: true,
        bodyElement: false,
        bodyText: false,
        nothing: false,
    });
});

test('a real text-node shift in the rail is counted and one in page body is not', async ({
    page,
}) => {
    await openShell(page);

    // Setup shifts (adding an in-flow body element pushes page content down) happen first and are
    // settled before the baseline is read, so only the two deliberate text shifts below are measured.
    await page.evaluate(async () => {
        const el = document.createElement('div');
        document.querySelector('main')!.prepend(el);
        el.id = 'probe-body-text';
        el.style.cssText = 'width:400px;text-align:center;font-size:20px';
        el.textContent = 'a considerably longer line of page body text';
        await new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done)));
    });
    const before = (await readShellShift(page)).value;

    // A second, unfiltered recorder proves each shift happened and that its source really was a Text
    // node (otherwise the assertions below could pass vacuously).
    await page.evaluate(() => {
        const seen: ProbeWindow['__wholePage'] = [];
        const record = (entries: PerformanceEntryList) => {
            for (const entry of entries as unknown as { sources: { node: Node | null }[] }[]) {
                for (const { node } of entry.sources) {
                    const element =
                        node?.nodeType === Node.TEXT_NODE
                            ? node.parentElement
                            : (node as Element | null);

                    seen.push({
                        textSource: node?.nodeType === Node.TEXT_NODE,
                        owner: element?.closest?.('[data-shell-rail]') ? 'rail' : 'other',
                    });
                }
            }
        };
        const observer = new PerformanceObserver((list) => record(list.getEntries()));
        observer.observe({ type: 'layout-shift' });

        (window as unknown as ProbeWindow).__wholePage = seen;
        // Same as the probe: entries queued but not yet delivered are still read.
        (window as unknown as ProbeWindow).__wholePageFlush = () => record(observer.takeRecords());
    });
    const wholePage = () =>
        page.evaluate(() => {
            (window as unknown as ProbeWindow).__wholePageFlush();

            return (window as unknown as ProbeWindow).__wholePage.slice();
        });

    // Page body: the text run of centred text moves when its width changes (a Text-node source) —
    // the same mechanism as a font swap in body content, which A13.9 says is not shell shift. The
    // SAME Text node is edited in place: replacing it would create a node with no previous position,
    // which is not a shift at all.
    await page.evaluate(async () => {
        (document.querySelector('#probe-body-text')!.firstChild as Text).data = 'short';
        await new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done)));
    });
    const afterBody = await readShellShift(page);
    const bodyShifts = await wholePage();

    expect(bodyShifts.some((s) => s.textSource && s.owner === 'other')).toBe(true);
    expect(bodyShifts.some((s) => s.owner === 'rail')).toBe(false);
    expect(afterBody.value).toBe(before);

    // Rail: the same movement of a centred rail label's text run — the mechanism of the deferred Plex
    // Sans 600 active-label re-centre — must be counted, and must name a text source.
    await page.evaluate(async () => {
        const label = document.querySelector('[data-shell-workspaces] a[aria-current] span')!;
        (label.firstChild as Text).data = 'Pro';
        await new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done)));
    });
    const afterRail = await readShellShift(page);
    const railShifts = await wholePage();

    expect(railShifts.some((s) => s.textSource && s.owner === 'rail')).toBe(true);
    expect(afterRail.value).toBeGreaterThan(before);
    expect(afterRail.sources.join('; ')).toContain('#text');
});
