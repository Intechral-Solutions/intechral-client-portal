import { usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import type { SharedPageProps } from '@/types';

import { OperatorShell } from './operator-shell';
import type { BreadcrumbSegment } from './breadcrumb';

export type { BreadcrumbSegment };

/**
 * The single shell-resolution boundary (EPIC-013 §23.2, seam 2).
 *
 * This is the **only** component in the application that reads `shell.presentation`, and no page may
 * branch on it. Today one family exists, so this returns the Operational shell unconditionally;
 * adding the Focused presentation later is one branch here and zero page edits (L18 forbids building
 * it, or any shell preference, now).
 *
 * The prop names a *presentation family*, never a role — rail is not an operator permission and a
 * top bar is not a customer permission (L17). Until the Focused shell exists, customer users use
 * this shell filtered by capability, which is the ordinary server-side `NavigationBuilder` gate.
 */
export function AppShell({
    children,
    trail,
}: PropsWithChildren<{
    /**
     * The page's own segments of the breadcrumb (Direction D §13.2), after the workspace and the active
     * view: a record page names the project and the task it is, and the shell draws the one trail.
     * This is the only way a page contributes to the breadcrumb: a page never renders its own
     * `Breadcrumb` landmark (A13.12). Omitted, the trail ends at the active view as before.
     */
    trail?: BreadcrumbSegment[];
}>) {
    const { shell } = usePage<SharedPageProps>().props;

    switch (shell.presentation) {
        case 'operational':
        default:
            return <OperatorShell trail={trail}>{children}</OperatorShell>;
    }
}
