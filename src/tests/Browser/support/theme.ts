import { expect } from '@playwright/test';
import type { Page } from '@playwright/test';

export type Theme = 'light' | 'dark';

/**
 * The canonical Direction D `canvas` per theme (docs/design/direction-d-design-system.md §2.2), as the computed
 * `rgb(...)` strings a browser reports. Typed here from the specification, NOT read from `app.css`, so a page that
 * is in the wrong theme, or a theme whose canvas value moved, cannot pass by agreeing with itself.
 */
export const CANVAS_RGB: Record<Theme, string> = {
    light: 'rgb(246, 245, 241)',
    dark: 'rgb(13, 20, 22)',
};

/** Resolve `--ds-canvas` to the computed colour the browser uses. Custom properties do not transition, so this is immediate. */
function readCanvas(page: Page): Promise<string> {
    return page.evaluate(() => {
        const probe = document.createElement('i');
        document.body.appendChild(probe);
        probe.style.color = 'var(--ds-canvas)';
        const value = getComputedStyle(probe).color;
        probe.remove();

        return value;
    });
}

/**
 * Prove the requested theme is the active one before anything is measured, then wait for the colour transitions its
 * activation started to finish.
 *
 *   1. `<html data-theme>` equals the requested theme.
 *   2. The canonical `canvas` token resolves to that theme's value: the theme really is applied, not just named.
 *   3. Every FINITE running animation or transition has finished. Infinite animations (the live-state pulse, spinners)
 *      are excluded on purpose: waiting on them would never end, and they are not a settling colour. A finite one that
 *      never ends fails the wait with a clear timeout; it is never skipped past.
 *
 * This replaces fixed `waitForTimeout` delays, which race the 120 ms `motion-fast` colour transitions on a loaded machine.
 */
export async function settleTheme(page: Page, theme: Theme, label: string): Promise<void> {
    expect(await page.locator('html').getAttribute('data-theme'), `${label}: the ${theme} theme is applied`).toBe(theme);
    expect(await readCanvas(page), `${label}: the canonical ${theme} canvas resolves`).toBe(CANVAS_RGB[theme]);

    // A transition begins at the next style recalculation after the attribute changed, so let two frames pass first.
    await page.evaluate(() => new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))));

    await page.waitForFunction(
        () =>
            document.getAnimations().every((animation) => {
                if (animation.playState !== 'running' && animation.playState !== 'pending') {
                    return true;
                }

                // `endTime` is Infinity for an animation with infinite iterations.
                return !Number.isFinite(animation.effect?.getComputedTiming().endTime ?? Infinity);
            }),
        undefined,
        { timeout: 5000 },
    );
}

/** Set `<html data-theme>` on the current document, then prove and settle it. */
export async function applyTheme(page: Page, theme: Theme, label: string): Promise<void> {
    await page.evaluate((value) => document.documentElement.setAttribute('data-theme', value), theme);
    await settleTheme(page, theme, label);
}
