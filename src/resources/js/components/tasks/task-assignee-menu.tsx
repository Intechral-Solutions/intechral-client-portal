import { Check, ChevronDown, UserRound } from 'lucide-react';

import type { AssigneeChoice } from '@/components/tasks/task-assignee-choices';
import { Avatar } from '@/components/ui/avatar';
import { focusRing } from '@/components/ui/control-metrics';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItemIndicator,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { UserRef } from '@/types/projects';

const NONE = 'none';

/** The keys on a menu button that open its menu (Radix: Enter, Space, ArrowDown; ArrowUp is held with them). */
const OPEN_KEYS: ReadonlySet<string> = new Set(['Enter', ' ', 'ArrowDown', 'ArrowUp']);

/**
 * EPIC-014 R6 — the single-row assignment control (§7.3): who holds this task, and the choices the
 * server offered. It is the one affordance both the Tasks list and the task detail use.
 *
 * It is a menu button, a real `button` that opens a radio menu (Unassigned first, then the choices,
 * the current holder checked), so it is operable with Enter/Space and the arrow keys and Radix returns
 * focus to it when the menu closes. The accessible name carries both the current holder and the task it
 * changes ("Assignee: Max. Change assignee of “Pack the van”"), so a row's control is never an anonymous
 * "Assignee". The mark beside the name is decorative, so the name is not announced twice.
 *
 * It decides nothing: `choices` is what the server allowed (the caller renders it only where
 * `abilities.assign` is true) and `onAssign` is called only for a *change*; choosing the current value
 * again sends nothing, which is also what keeps a departed holder from being re-assigned as if new.
 * While a request is in flight it is `aria-disabled`, not `disabled`, so a keyboard user keeps their
 * place, and it will not open (only the opening keys are held back: Tab still moves on). At S the name is read by assistive technology only and the mark alone is
 * drawn, so a list row's second band does not grow (D9).
 */
export function TaskAssigneeMenu({
    taskTitle,
    assignee,
    choices,
    pending,
    onAssign,
    compactAtSmall = false,
    className,
}: {
    taskTitle: string;
    assignee: UserRef | null;
    choices: readonly AssigneeChoice[];
    pending: boolean;
    onAssign: (userId: number | null) => void;
    /** Draw the mark alone at S, the name staying for assistive technology: a list row's second band (D9). */
    compactAtSmall?: boolean;
    className?: string;
}) {
    const value = assignee === null ? NONE : String(assignee.id);
    const holder = assignee?.name ?? 'Unassigned';

    function guardPointer(event: { preventDefault: () => void }) {
        if (pending) event.preventDefault();
    }

    // Only the keys that would open the menu: Tab, Escape and the rest stay the browser's and Radix's.
    function guardKey(event: { key: string; preventDefault: () => void }) {
        if (pending && OPEN_KEYS.has(event.key)) event.preventDefault();
    }

    function change(next: string) {
        if (next === value) return;

        onAssign(next === NONE ? null : Number(next));
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild onPointerDown={guardPointer} onKeyDown={guardKey}>
                <button
                    type="button"
                    aria-label={`Assignee: ${holder}. Change assignee of “${taskTitle}”`}
                    aria-disabled={pending || undefined}
                    aria-busy={pending || undefined}
                    data-cell="assignee"
                    className={cn(
                        'inline-flex max-w-full items-center gap-1.5 rounded-control px-1 py-0.5 text-sm text-text-secondary hover:bg-surface-hover',
                        pending && 'opacity-60',
                        focusRing,
                        className,
                    )}
                >
                    {assignee ? (
                        <Avatar name={assignee.name} size="sm" decorative />
                    ) : (
                        <span
                            aria-hidden="true"
                            className="inline-flex size-6 shrink-0 items-center justify-center rounded-full border border-dashed border-rule-control text-text-muted"
                        >
                            <UserRound className="size-3.5" />
                        </span>
                    )}
                    <span
                        className={cn(
                            'min-w-0 truncate',
                            compactAtSmall && 'max-md:sr-only',
                            assignee ? 'text-inherit' : 'text-text-muted',
                        )}
                    >
                        {holder}
                    </span>
                    <ChevronDown
                        aria-hidden="true"
                        className={cn('size-3.5 shrink-0', compactAtSmall && 'max-md:hidden')}
                    />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
                <DropdownMenuRadioGroup value={value} onValueChange={change}>
                    <DropdownMenuRadioItem value={NONE} className={itemClass}>
                        <Checked />
                        Unassigned
                    </DropdownMenuRadioItem>
                    {choices.map((choice) => (
                        <DropdownMenuRadioItem
                            key={choice.id}
                            value={String(choice.id)}
                            className={itemClass}
                        >
                            <Checked />
                            {choice.label}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** A fixed-width slot, so labels line up whether or not the item is checked; the check is the visible state. */
function Checked() {
    return (
        <span
            className="inline-flex size-4 shrink-0 items-center justify-center"
            aria-hidden="true"
        >
            <DropdownMenuItemIndicator>
                <Check className="size-3.5" />
            </DropdownMenuItemIndicator>
        </span>
    );
}

const itemClass =
    'flex cursor-pointer items-center gap-2 rounded-control py-2 pr-3 pl-2 text-sm focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus data-[highlighted]:bg-surface-hover data-[state=checked]:font-medium';
