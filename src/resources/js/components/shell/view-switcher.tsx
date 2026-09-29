import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { focusRing } from '@/components/ui/control-metrics';
import { cn } from '@/lib/utils';
import type { ContextItem, Workspace } from '@/types';

/**
 * The current view as a menu, shown in the breadcrumb only while the drawer is collapsed
 * (Direction D §5.4).
 *
 * A Radix `DropdownMenu` is a genuine composite widget, so its arrow-key behaviour is correct here
 * and does not contradict L11 — which forbids retrofitting arrow keys onto plain navigation links,
 * not using a real menu. It projects the same `context` the drawer would, minus actions, which are
 * not views.
 */
export function ViewSwitcher({ workspace, active }: { workspace: Workspace; active: ContextItem }) {
    const views = workspace.context
        .filter((section) => section.kind !== 'actions')
        .flatMap((section) => section.items);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                className={cn(
                    'flex min-w-0 items-center gap-1 rounded-control px-1 py-0.5 font-medium text-text transition-colors duration-motion-fast hover:bg-surface-hover',
                    focusRing,
                )}
                aria-label={`${workspace.label} views: ${active.label}`}
            >
                <span className="truncate">{active.label}</span>
                <ChevronDown className="h-3.5 w-3.5 shrink-0 text-text-muted" aria-hidden="true" />
            </DropdownMenuTrigger>

            <DropdownMenuContent align="start" className="min-w-52">
                {views.map((item) => (
                    <DropdownMenuItem key={item.key} asChild>
                        {item.visit === 'inertia' ? (
                            <Link href={item.href} className="block">
                                {item.label}
                            </Link>
                        ) : (
                            <a href={item.href} className="block">
                                {item.label}
                            </a>
                        )}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
