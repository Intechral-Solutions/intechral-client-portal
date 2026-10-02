import { useForm } from '@inertiajs/react';
import { useRef } from 'react';

import { FormFieldError } from '@/components/forms/form-field-error';
import {
    DescriptionField,
    DueDateField,
    PriorityField,
    TitleField,
} from '@/components/tasks/task-fields';
import { FormDialog } from '@/components/ui/form-dialog';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { update } from '@/routes/tasks';
import type { TaskPriority } from '@/types/projects';
import type { StandaloneTaskDetail, TaskFormOptions } from '@/types/tasks';

type StandaloneEditData = {
    title: string;
    description: string;
    priority: TaskPriority;
    status: 'todo' | 'in_progress' | 'done';
    due_date: string;
};

const FIELDS = ['title', 'status', 'priority', 'due_date', 'description'] as const;

/** The first field of this form that has an error, so a failed submit can move focus to it. */
function firstErrorField(errors: Record<string, string | undefined>) {
    return FIELDS.find((field) => errors[field]);
}

function toFormData(task: StandaloneTaskDetail): StandaloneEditData {
    return {
        title: task.title,
        description: task.description ?? '',
        priority: task.priority,
        status: task.statusValue,
        due_date: task.dueDate ?? '',
    };
}

const id = (field: string) => `standalone-edit-${field}`;

/**
 * The standalone task edit dialog (EPIC-014 §10, §14.2): title, status, priority, due date and
 * description, one `useForm` put to `tasks.update`. It shares the title, priority, due date and
 * description fields with the other task forms (`task-fields`) and keeps what is standalone-specific
 * explicit: a **raw status** (a standalone task's status is its own field, so editing it is allowed,
 * WP2), unlike a board task, whose completion is its column's.
 *
 * It sends **no assignee**: an absent `assignee_id` leaves it alone (WP2), and assignment goes through
 * the narrow assign control on the detail, so this dialog can never hand a task over by accident. It
 * never sends a project, ticket or creator either (INV-9). The page mounts it only while open, so each
 * opening starts from the task's current values.
 */
export function StandaloneEditDialog({
    open,
    onOpenChange,
    task,
    options,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    task: StandaloneTaskDetail;
    options: TaskFormOptions;
}) {
    const form = useForm<StandaloneEditData>(toFormData(task));
    const errors: Record<string, string | undefined> = form.errors;
    const submitting = useRef(false);

    function handleOpenChange(next: boolean) {
        if (!next) {
            form.reset();
            form.clearErrors();
        }

        onOpenChange(next);
    }

    function submit() {
        if (submitting.current) return;
        submitting.current = true;

        form.transform((data) => ({
            ...data,
            due_date: data.due_date === '' ? null : data.due_date,
        }));
        form.put(update.url(task.id), {
            preserveScroll: true,
            onSuccess: () => handleOpenChange(false),
            onError: (submitted) => {
                const field = firstErrorField(submitted);
                if (field) document.getElementById(id(field))?.focus();
            },
            onFinish: () => {
                submitting.current = false;
            },
        });
    }

    return (
        <FormDialog
            open={open}
            onOpenChange={handleOpenChange}
            title="Edit task"
            description="Change this task's title, status, priority, due date or description."
            submitLabel="Save changes"
            onSubmit={submit}
            processing={form.processing}
        >
            <TitleField
                id={id('title')}
                value={form.data.title}
                onChange={(value) => form.setData('title', value)}
                error={errors.title}
            />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                    <Label htmlFor={id('status')}>Status</Label>
                    <NativeSelect
                        id={id('status')}
                        className="w-full"
                        value={form.data.status}
                        onChange={(event) =>
                            form.setData(
                                'status',
                                event.target.value as StandaloneEditData['status'],
                            )
                        }
                        aria-invalid={Boolean(errors.status)}
                        aria-describedby={errors.status ? `${id('status')}-error` : undefined}
                    >
                        {options.statuses.map((status) => (
                            <option key={status.value} value={status.value}>
                                {status.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <FormFieldError id={`${id('status')}-error`} message={errors.status} />
                </div>

                <PriorityField
                    id={id('priority')}
                    value={form.data.priority}
                    onChange={(value) => form.setData('priority', value)}
                    options={options.priorities}
                    error={errors.priority}
                />
            </div>

            <DueDateField
                id={id('due_date')}
                value={form.data.due_date}
                onChange={(value) => form.setData('due_date', value)}
                error={errors.due_date}
            />

            <DescriptionField
                id={id('description')}
                rows={4}
                value={form.data.description}
                onChange={(value) => form.setData('description', value)}
                error={errors.description}
            />
        </FormDialog>
    );
}
