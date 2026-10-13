import { focusRing } from '@/components/ui/control-metrics';
import { cn } from '@/lib/utils';
import type { Workspace } from '@/types';

import { ShellLink } from './shell-link';

/**
 * The customer top navigation (Direction D §7, §8).
 *
 * It renders the Focused workspace list exactly as the server projected it — in the server's order,
 * with its labels, destinations, visit modes and current item. It filters nothing, infers no
 * permission and computes no active state (L14). The current item carries `aria-current="page"` and
 * the 2px `accent-line` underline, which is the customer shell's only "you are here" mark.
 *
 * Ordinary links in normal Tab order: no roving tabindex and no arrow-key handling (§14.3). CSS hides
 * the whole list below 900px, where the menu sheet carries the same links, so the two are never both
 * in the accessibility tree.
 */
export function TopNav({ workspaces }: { workspaces: Workspace[] }) {
    return (
        <nav aria-label="Main" data-shell-topnav>
            {workspaces.map((workspace) => (
                <ShellLink
                    key={workspace.key}
                    href={workspace.href}
                    visit={workspace.visit}
                    // Server-computed. Never derived from the URL here.
                    aria-current={workspace.isActive ? 'page' : undefined}
                    data-shell-nav-row
                    className={cn(
                        'flex items-center border-b-2 px-3 text-sm transition-colors duration-motion-fast',
                        workspace.isActive
                            ? 'border-accent-line font-semibold text-text'
                            : 'border-transparent text-text-secondary hover:text-text',
                        focusRing,
                    )}
                >
                    {workspace.label}
                </ShellLink>
            ))}
        </nav>
    );
}
