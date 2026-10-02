import { FormFieldError } from '@/components/forms/form-field-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import type { TaskPriority } from '@/types/projects';

/**
 * EPIC-014 §14.2 — the field set the task forms share: the standalone create dialog, the standalone
 * edit dialog and the board edit dialog. Title, description, priority and due date are the same field
 * with the same label, validation wiring and error element wherever they appear, so the three forms
 * cannot drift apart on them.
 *
 * It is deliberately NOT a task form. What differs between the kinds stays in each form, explicit:
 * a standalone task has a raw status and a "Me or nobody" assignee; a board task has a project-member
 * assignee and a milestone and NO status editor (completion is column-authoritative, INV-1). Each
 * field here is controlled by its form (`useForm`), reports plain values, and never decides what the
 * form may contain. `id` is the form's prefix, so two forms on one page never collide.
 */
type FieldProps<T> = {
    id: string;
    value: T;
    onChange: (value: T) => void;
    error?: string;
};

function describe(id: string, error?: string) {
    return {
        'aria-invalid': Boolean(error),
        'aria-describedby': error ? `${id}-error` : undefined,
    };
}

export function TitleField({ id, value, onChange, error }: FieldProps<string>) {
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>
                Title <span aria-hidden="true">*</span>
            </Label>
            <Input
                id={id}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                required
                maxLength={255}
                {...describe(id, error)}
            />
            <FormFieldError id={`${id}-error`} message={error} />
        </div>
    );
}

export function PriorityField({
    id,
    value,
    onChange,
    error,
    options,
}: FieldProps<TaskPriority> & { options: readonly { value: TaskPriority; label: string }[] }) {
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>Priority</Label>
            <NativeSelect
                id={id}
                className="w-full"
                value={value}
                onChange={(event) => onChange(event.target.value as TaskPriority)}
                {...describe(id, error)}
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </NativeSelect>
            <FormFieldError id={`${id}-error`} message={error} />
        </div>
    );
}

export function DueDateField({ id, value, onChange, error }: FieldProps<string>) {
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>Due date</Label>
            <Input
                id={id}
                type="date"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                {...describe(id, error)}
            />
            <FormFieldError id={`${id}-error`} message={error} />
        </div>
    );
}

export function DescriptionField({
    id,
    value,
    onChange,
    error,
    rows = 3,
}: FieldProps<string> & { rows?: number }) {
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>Description</Label>
            <Textarea
                id={id}
                rows={rows}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                {...describe(id, error)}
            />
            <FormFieldError id={`${id}-error`} message={error} />
        </div>
    );
}
