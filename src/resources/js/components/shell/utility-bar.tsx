import type { ReactNode } from 'react';

import type { Workspace } from '@/types';

import { Breadcrumb, type BreadcrumbSegment } from './breadcrumb';

/**
 * The 48px utility bar (Direction D §6).
 *
 * It **never carries workspace navigation** — that is the rail's job and the separation is the
 * point. It carries the breadcrumb, and on its right whatever the shell passes as `children`: as of
 * WP6 that is the `TimerPill`, the one global timer affordance (Direction D §12.1). Search/command is
 * NEXT and not rendered.
 */
export function UtilityBar({
    workspace,
    collapsed,
    trail,
    children,
}: {
    workspace: Workspace | null;
    collapsed: boolean;
    trail?: BreadcrumbSegment[];
    children?: ReactNode;
}) {
    return (
        <header data-shell-utility>
            <div className="min-w-0 flex-1">
                <Breadcrumb workspace={workspace} collapsed={collapsed} trail={trail} />
            </div>
            {children}
        </header>
    );
}
