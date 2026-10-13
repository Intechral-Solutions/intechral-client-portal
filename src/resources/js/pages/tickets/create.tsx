import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactElement } from 'react';

import { AttachmentInput } from '@/components/helpdesk/attachment-input';
import { FormFieldError } from '@/components/forms/form-field-error';
import { PageFrame } from '@/components/page-frame';
import { PageHeader } from '@/components/page-header';
import { AppShell } from '@/components/shell/app-shell';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { index, store } from '@/routes/tickets';
import type { AttachmentPolicy, SelectOption, TicketPriorityValue } from '@/types/helpdesk';

/**
 * Raise a request (EPIC-017 §19.2). The server rules are the ones that existed, plus the shared
 * attachment rule (G1); this page states them and shows the server's messages verbatim. The company
 * selector is gone (F-1): nothing ever persisted it. Customers keep choosing a priority, Critical
 * included (§13: preserved behaviour, not an endorsement).
 */
export type TicketsCreateProps = {
    categories: string[];
    priorities: SelectOption<TicketPriorityValue>[];
    defaultPriority: TicketPriorityValue;
    attachmentPolicy: AttachmentPolicy;
};

type CreateData = {
    title: string;
    category: string;
    priority: TicketPriorityValue;
    description: string;
    attachments: File[];
};

const FIELD_ORDER = ['title', 'category', 'priority', 'description'] as const;

export function TicketsCreatePage({
    categories,
    priorities,
    defaultPriority,
    attachmentPolicy,
}: TicketsCreateProps) {
    const form = useForm<CreateData>({
        title: '',
        category: categories[0] ?? '',
        priority: defaultPriority,
        description: '',
        attachments: [],
    });
    const errors: Record<string, string | undefined> = form.errors;

    const invalid = (field: string) => ({
        'aria-invalid': Boolean(errors[field]),
        'aria-describedby': errors[field] ? `create-${field}-error` : undefined,
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        form.post(store.url(), {
            forceFormData: true,
            onError: (submitted) => {
                const first = FIELD_ORDER.find((field) => submitted[field]);

                document.getElementById(first ? `create-${first}` : 'create-attachments')?.focus();
            },
        });
    }

    return (
        <>
            <Head title="New request" />
            <PageFrame width="reading" measure="forms" className="flex flex-col gap-6">
                <PageHeader
                    overline="Support"
                    title="New request"
                    description="Tell us what you need. We'll reply here and by email."
                />

                <form onSubmit={submit} className="flex flex-col gap-5">
                    <p className="text-sm text-text-secondary">Fields marked * are required.</p>

                    <div className="space-y-2">
                        <Label htmlFor="create-title">
                            Subject <span aria-hidden="true">*</span>
                        </Label>
                        <Input
                            id="create-title"
                            value={form.data.title}
                            onChange={(event) => form.setData('title', event.target.value)}
                            required
                            maxLength={255}
                            {...invalid('title')}
                        />
                        <FormFieldError id="create-title-error" message={errors.title} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="create-category">
                                Category <span aria-hidden="true">*</span>
                            </Label>
                            <NativeSelect
                                id="create-category"
                                className="w-full"
                                value={form.data.category}
                                onChange={(event) => form.setData('category', event.target.value)}
                                required
                                {...invalid('category')}
                            >
                                {categories.map((category) => (
                                    <option key={category} value={category}>
                                        {category}
                                    </option>
                                ))}
                            </NativeSelect>
                            <FormFieldError id="create-category-error" message={errors.category} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="create-priority">
                                Priority <span aria-hidden="true">*</span>
                            </Label>
                            <NativeSelect
                                id="create-priority"
                                className="w-full"
                                value={form.data.priority}
                                onChange={(event) =>
                                    form.setData(
                                        'priority',
                                        event.target.value as TicketPriorityValue,
                                    )
                                }
                                required
                                {...invalid('priority')}
                            >
                                {priorities.map((priority) => (
                                    <option key={priority.value} value={priority.value}>
                                        {priority.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <FormFieldError id="create-priority-error" message={errors.priority} />
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="create-description">
                            Description <span aria-hidden="true">*</span>
                        </Label>
                        <Textarea
                            id="create-description"
                            rows={8}
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                            required
                            {...invalid('description')}
                        />
                        <FormFieldError
                            id="create-description-error"
                            message={errors.description}
                        />
                    </div>

                    <AttachmentInput
                        id="create-attachments"
                        files={form.data.attachments}
                        onChange={(files) => form.setData('attachments', files)}
                        policy={attachmentPolicy}
                        errors={errors}
                    />

                    <div className="flex flex-wrap justify-end gap-3 border-t border-rule pt-6">
                        <Link
                            href={index.url()}
                            className={buttonVariants({ variant: 'secondary' })}
                        >
                            Cancel
                        </Link>
                        <Button type="submit" className="min-w-36" disabled={form.processing}>
                            {form.processing ? 'Submitting…' : 'Submit request'}
                        </Button>
                    </div>
                </form>
            </PageFrame>
        </>
    );
}

TicketsCreatePage.layout = (page: ReactElement) => (
    <AppShell trail={[{ label: 'New request' }]}>{page}</AppShell>
);

export default TicketsCreatePage;
