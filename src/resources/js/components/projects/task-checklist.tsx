import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

import { FormFieldError } from '@/components/forms/form-field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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

    function toggleItem(item: TaskChecklistItemData) {
        if (pendingIds.includes(item.id) || removingId === item.id) return;

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

        router.post(
            store.url({ project: projectId, task: taskId }),
            { title },
            {
                preserveScroll: true,
                only: ['checklist', 'flash'],
                onSuccess: () => setTitle(''),
                onError: (errors) => setAddError(errors.title),
                onFinish: () => {
                    setAdding(false);
                    document.getElementById('checklist-add-title')?.focus();
                },
            },
        );
    }

    function removeItem(item: TaskChecklistItemData, index: number) {
        setRemovingId(item.id);

        router.delete(destroy.url({ project: projectId, task: taskId, item: item.id }), {
            preserveScroll: true,
            only: ['checklist', 'flash'],
            onSuccess: () => {
                const next = items[index + 1] ?? items[index - 1];
                const targetId = next ? `checklist-item-${next.id}` : 'checklist-add-title';
                document.getElementById(targetId)?.focus();
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
