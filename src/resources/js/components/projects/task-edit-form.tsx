import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { FormFieldError } from '@/components/forms/form-field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
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

type TaskEditFormProps = {
    projectId: number;
    task: TaskDetail;
    options: TaskEditOptions;
};

/**
 * The manager-only structural edit form (EPIC-011E §11, D1): title, priority, assignee,
 * milestone (I4), due date, and description, one `useForm` mapped to the existing
 * `projects.tasks.update` endpoint. A plain full-page response (no `only`) is fine here: the
 * read-only summary elsewhere on the page needs the same fresh task anyway.
 */
export function TaskEditForm({ projectId, task, options }: TaskEditFormProps) {
    const form = useForm<TaskEditFormData>(toFormData(task));
    const errors: Record<string, string | undefined> = form.errors;
    const id = (field: string) => `task-edit-${field}`;
    const invalid = (field: string) => ({
        'aria-invalid': Boolean(errors[field]),
        'aria-describedby': errors[field] ? `${id(field)}-error` : undefined,
    });

    // The current assignee always has an option, even when they have since left the project
    // (I8): the select never silently drops them, so an unrelated save cannot unassign them.
    // They are shown only here, never folded into the reusable candidate pool.
    const departedAssignee: (UserRef & { departed: true }) | null =
        task.assignee && !task.assigneeIsMember ? { ...task.assignee, departed: true } : null;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            assignee_id: data.assignee_id === '' ? null : Number(data.assignee_id),
            milestone_id: data.milestone_id === '' ? null : Number(data.milestone_id),
        }));
        form.put(update.url({ project: projectId, task: task.id }), {
            preserveScroll: true,
            onError: (submitted) => {
                const field = firstTaskErrorField(submitted);
                if (field) document.getElementById(id(field))?.focus();
            },
        });
    }

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="space-y-2">
                <Label htmlFor={id('title')}>Title</Label>
                <Input
                    id={id('title')}
                    value={form.data.title}
                    onChange={(event) => form.setData('title', event.target.value)}
                    required
                    maxLength={255}
                    {...invalid('title')}
                />
                <FormFieldError id={`${id('title')}-error`} message={errors.title} />
            </div>

            <div className="space-y-2">
                <Label htmlFor={id('priority')}>Priority</Label>
                <NativeSelect
                    id={id('priority')}
                    className="w-full"
                    value={form.data.priority}
                    onChange={(event) =>
                        form.setData('priority', event.target.value as TaskPriority)
                    }
                    {...invalid('priority')}
                >
                    {options.priorities.map((priority) => (
                        <option key={priority.value} value={priority.value}>
                            {priority.label}
                        </option>
                    ))}
                </NativeSelect>
                <FormFieldError id={`${id('priority')}-error`} message={errors.priority} />
            </div>

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
                    {...invalid('assignee_id')}
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
                    {...invalid('milestone_id')}
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

            <div className="space-y-2">
                <Label htmlFor={id('due_date')}>Due date</Label>
                <Input
                    id={id('due_date')}
                    type="date"
                    value={form.data.due_date}
                    onChange={(event) => form.setData('due_date', event.target.value)}
                    {...invalid('due_date')}
                />
                <FormFieldError id={`${id('due_date')}-error`} message={errors.due_date} />
            </div>

            <div className="space-y-2">
                <Label htmlFor={id('description')}>Description</Label>
                <Textarea
                    id={id('description')}
                    rows={4}
                    value={form.data.description}
                    onChange={(event) => form.setData('description', event.target.value)}
                    {...invalid('description')}
                />
                <FormFieldError id={`${id('description')}-error`} message={errors.description} />
            </div>

            <div className="flex justify-end">
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving...' : 'Save changes'}
                </Button>
            </div>
        </form>
    );
}
