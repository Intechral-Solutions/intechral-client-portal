import { Head, Link, useForm } from '@inertiajs/react';
import { LifeBuoy } from 'lucide-react';
import type { FormEvent, ReactElement } from 'react';

import { AttachmentInput } from '@/components/helpdesk/attachment-input';
import { FormFieldError } from '@/components/forms/form-field-error';
import { PageFrame } from '@/components/page-frame';
import { PageHeader } from '@/components/page-header';
import { AppShell } from '@/components/shell/app-shell';
import { Button, buttonVariants } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { index, store } from '@/routes/support';
import type {
    AttachmentPolicy,
    SelectOption,
    SupportCustomerOption,
    TicketPriorityValue,
} from '@/types/helpdesk';

/**
 * Raise a request for a contact at a customer you support (Release 1.0, R1-5). The server sends the customers the signed-in partner support user
 * supports RIGHT NOW and, for each, the contacts who can be the requester (`PartnerSupportAccess::creationOptions`); nothing else. This page only
 * reflects that authorization: the server decides again, under lock, when the request arrives, and a refusal is shown beside the field it concerns
 * (`organization_id`, `requester_id`). The contact is always an explicit choice with no default, so a request is never filed for a person by accident;
 * the signed-in user is recorded as the submitter by the server, never by this form.
 */
export type SupportCreateProps = {
    customers: SupportCustomerOption[];
    categories: string[];
    priorities: SelectOption<TicketPriorityValue>[];
    defaultPriority: TicketPriorityValue;
    attachmentPolicy: AttachmentPolicy;
};

type CreateData = {
    /** The chosen customer's id as text (a select value); chosen for the person when there is only one. */
    organization_id: string;
    /** The chosen contact's id as text; never defaulted. */
    requester_id: string;
    title: string;
    category: string;
    priority: TicketPriorityValue;
    description: string;
    attachments: File[];
};

const FIELD_ORDER = [
    'organization_id',
    'requester_id',
    'title',
    'category',
    'priority',
    'description',
] as const;

export function SupportCreatePage({
    customers,
    categories,
    priorities,
    defaultPriority,
    attachmentPolicy,
}: SupportCreateProps) {
    const only = customers.length === 1 ? customers[0] : undefined;
    const form = useForm<CreateData>({
        organization_id: only ? String(only.id) : '',
        requester_id: '',
        title: '',
        category: categories[0] ?? '',
        priority: defaultPriority,
        description: '',
        attachments: [],
    });
    const errors: Record<string, string | undefined> = form.errors;
    const customer = customers.find((option) => String(option.id) === form.data.organization_id);
    const contacts = customer?.contacts ?? [];

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

    if (customers.length === 0) {
        return (
            <>
                <Head title="New request for a customer" />
                <PageFrame width="reading" measure="forms" className="flex flex-col gap-6">
                    <PageHeader
                        overline="Support"
                        title="New request for a customer"
                        description="Raise a request on behalf of a contact at an organization you support."
                    />
                    <EmptyState
                        icon={LifeBuoy}
                        title="There is no customer you can raise a request for."
                        description="Your support access does not currently cover an organization. Contact Intechral if you expected it to."
                        action={
                            <Link
                                href={index.url()}
                                className={buttonVariants({ variant: 'secondary' })}
                            >
                                Back to supported requests
                            </Link>
                        }
                    />
                </PageFrame>
            </>
        );
    }

    return (
        <>
            <Head title="New request for a customer" />
            <PageFrame width="reading" measure="forms" className="flex flex-col gap-6">
                <PageHeader
                    overline="Support"
                    title="New request for a customer"
                    description="Raise a request on behalf of a contact at an organization you support. The contact is the requester and hears about it by email; you are recorded as having raised it."
                />

                <form onSubmit={submit} className="flex flex-col gap-5">
                    <p className="text-sm text-text-secondary">Fields marked * are required.</p>

                    {only ? (
                        <div className="space-y-2">
                            <p
                                id="create-organization_id"
                                tabIndex={-1}
                                className="text-sm text-text-secondary"
                            >
                                Requesting for{' '}
                                <span className="font-medium text-text">{only.name}</span>
                            </p>
                            <FormFieldError
                                id="create-organization_id-error"
                                message={errors.organization_id}
                            />
                        </div>
                    ) : (
                        <div className="space-y-2">
                            <Label htmlFor="create-organization_id">
                                Customer <span aria-hidden="true">*</span>
                            </Label>
                            <NativeSelect
                                id="create-organization_id"
                                className="w-full"
                                value={form.data.organization_id}
                                onChange={(event) => {
                                    form.setData((data) => ({
                                        ...data,
                                        organization_id: event.target.value,
                                        // A contact belongs to one customer: never carry the choice across.
                                        requester_id: '',
                                    }));
                                }}
                                required
                                {...invalid('organization_id')}
                            >
                                <option value="">Choose a customer</option>
                                {customers.map((option) => (
                                    <option key={option.id} value={String(option.id)}>
                                        {option.name}
                                    </option>
                                ))}
                            </NativeSelect>
                            <FormFieldError
                                id="create-organization_id-error"
                                message={errors.organization_id}
                            />
                        </div>
                    )}

                    <div className="space-y-2">
                        <Label htmlFor="create-requester_id">
                            Contact <span aria-hidden="true">*</span>
                        </Label>
                        <NativeSelect
                            id="create-requester_id"
                            className="w-full"
                            value={form.data.requester_id}
                            onChange={(event) => form.setData('requester_id', event.target.value)}
                            required
                            disabled={customer === undefined || contacts.length === 0}
                            {...invalid('requester_id')}
                        >
                            <option value="">Choose the contact who needs help</option>
                            {contacts.map((contact) => (
                                <option key={contact.id} value={String(contact.id)}>
                                    {contact.name} ({contact.email})
                                </option>
                            ))}
                        </NativeSelect>
                        {customer !== undefined && contacts.length === 0 ? (
                            <p className="text-sm text-text-secondary">
                                {customer.name} has no contact who can receive requests yet. Contact
                                Intechral to add one.
                            </p>
                        ) : null}
                        <FormFieldError
                            id="create-requester_id-error"
                            message={errors.requester_id}
                        />
                    </div>

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

SupportCreatePage.layout = (page: ReactElement) => (
    <AppShell trail={[{ label: 'New request for a customer' }]}>{page}</AppShell>
);

export default SupportCreatePage;
