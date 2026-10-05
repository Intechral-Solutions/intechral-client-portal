import { router, useForm } from '@inertiajs/react';
import { Clock, Pencil, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';

import { milestoneTaskText } from '@/components/projects/milestone-stages';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ConfirmationDialog } from '@/components/ui/confirmation-dialog';
import { Progress } from '@/components/ui/progress';
import { Status } from '@/components/ui/status';
import { formatDate } from '@/lib/dates';
import { complete, destroy, reopen } from '@/routes/projects/milestones';
import type { MilestoneItem } from '@/types/projects';

type MilestoneListItemProps = {
    milestone: MilestoneItem;
    projectId: number;
    /** The page's `manage` ability: the server's Settings-access answer (A9). */
    canManage: boolean;
    onEdit: () => void;
};

/**
 * EPIC-015 WP4 — one milestone on the Milestones page (§14.1): a ruled row, not a card.
 *
 * Two facts, never mixed (Q2, INV-P9):
 *   - **completion** is explicit: "Completed" with when and by whom, or open, or overdue (the
 *     server's flag: due before today and not completed). Only Complete/Reopen changes it;
 *   - **linked-task progress** is informational: "N of M linked tasks done", never "complete", so a
 *     milestone with every task done but nobody completing it still reads as open (or overdue).
 *
 * Complete/Reopen, Edit and Delete are offered only with the page's `manage` ability. Complete and
 * Reopen are one button on the existing routes; it never changes state itself: the row shows what the
 * server returns, and the button is `aria-disabled` and busy while the request is in flight, so a
 * double press sends one request. Being the same element either way, it keeps focus across the
 * change.
 */
export function MilestoneListItem({
    milestone,
    projectId,
    canManage,
    onEdit,
}: MilestoneListItemProps) {
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [toggling, setToggling] = useState(false);
    // Synchronous guard (the TaskCompleteAction pattern): state alone updates after the render, so
    // two activations in one tick could both pass it.
    const inFlight = useRef(false);
    const [toggleError, setToggleError] = useState<string | null>(null);
    const form = useForm({});
    const errors: Record<string, string | undefined> = form.errors;
    const completed = milestone.completedAt !== null;
    const titleId = `milestone-${milestone.id}-title`;

    function toggleCompletion() {
        if (inFlight.current) return;

        inFlight.current = true;
        const route = completed ? reopen : complete;
        setToggling(true);
        setToggleError(null);
        router.put(
            route.url({ project: projectId, milestone: milestone.id }),
            {},
            {
                // Keep the page mounted, so this button (now labelled the other way) keeps focus.
                preserveState: true,
                preserveScroll: true,
                onError: (failed) =>
                    setToggleError(
                        Object.values(failed)[0] ??
                            `The milestone could not be ${completed ? 'reopened' : 'completed'}.`,
                    ),
                onFinish: () => {
                    inFlight.current = false;
                    setToggling(false);
                },
            },
        );
    }

    return (
        <li
            aria-labelledby={titleId}
            data-milestone-state={completed ? 'completed' : milestone.overdue ? 'overdue' : 'open'}
            className="grid gap-x-6 gap-y-3 py-4 md:grid-cols-[minmax(0,1fr)_minmax(10rem,14rem)_auto]"
        >
            <div className="min-w-0 space-y-1">
                <h3 id={titleId} className="text-sm font-semibold break-words text-text">
                    {milestone.name}
                </h3>
                {milestone.description ? (
                    <p className="text-sm break-words text-text-secondary">
                        {milestone.description}
                    </p>
                ) : null}
                <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                    {completed ? (
                        <Status tone="success" glyph="check">
                            Completed
                        </Status>
                    ) : milestone.overdue ? (
                        <span className="inline-flex items-center gap-1 font-medium text-danger">
                            <Clock className="size-3.5 shrink-0" aria-hidden="true" />
                            Overdue
                        </span>
                    ) : (
                        <Status tone="neutral" glyph="circle">
                            Not completed
                        </Status>
                    )}
                    <span className="text-text-secondary">
                        Due{' '}
                        <time dateTime={milestone.dueDate}>{formatDate(milestone.dueDate)}</time>
                    </span>
                    {completed ? (
                        <span className="text-text-secondary">
                            Completed{' '}
                            <time dateTime={milestone.completedAt ?? undefined}>
                                {formatDate(milestone.completedAt!.slice(0, 10))}
                            </time>
                            {milestone.completedBy ? ` by ${milestone.completedBy.name}` : null}
                        </span>
                    ) : null}
                </p>
            </div>

            <div className="flex flex-col justify-center gap-1.5">
                <span className="text-xs text-text-secondary">{milestoneTaskText(milestone)}</span>
                {milestone.taskCount > 0 ? (
                    <Progress
                        value={milestone.doneCount}
                        max={milestone.taskCount}
                        label={`${milestone.name}: linked task progress`}
                        valueText={milestoneTaskText(milestone)}
                    />
                ) : null}
            </div>

            {canManage ? (
                <div className="flex items-start gap-1 md:justify-end">
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        aria-label={`${completed ? 'Reopen' : 'Complete'} ${milestone.name}`}
                        // `aria-disabled`, not `disabled`, while in flight: a disabled button drops
                        // keyboard focus to the document (the Tasks Complete control's rule).
                        aria-disabled={toggling || undefined}
                        aria-busy={toggling || undefined}
                        className={toggling ? 'opacity-60' : undefined}
                        onClick={toggleCompletion}
                    >
                        {completed ? 'Reopen' : 'Complete'}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={`Edit ${milestone.name}`}
                        onClick={onEdit}
                    >
                        <Pencil aria-hidden="true" />
                    </Button>
                    <ConfirmationDialog
                        open={confirmingDelete}
                        onOpenChange={(next) => {
                            setConfirmingDelete(next);
                            if (!next) form.clearErrors();
                        }}
                        title={`Delete ${milestone.name}?`}
                        description="Tasks on this milestone stay, but lose their milestone. This cannot be undone."
                        confirmLabel="Delete milestone"
                        processing={form.processing}
                        error={errors.delete}
                        onConfirm={() =>
                            // Not optimistic: the row stays until the server confirms.
                            form.delete(
                                destroy.url({ project: projectId, milestone: milestone.id }),
                                { preserveScroll: true, errorBag: 'deleteMilestone' },
                            )
                        }
                    >
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={`Delete ${milestone.name}`}
                        >
                            <Trash2 aria-hidden="true" />
                        </Button>
                    </ConfirmationDialog>
                </div>
            ) : null}

            {toggleError ? (
                <Alert variant="danger" className="md:col-span-3">
                    Could not change “{milestone.name}”: {toggleError}
                </Alert>
            ) : null}
        </li>
    );
}
