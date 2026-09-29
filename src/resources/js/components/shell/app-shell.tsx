import { usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import type { SharedPageProps } from '@/types';

import { OperatorShell } from './operator-shell';

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
export function AppShell({ children }: PropsWithChildren) {
    const { shell } = usePage<SharedPageProps>().props;

    switch (shell.presentation) {
        case 'operational':
        default:
            return <OperatorShell>{children}</OperatorShell>;
    }
}
