import type { ReactNode } from 'react';

import type { Workspace } from '@/types';

import { Breadcrumb } from './breadcrumb';

/**
 * The 48px utility bar (Direction D §6).
 *
 * It **never carries workspace navigation** — that is the rail's job and the separation is the
 * point. In this epic it carries the breadcrumb only: search/command is NEXT and not rendered, and
 * the timer pill arrives in WP6, which is why the WP5-era `RunningTimerBar` still renders below.
 */
export function UtilityBar({
    workspace,
    collapsed,
    children,
}: {
    workspace: Workspace | null;
    collapsed: boolean;
    children?: ReactNode;
}) {
    return (
        <header data-shell-utility>
            <div className="min-w-0 flex-1">
                <Breadcrumb workspace={workspace} collapsed={collapsed} />
            </div>
            {children}
        </header>
    );
}
