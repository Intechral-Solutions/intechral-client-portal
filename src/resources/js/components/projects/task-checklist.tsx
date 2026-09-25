import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';

import { FormFieldError } from '@/components/forms/form-field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { destroy, store, toggle } from '@/routes/projects/tasks/checklist';
import type { TaskChecklistItemData } from '@/types/projects';

type TaskChecklistProps = {
    projectId: number;
    taskId: number;
    items: TaskChecklistItemData[];
    /** Manager/admin only (D5): add and remove. Toggling is open to any viewer of the page. */
    canAuthor: boolean;
};

/**
 * The task checklist (EPIC-011E §14, D5). Toggling is a narrow optimistic interaction —
 * `router.optimistic` flips one item and reconciles against the authoritative `checklist` prop,
 * the same pattern the board's move uses — because it is idempotent and safe to replay. Add and
 * remove are server-confirmed: a new item needs its server id, and removing the wrong item is
 * not something worth racing.
 */
export function TaskChecklist({ projectId, taskId, items, canAuthor }: TaskChecklistProps) {
    const [pendingIds, setPendingIds] = useState<number[]>([]);
    const [removingId, setRemovingId] = useState<number | null>(null);
    const [title, setTitle] = useState('');
    const [adding, setAdding] = useState(false);
    const [addError, setAddError] = useState<string | undefined>(undefined);

    const done = items.filter((item) => item.completed).length;
    const total = items.length;

    // The id of the control that should hold focus once the request that took focus away has
    // settled. A disabled element cannot receive focus, and every control here is disabled while
    // its own request is in flight, so focusing from inside `onFinish` is too early: React has
    // not yet committed the re-enable at that point and the `.focus()` call is silently dropped,
    // stranding focus on <body>. The effect below runs after that commit instead (EPIC-011E WP10).
    const refocusIdRef = useRef<string | null>(null);

    useEffect(() => {
        const id = refocusIdRef.current;
        if (id === null) return;

        const target = document.getElementById(id);
        // Still disabled: a later commit will re-run this effect and restore focus then.
        if (target === null || (target as HTMLInputElement).disabled) return;

        refocusIdRef.current = null;
        target.focus();
    });

    function toggleItem(item: TaskChecklistItemData) {
        if (pendingIds.includes(item.id) || removingId === item.id) return;

        // Keyboard users toggle from the checkbox itself; it is disabled for the duration of the
        // request, so focus has to be put back deliberately once it is enabled again.
        refocusIdRef.current = `checklist-item-${item.id}`;

        const nextCompleted = !item.completed;
        setPendingIds((ids) => [...ids, item.id]);

        router
            .optimistic((props: { checklist: TaskChecklistItemData[] }) => ({
                checklist: props.checklist.map((candidate) =>
                    candidate.id === item.id
                        ? { ...candidate, completed: nextCompleted }
                        : candidate,
                ),
            }))
            .put(
                toggle.url({ project: projectId, task: taskId, item: item.id }),
                { completed: nextCompleted },
                {
                    only: ['checklist'],
                    preserveScroll: true,
                    preserveState: true,
                    onFinish: () => setPendingIds((ids) => ids.filter((id) => id !== item.id)),
                },
            );
    }

    function submitAdd(event: FormEvent) {
        event.preventDefault();
        setAdding(true);
        setAddError(undefined);
        // The field is disabled while the add is in flight; put focus back on it afterwards so a
        // keyboard user can type the next item without re-tabbing.
        refocusIdRef.current = 'checklist-add-title';

        router.post(
            store.url({ project: projectId, task: taskId }),
            { title },
            {
                preserveScroll: true,
                only: ['checklist', 'flash'],
                onSuccess: () => setTitle(''),
                onError: (errors) => setAddError(errors.title),
                onFinish: () => setAdding(false),
            },
        );
    }

    function removeItem(item: TaskChecklistItemData, index: number) {
        setRemovingId(item.id);

        router.delete(destroy.url({ project: projectId, task: taskId, item: item.id }), {
            preserveScroll: true,
            only: ['checklist', 'flash'],
            onSuccess: () => {
                // The removed row's own controls are gone, so focus moves to its neighbour (or
                // the add field when it was the last item). Routed through the same effect as the
                // other two paths so a neighbour that happens to be mid-request is not skipped.
                const next = items[index + 1] ?? items[index - 1];
                refocusIdRef.current = next ? `checklist-item-${next.id}` : 'checklist-add-title';
            },
            onFinish: () => setRemovingId(null),
        });
    }

    return (
        <div>
            {total > 0 ? (
                <div className="mb-3 space-y-1">
                    <div className="flex justify-between text-xs text-muted-foreground">
                        <span aria-live="polite">
                            {done} of {total} complete
                        </span>
                        <span>{Math.round((done / total) * 100)}%</span>
                    </div>
                    <Progress
                        value={done}
                        max={total}
                        label="Checklist progress"
                        valueText={`${done} of ${total} complete`}
                    />
                </div>
            ) : null}

            {total === 0 && !canAuthor ? (
                <p className="text-sm text-muted-foreground italic">No checklist items.</p>
            ) : null}

            {total > 0 ? (
                <ul className="space-y-2">
                    {items.map((item, index) => {
                        const busy = pendingIds.includes(item.id) || removingId === item.id;

                        return (
                            <li key={item.id} className="flex items-center gap-2">
                                <input
                                    id={`checklist-item-${item.id}`}
                                    type="checkbox"
                                    checked={item.completed}
                                    disabled={busy}
                                    onChange={() => toggleItem(item)}
                                    className="h-4 w-4 rounded border-input"
                                />
                                <label
                                    htmlFor={`checklist-item-${item.id}`}
                                    className={
                                        item.completed
                                            ? 'text-sm text-muted-foreground line-through'
                                            : 'text-sm text-foreground'
                                    }
                                >
                                    {item.title}
                                </label>
                                {canAuthor ? (
                                    <button
                                        type="button"
                                        aria-label={`Remove "${item.title}"`}
                                        disabled={busy}
                                        className="ml-auto text-xs text-muted-foreground hover:text-[var(--text-danger)] disabled:opacity-50"
                                        onClick={() => removeItem(item, index)}
                                    >
                                        Remove
                                    </button>
                                ) : null}
                            </li>
                        );
                    })}
                </ul>
            ) : null}

            {canAuthor ? (
                <form onSubmit={submitAdd} className="mt-3 flex items-start gap-2">
                    <div className="flex-1">
                        <Label htmlFor="checklist-add-title" className="sr-only">
                            Add a checklist item
                        </Label>
                        <Input
                            id="checklist-add-title"
                            placeholder="Add an item…"
                            value={title}
                            maxLength={255}
                            disabled={adding}
                            onChange={(event) => setTitle(event.target.value)}
                            aria-invalid={Boolean(addError)}
                            aria-describedby={addError ? 'checklist-add-error' : undefined}
                        />
                        <FormFieldError id="checklist-add-error" message={addError} />
                    </div>
                    <Button type="submit" disabled={adding || title.trim() === ''}>
                        Add
                    </Button>
                </form>
            ) : null}
        </div>
    );
}
