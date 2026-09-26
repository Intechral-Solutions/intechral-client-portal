import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/**
 * Inertia navigation focus and announcement policy (EPIC-013 §22.2, locked by spike S2 / A1.8).
 *
 * > Announce every page change politely. Move focus only when the visit destroyed it.
 *
 * S2 measured the three candidates in Chromium. Moving focus to `main` or the page `h1` on every
 * visit destroys a keyboard user's place in the rail, so neither is the default. Leaving focus alone
 * is right for a rail link — it stays on the activated link — but wrong for an in-page control such
 * as a view tab, which lives inside the subtree Inertia replaces, so activating one silently drops
 * focus to `<body>`. That was a real defect before WP4, not a hypothetical.
 *
 * So: always announce, and repair focus only when it was actually lost.
 */
export function usePageAnnouncement() {
    const region = useRef<HTMLParagraphElement | null>(null);
    /**
     * Inertia fires `navigate` for the FIRST page as well as every later visit. On the first one
     * nothing was destroyed — focus is at the start of the document, which is exactly where a
     * keyboard user expects Tab to begin — so repairing it would swallow the skip link and the whole
     * shell on the user's first Tab. Real-browser validation caught this: initial focus landed on
     * `<main>` and Tab went straight into page content.
     */
    const firstNavigate = useRef(true);

    useEffect(() => {
        function announce() {
            if (firstNavigate.current) {
                firstNavigate.current = false;

                return;
            }

            const heading = document.querySelector('main h1')?.textContent?.trim();
            const node = region.current;

            if (!node) {
                return;
            }

            // Cleared before it is re-set: consecutive pages can share an `h1` (`/time` and
            // `/time/allocation` both read "My Time") and assistive technology will not re-announce
            // identical text (A1.8).
            node.textContent = '';

            if (heading) {
                window.setTimeout(() => {
                    if (region.current) {
                        region.current.textContent = heading;
                    }
                }, 50);
            }

            const active = document.activeElement;

            // Only `body`/`documentElement` mean the activating control was inside the replaced
            // subtree. Anything else is a still-live control — usually the rail link just used —
            // and moving focus away from it would be the destructive behaviour S2 rejected.
            if (!active || active === document.body || active === document.documentElement) {
                document.getElementById('main-content')?.focus();
            }
        }

        // Fires for back/forward as well, which closes the silent-history gap S2 found.
        return router.on('navigate', announce);
    }, []);

    return region;
}
