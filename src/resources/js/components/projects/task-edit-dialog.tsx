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
import { update } from '@/routes/projects/tasks';
import type { TaskDetail, TaskEditOptions, TaskPriority, UserRef } from '@/types/projects';

/** The form values, exactly as they are submitted (snake_case keys, string inputs). */
type TaskEditFormData = {
    title: string;
    description: string;
    priority: TaskPriority;
    assignee_id: number | '';
    milestone_id: number | '';
    due_date: string;
};

const FIELDS = [
    'title',
    'description',
    'priority',
    'assignee_id',
    'milestone_id',
    'due_date',
] as const;

/** The first field of this form that has an error, so a failed submit can move focus to it. */
function firstTaskErrorField(errors: Record<string, string | undefined>) {
    return FIELDS.find((field) => errors[field]);
}

function toFormData(task: TaskDetail): TaskEditFormData {
    return {
        title: task.title,
        description: task.description ?? '',
        priority: task.priority,
        assignee_id: task.assignee?.id ?? '',
        milestone_id: task.milestone?.id ?? '',
        due_date: task.dueDate ?? '',
    };
}

const id = (field: string) => `task-edit-${field}`;

/**
 * The board task edit dialog (EPIC-011E §11, D1; EPIC-014 §14.2): title, priority, assignee,
 * milestone (I4), due date and description, one `useForm` mapped to the existing
 * `projects.tasks.update` endpoint, now on a `FormDialog` opened from the entity header instead of a
 * sidebar form. Only a viewer the server lets manage the project is offered it (`options` is null
 * otherwise).
 *
 * It shares the title, priority, due date and description fields with the standalone forms
 * (`task-fields`) and keeps what is board-specific explicit: the project-member assignee (the
 * current holder is kept as an option even when they have since left, I8, so an unrelated save
 * cannot unassign them) and the milestone. There is deliberately **no status field**: a board task's
 * completion is its column's (INV-1), moved by the semantic Complete/Reopen action, never edited as a
 * raw `status` here.
 *
 * The dialog is mounted only while open (the page renders it that way), so every opening starts from
 * the task's current values and a closed dialog holds no stale draft.
 */
export function TaskEditDialog({
    open,
    onOpenChange,
    projectId,
    task,
    options,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
    task: TaskDetail;
    options: TaskEditOptions;
}) {
    const form = useForm<TaskEditFormData>(toFormData(task));
    const errors: Record<string, string | undefined> = form.errors;
    const submitting = useRef(false);

    const departedAssignee: (UserRef & { departed: true }) | null =
        task.assignee && !task.assigneeIsMember ? { ...task.assignee, departed: true } : null;

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
            assignee_id: data.assignee_id === '' ? null : Number(data.assignee_id),
            milestone_id: data.milestone_id === '' ? null : Number(data.milestone_id),
        }));
        form.put(update.url({ project: projectId, task: task.id }), {
            preserveScroll: true,
            onSuccess: () => handleOpenChange(false),
            onError: (submitted) => {
                const field = firstTaskErrorField(submitted);
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
            description="Change this task's details, assignee or milestone."
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

            <PriorityField
                id={id('priority')}
                value={form.data.priority}
                onChange={(value) => form.setData('priority', value)}
                options={options.priorities}
                error={errors.priority}
            />

            <div className="space-y-2">
                <Label htmlFor={id('assignee_id')}>Assignee</Label>
                <NativeSelect
                    id={id('assignee_id')}
                    className="w-full"
                    value={form.data.assignee_id}
                    onChange={(event) =>
                        form.setData(
                            'assignee_id',
                            event.target.value === '' ? '' : Number(event.target.value),
                        )
                    }
                    aria-invalid={Boolean(errors.assignee_id)}
                    aria-describedby={errors.assignee_id ? `${id('assignee_id')}-error` : undefined}
                >
                    <option value="">Unassigned</option>
                    {options.members.map((member) => (
                        <option key={member.id} value={member.id}>
                            {member.name}
                        </option>
                    ))}
                    {departedAssignee ? (
                        <option value={departedAssignee.id}>
                            {departedAssignee.name} (no longer a project member)
                        </option>
                    ) : null}
                </NativeSelect>
                <FormFieldError id={`${id('assignee_id')}-error`} message={errors.assignee_id} />
            </div>

            <div className="space-y-2">
                <Label htmlFor={id('milestone_id')}>Milestone</Label>
                <NativeSelect
                    id={id('milestone_id')}
                    className="w-full"
                    value={form.data.milestone_id}
                    onChange={(event) =>
                        form.setData(
                            'milestone_id',
                            event.target.value === '' ? '' : Number(event.target.value),
                        )
                    }
                    aria-invalid={Boolean(errors.milestone_id)}
                    aria-describedby={
                        errors.milestone_id ? `${id('milestone_id')}-error` : undefined
                    }
                >
                    <option value="">No milestone</option>
                    {options.milestones.map((milestone) => (
                        <option key={milestone.id} value={milestone.id}>
                            {milestone.name}
                        </option>
                    ))}
                </NativeSelect>
                <FormFieldError id={`${id('milestone_id')}-error`} message={errors.milestone_id} />
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
