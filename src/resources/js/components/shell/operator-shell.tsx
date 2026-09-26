import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { PropsWithChildren } from 'react';

import { FlashRegion } from '@/components/feedback/flash-region';
import { RunningTimerBar } from '@/components/time/running-timer-bar';
import { TimerProvider } from '@/components/time/timer-provider';
import { usePageAnnouncement } from '@/hooks/use-page-announcement';
import { usePanelState } from '@/hooks/use-panel-state';
import type { SharedPageProps } from '@/types';

import { AccountMenu } from './account-menu';
import { Drawer } from './drawer';
import { NavSheet } from './nav-sheet';
import { Rail } from './rail';
import { SkipLink } from './skip-link';
import { UtilityBar } from './utility-bar';

/**
 * The Direction D **Operational** presentation: 64px rail, optional 248px contextual drawer, 48px
 * utility bar, full-viewport canvas (L1, Direction D §6).
 *
 * Its entire navigation input is the WP3 `navigation` prop. It performs no filtering, no permission
 * inference, no URL matching and no hierarchy building: the server already decided which workspaces
 * exist, what they link to, how each destination navigates and what is active (L14, §12.3 rule 1).
 * Geometry — including docked versus overlay and the whole narrow-width reshape — is CSS off
 * `html[data-drawer]` and the width classes, so no JavaScript decides layout (§16).
 */
export function OperatorShell({ children }: PropsWithChildren) {
    const { auth, navigation, flash } = usePage<SharedPageProps>().props;

    const workspace =
        navigation.workspaces.find((candidate) => candidate.key === navigation.currentWorkspace) ??
        null;

    // `context: []` means the workspace has no contextual navigation for any presentation, so there
    // is no panel — the rail item links straight to the surface (§12.3 rule 6). The empty context
    // and the null panel hint agree by contract; neither is inferred from the other.
    const hasPanel = (workspace?.context.length ?? 0) > 0;
    const serverDefault = hasPanel ? (workspace?.presentation.operational?.panel ?? null) : null;

    const panel = usePanelState(workspace?.key ?? null, serverDefault);
    const collapsed = !hasPanel || panel.panel === 'collapsed';
    const announcement = usePageAnnouncement();

    // Distinguishes "the user opened it" from "it was open by default", which is what decides
    // whether focus should move into it (Direction D §14.2).
    const [openedByUser, setOpenedByUser] = useState(false);
    // Focus cannot return to the rail toggle at the moment the panel closes, because the toggle only
    // exists once the panel is collapsed. A ref, not state: this is a one-shot imperative intent
    // consumed by the render that creates the toggle, and it must not itself cause a render.
    const returnFocus = useRef(false);

    function openPanel() {
        setOpenedByUser(true);
        returnFocus.current = false;
        panel.open();
    }

    /** `handBack` is true for a deliberate dismissal, false when the user clicked elsewhere. */
    function closePanel(handBack: boolean) {
        setOpenedByUser(false);
        returnFocus.current = handBack;
        panel.close();
    }

    useEffect(() => {
        if (!returnFocus.current || !collapsed || !hasPanel) {
            return;
        }

        returnFocus.current = false;
        document.querySelector<HTMLElement>('[data-shell-toggle]')?.focus();
    }, [collapsed, hasPanel]);

    const { close, toggle } = panel;

    /**
     * Esc and outside-click dismiss the panel — but **only while it floats** (Direction D §5.4,
     * §14.3: both are overlay affordances). A docked panel is part of the layout, so dismissing it
     * on any click into the content would collapse a grid column under the user's cursor: the
     * browser suite caught exactly that, as clicks and drags on the board and in forms landed in the
     * wrong place after the shift. `Ctrl+\` remains the way to collapse a docked panel.
     *
     * Whether it floats is asked of the CSS rather than re-derived from breakpoints here, so the
     * width classes stay the single source of truth for geometry (§16).
     */
    function panelIsFloating() {
        const drawer = document.querySelector('[data-shell-drawer]');

        return !!drawer && getComputedStyle(drawer).position === 'fixed';
    }

    useEffect(() => {
        if (collapsed) {
            return;
        }

        function onKeyDown(event: KeyboardEvent) {
            if (event.key === 'Escape' && panelIsFloating()) {
                setOpenedByUser(false);
                returnFocus.current = true;
                close();
            }
        }

        function onPointerDown(event: PointerEvent) {
            const target = event.target as Element | null;

            if (
                !target?.closest('[data-shell-drawer]') &&
                !target?.closest('[data-shell-rail]') &&
                panelIsFloating()
            ) {
                // The user chose somewhere else to be; taking focus back would fight them.
                setOpenedByUser(false);
                returnFocus.current = false;
                close();
            }
        }

        document.addEventListener('keydown', onKeyDown);
        document.addEventListener('pointerdown', onPointerDown);

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            document.removeEventListener('pointerdown', onPointerDown);
        };
    }, [collapsed, close]);

    // `Ctrl+\` toggles the panel (Direction D §14.3). Inert while a text field has focus so it can
    // never swallow a keystroke meant for an input (WCAG 2.1.4).
    useEffect(() => {
        if (!hasPanel) {
            return;
        }

        function onKeyDown(event: KeyboardEvent) {
            if (event.key !== '\\' || !(event.ctrlKey || event.metaKey)) {
                return;
            }

            const target = event.target as HTMLElement | null;

            if (
                target?.isContentEditable ||
                ['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName ?? '')
            ) {
                return;
            }

            event.preventDefault();
            setOpenedByUser(true);
            returnFocus.current = true;
            toggle();
        }

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [hasPanel, toggle]);

    return (
        <TimerProvider enabled={auth.permissions.includes('time.log')}>
            <div
                data-shell="operational"
                data-panel={hasPanel ? 'true' : 'false'}
                data-pinned={panel.pinned ? 'true' : 'false'}
            >
                <SkipLink />

                <Rail
                    workspaces={navigation.workspaces}
                    showToggle={hasPanel && collapsed}
                    onToggle={openPanel}
                >
                    <NavSheet workspaces={navigation.workspaces} current={workspace} />
                    {auth.user ? <AccountMenu user={auth.user} /> : null}
                </Rail>

                {workspace && hasPanel && !collapsed ? (
                    <Drawer
                        workspace={workspace}
                        pinned={panel.pinned}
                        autoFocus={openedByUser}
                        onClose={() => closePanel(true)}
                        onPin={panel.pin}
                    />
                ) : null}

                <div data-shell-canvas>
                    <UtilityBar workspace={workspace} collapsed={collapsed} />

                    <RunningTimerBar />
                    <FlashRegion flash={flash} />

                    {/* `tabIndex={-1}` makes this both the skip link's destination and the repair
                        target for a visit that destroyed focus (A1.8). */}
                    <main id="main-content" tabIndex={-1} className="min-w-0 flex-1 outline-none">
                        {children}
                    </main>

                    {/* `data-shell-announcer` distinguishes this from a page's own live region:
                        the board has one with the same role and classes, and a selector that matched
                        both would be ambiguous. */}
                    <p
                        ref={announcement}
                        data-shell-announcer
                        aria-live="polite"
                        className="sr-only"
                    />
                </div>
            </div>
        </TimerProvider>
    );
}
