import { useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
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

    useEffect(() => {
        if (open) inputRef.current?.focus();
    }, [open]);

    if (!open) return null;

    function submit(event: FormEvent) {
        event.preventDefault();

        form.post(storeTask.url({ project: projectId }), {
            preserveScroll: true,
            only: ['columns', 'flash'],
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    }

    return (
        <form onSubmit={submit} className="space-y-2 p-2 pt-0">
            <div>
                <label htmlFor={`quick-add-${columnId}`} className="sr-only">
                    New task title
                </label>
                <input
                    ref={inputRef}
                    id={`quick-add-${columnId}`}
                    type="text"
                    value={form.data.title}
                    onChange={(event) => form.setData('title', event.target.value)}
                    placeholder="Task title…"
                    required
                    className="block w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
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
