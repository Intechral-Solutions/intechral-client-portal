import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { ConfirmationDialog } from '@/components/ui/confirmation-dialog';
import { destroy } from '@/routes/projects/tasks';

type TaskDeleteButtonProps = {
    projectId: number;
    taskId: number;
    taskTitle: string;
};

/**
 * Task delete (D4, D1): manager-only, confirmed, never optimistic. A task referenced by any
 * `TimeEntry` is refused by the server; the dialog shows that refusal in place and nothing is
 * removed. On success the server redirects to the board.
 */
export function TaskDeleteButton({ projectId, taskId, taskTitle }: TaskDeleteButtonProps) {
    const [open, setOpen] = useState(false);
    const form = useForm({});
    const errors: Record<string, string | undefined> = form.errors;

    return (
        <ConfirmationDialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) form.clearErrors();
            }}
            title="Delete this task?"
            description={`This permanently removes "${taskTitle}". It cannot be undone.`}
            confirmLabel="Delete task"
            processing={form.processing}
            error={errors.delete}
            onConfirm={() =>
                // Not optimistic: the task stays on screen until the server confirms. A refusal
                // (recorded time, D4) comes back as an error and the dialog stays open. Success
                // is a server-issued redirect to the board.
                form.delete(destroy.url({ project: projectId, task: taskId }), {
                    preserveScroll: true,
                })
            }
        >
            <Button
                type="button"
                variant="outline"
                className="w-full border-[var(--border-danger)] text-[var(--text-danger)]"
            >
                Delete task
            </Button>
        </ConfirmationDialog>
    );
}
