import { useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { store as storeTask } from '@/routes/projects/tasks';

type QuickAddTaskProps = {
    projectId: number;
    columnId: number;
    open: boolean;
    onClose: () => void;
};

/**
 * The board's per-column task-create form (EPIC-011E §11). Server-confirmed, not optimistic:
 * creation needs the server's id and dense position. Closes and returns focus to its own
 * "Add task" toggle on success (the toggle owns that focus-return, see board-column.tsx); stays
 * open with the validation error inline on failure. Only one instance is ever open at a time
 * (enforced by the parent, which controls `open`).
 */
export function QuickAddTask({ projectId, columnId, open, onClose }: QuickAddTaskProps) {
    const form = useForm({ title: '', column_id: columnId, priority: 'medium' as const });
    const inputRef = useRef<HTMLInputElement>(null);

    // A synchronous guard against a same-tick double submit (double click, or Enter held while
    // repeating): `form.processing` only disables the button after React commits the re-render
    // triggered by useForm's own onBefore, which is too late to stop a second `submit()` call
    // that happens before that commit (EPIC-011E Amendment 6, Fix 2). Never creates a task
    // optimistically — this only decides whether a second `form.post` is sent at all.
    const submittingRef = useRef(false);

    useEffect(() => {
        if (open) inputRef.current?.focus();
    }, [open]);

    if (!open) return null;

    function submit(event: FormEvent) {
        event.preventDefault();

        if (submittingRef.current) return;
        submittingRef.current = true;

        form.post(storeTask.url({ project: projectId }), {
            preserveScroll: true,
            only: ['columns', 'flash'],
            onSuccess: () => {
                form.reset();
                onClose();
            },
            // The single terminal callback for every outcome (success, a validation error that
            // leaves the form open and editable, an HTTP/server error, a network failure, or a
            // cancellation): the one correct place to release the guard so the form can submit
            // again once this request has actually settled.
            onFinish: () => {
                submittingRef.current = false;
            },
        });
    }

    return (
        <form onSubmit={submit} className="space-y-2 p-2 pt-0">
            <div>
                <label htmlFor={`quick-add-${columnId}`} className="sr-only">
                    New task title
                </label>
                <Input
                    ref={inputRef}
                    id={`quick-add-${columnId}`}
                    type="text"
                    value={form.data.title}
                    onChange={(event) => form.setData('title', event.target.value)}
                    placeholder="Task title…"
                    required
                />
                {form.errors.title ? (
                    <p role="alert" className="mt-1 text-xs text-[var(--text-danger)]">
                        {form.errors.title}
                    </p>
                ) : null}
            </div>
            <div className="flex gap-1">
                <Button type="submit" size="sm" disabled={form.processing}>
                    Add
                </Button>
                <Button type="button" variant="ghost" size="sm" onClick={onClose}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}
