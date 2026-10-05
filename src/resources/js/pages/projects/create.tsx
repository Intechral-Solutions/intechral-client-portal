import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent, ReactElement } from 'react';

import { PageFrame } from '@/components/page-frame';
import { PageHeader } from '@/components/page-header';
import { CompanySelector } from '@/components/projects/company-selector';
import {
    firstMemberErrorField,
    MemberRowsEditor,
    toMemberPayload,
    type MemberRow,
} from '@/components/projects/member-rows-editor';
import {
    firstDetailsErrorField,
    ProjectDetailsFields,
    type ProjectDetailsData,
} from '@/components/projects/project-details-fields';
import { Section } from '@/components/section';
import { Button, buttonVariants } from '@/components/ui/button';
import { AppShell } from '@/components/shell/app-shell';
import { index, store } from '@/routes/projects';
import type { SharedPageProps } from '@/types';
import type { CompanyOption, MemberCandidate } from '@/types/projects';

export type CreateProjectProps = {
    companies: CompanyOption[];
    /** `editMembers` is true for projects.admin only (D7-B). */
    abilities: { editMembers: boolean };
    /** Present only when `abilities.editMembers` is true; absent, not empty, otherwise. */
    memberCandidates?: MemberCandidate[];
};

type CreateFormData = ProjectDetailsData & {
    companies: number[];
    members: MemberRow[];
};

export function CreateProjectPage({ companies, abilities, memberCandidates }: CreateProjectProps) {
    const { auth } = usePage<SharedPageProps>().props;
    const form = useForm<CreateFormData>({
        name: '',
        description: '',
        start_date: '',
        target_date: '',
        status: 'active',
        budget: '',
        companies: [],
        members: [],
    });
    const canEditMembers = abilities.editMembers && memberCandidates !== undefined;
    const errors: Record<string, string | undefined> = form.errors;

    function submit(event: FormEvent) {
        event.preventDefault();

        // Client row keys never reach the server, and a non-admin sends no `members` field at
        // all (a forged one is refused server-side, D7-B).
        form.transform((data) => {
            const { members, ...rest } = data;

            return canEditMembers ? { ...rest, members: toMemberPayload(members) } : rest;
        });
        form.post(store.url(), {
            errorBag: 'createProject',
            onError: (submitted) => {
                const detail = firstDetailsErrorField(submitted);
                const member = detail
                    ? null
                    : firstMemberErrorField(submitted, form.data.members.length);
                const row = member ? form.data.members[member.index] : undefined;
                const target = detail
                    ? `create-${detail}`
                    : member && row
                      ? `create-members-${row.key}-${member.field}`
                      : null;

                if (target) document.getElementById(target)?.focus();
            },
        });
    }

    return (
        <>
            <Head title="New project" />
            <PageFrame width="reading" measure="forms" className="flex flex-col gap-6">
                <PageHeader
                    overline="Projects"
                    title="New project"
                    description="Set up the project. Its board is created with default columns, and you are its manager."
                />

                <form onSubmit={submit} className="flex flex-col gap-8">
                    <Section title="Project details" description="Name, dates, status and budget.">
                        <ProjectDetailsFields
                            idPrefix="create"
                            data={form.data}
                            errors={errors}
                            onChange={(key, value) =>
                                form.setData((current) => ({ ...current, [key]: value }))
                            }
                        />
                    </Section>

                    {companies.length > 0 ? (
                        <Section title="Linked companies">
                            <CompanySelector
                                idPrefix="create"
                                companies={companies}
                                selected={form.data.companies}
                                onChange={(selected) => form.setData('companies', selected)}
                                error={
                                    errors.companies ??
                                    Object.entries(errors).find(([key]) =>
                                        key.startsWith('companies.'),
                                    )?.[1]
                                }
                            />
                        </Section>
                    ) : null}

                    <Section
                        title="Members"
                        description="You are added as the project's manager automatically."
                    >
                        {canEditMembers && auth.user ? (
                            <MemberRowsEditor
                                idPrefix="create-members"
                                owner={{ id: auth.user.id, name: auth.user.name }}
                                rows={form.data.members}
                                onChange={(rows) => form.setData('members', rows)}
                                candidates={memberCandidates}
                                errors={errors}
                            />
                        ) : (
                            <p className="text-sm text-text-secondary">
                                Adding other members is done by an administrator after the project
                                is created.
                            </p>
                        )}
                    </Section>

                    <div className="flex flex-wrap justify-end gap-3 border-t border-rule pt-6">
                        <Link
                            href={index.url()}
                            className={buttonVariants({ variant: 'secondary' })}
                        >
                            Cancel
                        </Link>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Creating...' : 'Create project'}
                        </Button>
                    </div>
                </form>
            </PageFrame>
        </>
    );
}

/** The shell's one breadcrumb: `Projects › All projects › New project`. No page-owned trail. */
CreateProjectPage.layout = (page: ReactElement) => (
    <AppShell trail={[{ label: 'New project' }]}>{page}</AppShell>
);

export default CreateProjectPage;
