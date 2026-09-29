import { useForm } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { ConfirmationDialog } from '@/components/ui/confirmation-dialog';
import { Progress } from '@/components/ui/progress';
import { formatDate } from '@/lib/dates';
import { destroy } from '@/routes/projects/milestones';
import type { MilestoneItem } from '@/types/projects';

type MilestoneCardProps = {
    milestone: MilestoneItem;
    projectId: number;
    canManage: boolean;
    onEdit: () => void;
};

export function MilestoneCard({ milestone, projectId, canManage, onEdit }: MilestoneCardProps) {
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const form = useForm({});
    const errors: Record<string, string | undefined> = form.errors;

    return (
        <article
            aria-labelledby={`milestone-${milestone.id}-title`}
            className="space-y-4 rounded-md border border-border bg-card p-5 text-card-foreground"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2
                        id={`milestone-${milestone.id}-title`}
                        className="text-base leading-snug font-semibold"
                    >
                        {milestone.name}
                    </h2>
                    {milestone.description ? (
                        <p className="mt-1 text-sm text-muted-foreground">
                            {milestone.description}
                        </p>
                    ) : null}
                </div>
                <div className="flex shrink-0 items-center gap-3">
                    <span
                        className={
                            milestone.overdue
                                ? 'text-sm font-medium text-[var(--text-danger)]'
                                : 'text-sm text-muted-foreground'
                        }
                    >
                        Due {formatDate(milestone.dueDate)}
                    </span>
                    {canManage ? (
                        <div className="flex items-center gap-1">
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
                                    // Not optimistic: the card stays until the server confirms.
                                    form.delete(
                                        destroy.url({
                                            project: projectId,
                                            milestone: milestone.id,
                                        }),
                                        {
                                            preserveScroll: true,
                                            errorBag: 'deleteMilestone',
                                        },
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
                </div>
            </div>

            <div className="space-y-1">
                <div className="flex justify-between text-xs text-muted-foreground">
                    <span>
                        {milestone.taskCount} {milestone.taskCount === 1 ? 'task' : 'tasks'}
                    </span>
                    <span>{milestone.completion}% complete</span>
                </div>
                <Progress
                    value={milestone.completion}
                    label={`${milestone.name} completion`}
                    valueText={`${milestone.doneCount} of ${milestone.taskCount} tasks done`}
                />
            </div>
        </article>
    );
}
