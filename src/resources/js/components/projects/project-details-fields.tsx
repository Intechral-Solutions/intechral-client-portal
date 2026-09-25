import { FormFieldError } from '@/components/forms/form-field-error';
import { PROJECT_STATUSES } from '@/components/projects/project-status';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import type { ProjectStatus } from '@/types/projects';

/** The form values, exactly as they are submitted (snake_case keys, string inputs). */
export type ProjectDetailsData = {
    name: string;
    description: string;
    start_date: string;
    target_date: string;
    status: ProjectStatus;
    /** Kept as the string the input holds; never parsed, so money is never a float. */
    budget: string;
};

type ProjectDetailsFieldsProps = {
    /** Prefix for element ids, unique per form on the page. */
    idPrefix: string;
    data: ProjectDetailsData;
    errors: Record<string, string | undefined>;
    onChange: <Key extends keyof ProjectDetailsData>(
        key: Key,
        value: ProjectDetailsData[Key],
    ) => void;
};

/** The first field of this form that has an error, so a failed submit can move focus to it. */
export function firstDetailsErrorField(errors: Record<string, string | undefined>) {
    return (['name', 'description', 'start_date', 'target_date', 'status', 'budget'] as const).find(
        (field) => errors[field],
    );
}

export function ProjectDetailsFields({
    idPrefix,
    data,
    errors,
    onChange,
}: ProjectDetailsFieldsProps) {
    const id = (field: string) => `${idPrefix}-${field}`;
    const invalid = (field: string) => ({
        'aria-invalid': Boolean(errors[field]),
        'aria-describedby': errors[field] ? `${id(field)}-error` : undefined,
    });

    return (
        <div className="space-y-5">
            <div className="space-y-2">
                <Label htmlFor={id('name')}>
                    Project name <span aria-hidden="true">*</span>
                </Label>
                <Input
                    id={id('name')}
                    value={data.name}
                    onChange={(event) => onChange('name', event.target.value)}
                    required
                    maxLength={255}
                    {...invalid('name')}
                />
                <FormFieldError id={`${id('name')}-error`} message={errors.name} />
            </div>

            <div className="space-y-2">
                <Label htmlFor={id('description')}>Description</Label>
                <Textarea
                    id={id('description')}
                    rows={4}
                    value={data.description}
                    onChange={(event) => onChange('description', event.target.value)}
                    {...invalid('description')}
                />
                <FormFieldError id={`${id('description')}-error`} message={errors.description} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                    <Label htmlFor={id('start_date')}>Start date</Label>
                    <Input
                        id={id('start_date')}
                        type="date"
                        value={data.start_date}
                        onChange={(event) => onChange('start_date', event.target.value)}
                        {...invalid('start_date')}
                    />
                    <FormFieldError id={`${id('start_date')}-error`} message={errors.start_date} />
                </div>
                <div className="space-y-2">
                    <Label htmlFor={id('target_date')}>Target date</Label>
                    <Input
                        id={id('target_date')}
                        type="date"
                        value={data.target_date}
                        onChange={(event) => onChange('target_date', event.target.value)}
                        {...invalid('target_date')}
                    />
                    <FormFieldError
                        id={`${id('target_date')}-error`}
                        message={errors.target_date}
                    />
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                    <Label htmlFor={id('status')}>Status</Label>
                    <NativeSelect
                        id={id('status')}
                        className="w-full"
                        value={data.status}
                        onChange={(event) =>
                            onChange('status', event.target.value as ProjectStatus)
                        }
                        {...invalid('status')}
                    >
                        {PROJECT_STATUSES.map((status) => (
                            <option key={status.value} value={status.value}>
                                {status.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <FormFieldError id={`${id('status')}-error`} message={errors.status} />
                </div>
                <div className="space-y-2">
                    <Label htmlFor={id('budget')}>Budget ($)</Label>
                    <Input
                        id={id('budget')}
                        type="number"
                        inputMode="decimal"
                        min="0"
                        step="0.01"
                        value={data.budget}
                        onChange={(event) => onChange('budget', event.target.value)}
                        {...invalid('budget')}
                    />
                    <FormFieldError id={`${id('budget')}-error`} message={errors.budget} />
                </div>
            </div>
        </div>
    );
}
