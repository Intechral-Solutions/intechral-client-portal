import { usePage } from '@inertiajs/react';
import type { PropsWithChildren, ReactNode } from 'react';

import type { SharedPageProps } from '@/types';

import { TimerProvider } from '@/components/time/timer-provider';

import { CustomerShell } from './customer-shell';
import { OperatorShell } from './operator-shell';
import type { BreadcrumbSegment } from './breadcrumb';

export type { BreadcrumbSegment };

/**
 * The single shell-resolution boundary (EPIC-013 §23.2, seam 2).
 *
 * This is the **only** component in the application that reads `shell.presentation`, and no page may
 * branch on it. It picks one of two shells and adds nothing to a page: the Operational shell (rail,
 * drawer, utility bar) or the Focused customer shell (top bar). Every page keeps
 * `Page.layout = <AppShell>` and renders unchanged in either.
 *
 * The prop names a *presentation family*, never a role — rail is not an operator permission and a top
 * bar is not a customer permission (L17). The server resolves it from capabilities
 * (`ShellPresentationResolver`) and it is never an authorization rule: every route keeps its own gate.
 */
export function AppShell({
    children,
    trail,
}: PropsWithChildren<{
    /**
     * The page's own segments of the breadcrumb (Direction D §13.2), after the workspace and the active
     * view: a record page names the project and the task it is, and the shell draws the one trail.
     * This is the only way a page contributes to the breadcrumb: a page never renders its own
     * `Breadcrumb` landmark (A13.12). Omitted, the trail ends at the active view as before. The Focused
     * shell has no breadcrumb and ignores it.
     */
    trail?: BreadcrumbSegment[];
}>) {
    const { shell, auth } = usePage<SharedPageProps>().props;

    let presentation: ReactNode;

    switch (shell.presentation) {
        case 'focused':
            presentation = <CustomerShell trail={trail}>{children}</CustomerShell>;
            break;
        case 'operational':
        default:
            presentation = <OperatorShell trail={trail}>{children}</OperatorShell>;
    }

    // The timer context belongs ABOVE the branch (EPIC-017 §8.3, N7): pages such as `/time` and the
    // task time panel use `useTimers()` and are chrome-agnostic, so they must work under every
    // presentation. Only the Operational shell draws the global pill and tray; the provider is
    // presentation-independent state, enabled by the existing `time.log` capability alone. It is never
    // a timer authorization rule — which contexts may receive time is decided on the server.
    return (
        <TimerProvider enabled={auth.permissions.includes('time.log')}>
            {presentation}
        </TimerProvider>
    );
}
