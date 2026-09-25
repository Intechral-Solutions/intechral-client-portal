import { useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import type { FormEvent } from 'react';

import { FormFieldError } from '@/components/forms/form-field-error';
import { FormDialog } from '@/components/ui/form-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { store, update } from '@/routes/projects/milestones';
import type { MilestoneItem } from '@/types/projects';

type MilestoneFormDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
    /** Present to edit that milestone; absent (even while `open`) to create a new one. */
    milestone?: MilestoneItem;
};

type MilestoneFormData = {
    name: string;
    description: string;
    due_date: string;
};

function valuesFor(milestone: MilestoneItem | undefined): MilestoneFormData {
    return {
        name: milestone?.name ?? '',
        description: milestone?.description ?? '',
        due_date: milestone?.dueDate ?? '',
    };
}

/**
 * One dialog instance handles both create and edit, for every row, and stays mounted for the
 * page's lifetime: Radix's own open/close lifecycle (and the WP2 focus-return fix built on it)
 * runs on one stable `Dialog.Root`, never torn down mid-close by a parent that unmounts it.
 * Its fields resync from `milestone` whenever the dialog opens, so switching targets between
 * closes never carries a stale draft, without needing a fresh component instance per target.
 */
export function MilestoneFormDialog({
    open,
    onOpenChange,
    projectId,
    milestone,
}: MilestoneFormDialogProps) {
    const form = useForm<MilestoneFormData>(valuesFor(milestone));
    const errors: Record<string, string | undefined> = form.errors;
    const errorBag = milestone ? 'updateMilestone' : 'createMilestone';
    // Seeded from the initial `open` value, not a hardcoded false: a dialog that starts out
    // already open (its errors freshly arrived with the page, nothing stale to discard) must
    // not have that first render treated as a "just opened" transition.
    const wasOpen = useRef(open);

    useEffect(() => {
        if (open && !wasOpen.current) {
            form.setData(valuesFor(milestone));
            form.clearErrors();
        }
        wasOpen.current = open;
        // form is a stable Inertia handle; only re-sync when the dialog opens or its target changes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, milestone]);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            errorBag,
            onSuccess: () => onOpenChange(false),
        };

        if (milestone) {
            form.put(update.url({ project: projectId, milestone: milestone.id }), options);
        } else {
            form.post(store.url(projectId), options);
        }
    }

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title={milestone ? 'Edit milestone' : 'New milestone'}
            description="Milestones group tasks under a target date so progress is easy to see."
            submitLabel={milestone ? 'Save changes' : 'Create milestone'}
            onSubmit={submit}
            processing={form.processing}
        >
            <div className="space-y-2">
                <Label htmlFor="milestone-name">
                    Name <span aria-hidden="true">*</span>
                </Label>
                <Input
                    id="milestone-name"
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                    required
                    maxLength={255}
                    aria-invalid={Boolean(errors.name)}
                    aria-describedby={errors.name ? 'milestone-name-error' : undefined}
                />
                <FormFieldError id="milestone-name-error" message={errors.name} />
            </div>

            <div className="space-y-2">
                <Label htmlFor="milestone-due-date">
                    Due date <span aria-hidden="true">*</span>
                </Label>
                <Input
                    id="milestone-due-date"
                    type="date"
                    value={form.data.due_date}
                    onChange={(event) => form.setData('due_date', event.target.value)}
                    required
                    aria-invalid={Boolean(errors.due_date)}
                    aria-describedby={errors.due_date ? 'milestone-due-date-error' : undefined}
                />
                <FormFieldError id="milestone-due-date-error" message={errors.due_date} />
            </div>

            <div className="space-y-2">
                <Label htmlFor="milestone-description">Description</Label>
                <Textarea
                    id="milestone-description"
                    rows={3}
                    value={form.data.description}
                    onChange={(event) => form.setData('description', event.target.value)}
                    aria-invalid={Boolean(errors.description)}
                    aria-describedby={
                        errors.description ? 'milestone-description-error' : undefined
                    }
                />
                <FormFieldError id="milestone-description-error" message={errors.description} />
            </div>
        </FormDialog>
    );
}
