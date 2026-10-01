import { useForm, usePage } from '@inertiajs/react';
import { useRef } from 'react';

import { FormFieldError } from '@/components/forms/form-field-error';
import { FormDialog } from '@/components/ui/form-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/tasks';
import type { SharedPageProps } from '@/types';
import type { TaskPriority } from '@/types/projects';
import type { TaskCreateOptions } from '@/types/tasks';

type CreateTaskFormData = {
    title: string;
    description: string;
    /** The form only ever offers "Me" or "Unassigned"; the server enforces exactly that (D3, A7). */
    assignee_id: number | '';
    priority: TaskPriority;
    status: 'todo' | 'in_progress' | 'done';
    due_date: string;
};

const EMPTY: CreateTaskFormData = {
    title: '',
    description: '',
    assignee_id: '',
    priority: 'medium',
    status: 'todo',
    due_date: '',
};

/**
 * The standalone-task create dialog (EPIC-014 §14.1), replacing the inline collapsible form with the
 * same fields on a `FormDialog`. It posts to the existing `tasks.store`: there is no second create
 * endpoint, no ticket-task creation and no assignee beyond the actor or nobody (the server enforces
 * the set; the form only offers it). Server-confirmed, not optimistic.
 *
 * The page owns `open`, so the header action and the empty state's action open the same dialog and
 * focus returns to whichever opened it. A failed create keeps the dialog open with its errors and the
 * draft; closing it by any route discards both.
 */
export function CreateTaskDialog({
    open,
    onOpenChange,
    options,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    options: TaskCreateOptions;
}) {
    const { auth } = usePage<SharedPageProps>().props;
    const form = useForm<CreateTaskFormData>(EMPTY);
    const errors: Record<string, string | undefined> = form.errors;
    // A same-tick double-submit guard: `form.processing` only disables the button after React commits
    // the re-render `useForm`'s own onBefore triggers, one tick too late to stop a second `submit()`
    // from a fast double click (EPIC-011E Amendment 6, Fix 2 pattern).
    const submittingRef = useRef(false);

    function handleOpenChange(next: boolean) {
        if (!next) {
            form.reset();
            form.clearErrors();
        }

        onOpenChange(next);
    }

    function submit() {
        if (submittingRef.current) return;
        submittingRef.current = true;

        form.transform((data) => ({
            ...data,
            assignee_id: data.assignee_id === '' ? null : Number(data.assignee_id),
        }));
        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => handleOpenChange(false),
            onFinish: () => {
                submittingRef.current = false;
            },
        });
    }

    return (
        <FormDialog
            open={open}
            onOpenChange={handleOpenChange}
            title="New task"
            description="A standalone task of your own. Assign it to yourself or leave it unassigned."
            submitLabel="Create task"
            onSubmit={submit}
            processing={form.processing}
        >
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="space-y-2 sm:col-span-2">
                    <Label htmlFor="new-task-title">
                        Title <span aria-hidden="true">*</span>
                    </Label>
                    <Input
                        id="new-task-title"
                        value={form.data.title}
                        onChange={(event) => form.setData('title', event.target.value)}
                        required
                        maxLength={255}
                        aria-invalid={Boolean(errors.title)}
                        aria-describedby={errors.title ? 'new-task-title-error' : undefined}
                    />
                    <FormFieldError id="new-task-title-error" message={errors.title} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="new-task-assignee">Assignee</Label>
                    <NativeSelect
                        id="new-task-assignee"
                        className="w-full"
                        value={form.data.assignee_id}
                        onChange={(event) =>
                            form.setData(
                                'assignee_id',
                                event.target.value === '' ? '' : Number(event.target.value),
                            )
                        }
                    >
                        <option value="">Unassigned</option>
                        {auth.user ? <option value={auth.user.id}>Me</option> : null}
                    </NativeSelect>
                    <FormFieldError id="new-task-assignee-error" message={errors.assignee_id} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="new-task-priority">Priority</Label>
                    <NativeSelect
                        id="new-task-priority"
                        className="w-full"
                        value={form.data.priority}
                        onChange={(event) =>
                            form.setData('priority', event.target.value as TaskPriority)
                        }
                    >
                        {options.priorities.map((priority) => (
                            <option key={priority.value} value={priority.value}>
                                {priority.label}
                            </option>
                        ))}
                    </NativeSelect>
                </div>

                <div className="space-y-2">
                    <Label htmlFor="new-task-status">Status</Label>
                    <NativeSelect
                        id="new-task-status"
                        className="w-full"
                        value={form.data.status}
                        onChange={(event) =>
                            form.setData(
                                'status',
                                event.target.value as CreateTaskFormData['status'],
                            )
                        }
                    >
                        {options.statuses.map((status) => (
                            <option key={status.value} value={status.value}>
                                {status.label}
                            </option>
                        ))}
                    </NativeSelect>
                </div>

                <div className="space-y-2">
                    <Label htmlFor="new-task-due-date">Due date</Label>
                    <Input
                        id="new-task-due-date"
                        type="date"
                        value={form.data.due_date}
                        onChange={(event) => form.setData('due_date', event.target.value)}
                    />
                    <FormFieldError id="new-task-due-date-error" message={errors.due_date} />
                </div>

                <div className="space-y-2 sm:col-span-2">
                    <Label htmlFor="new-task-description">Description</Label>
                    <Textarea
                        id="new-task-description"
                        rows={2}
                        value={form.data.description}
                        onChange={(event) => form.setData('description', event.target.value)}
                        aria-invalid={Boolean(errors.description)}
                        aria-describedby={
                            errors.description ? 'new-task-description-error' : undefined
                        }
                    />
                    <FormFieldError id="new-task-description-error" message={errors.description} />
                </div>
            </div>
        </FormDialog>
    );
}
