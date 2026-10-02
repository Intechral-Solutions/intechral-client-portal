import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { ConfirmationDialog } from '@/components/ui/confirmation-dialog';

type TaskDeleteButtonProps = {
    /**
     * The delete route for this kind of task: `tasks.destroy` for a standalone task, `projects.tasks.destroy`
     * for a board task. The server authorizes each, and each redirects to where the task's own page no
     * longer exists (the Tasks list, the board); there is no other delete path.
     */
    url: string;
    taskTitle: string;
};

/**
 * Task delete (D4, D1), shared by both detail kinds: only rendered where the server's ability allows
 * it, confirmed, never optimistic. A task referenced by any `TimeEntry` is refused by the server
 * (the recorded-time guard stays authoritative); the dialog shows that refusal in place and nothing
 * is removed. On success the server redirects away from the page that no longer exists.
 */
export function TaskDeleteButton({ url, taskTitle }: TaskDeleteButtonProps) {
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
                // is a server-issued redirect.
                form.delete(url, { preserveScroll: true })
            }
        >
            <Button
                type="button"
                variant="outline"
                className="border-[var(--border-danger)] text-[var(--text-danger)]"
            >
                Delete task
            </Button>
        </ConfirmationDialog>
    );
}
