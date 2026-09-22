import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactElement } from 'react';

import { PageHeader } from '@/components/page-header';
import { CompanySelector } from '@/components/projects/company-selector';
import {
    firstMemberErrorField,
    MemberRowsEditor,
    newMemberRow,
    toMemberPayload,
    type MemberRow,
} from '@/components/projects/member-rows-editor';
import {
    firstDetailsErrorField,
    ProjectDetailsFields,
    type ProjectDetailsData,
} from '@/components/projects/project-details-fields';
import { ProjectMemberList } from '@/components/projects/project-member-list';
import { SectionPanel } from '@/components/section-panel';
import { Button, buttonVariants } from '@/components/ui/button';
import { ConfirmationDialog } from '@/components/ui/confirmation-dialog';
import { AppLayout } from '@/layouts/app-layout';
import { board, destroy, update } from '@/routes/projects';
import { sync as syncCompanies } from '@/routes/projects/companies';
import { sync as syncMembers } from '@/routes/projects/members';
import type {
    CompanyOption,
    MemberCandidate,
    ProjectDetail,
    ProjectMemberRef,
} from '@/types/projects';

export type EditProjectProps = {
    project: ProjectDetail;
    members: ProjectMemberRef[];
    companies: CompanyOption[];
    linkedCompanyIds: number[];
    abilities: { delete: boolean; editMembers: boolean };
    /** Present only when `abilities.editMembers` is true; absent, not empty, otherwise. */
    memberCandidates?: MemberCandidate[];
};

// Each section below owns its own form: its own values, its own processing state and its own
// error bag, mapped 1:1 to one endpoint. Saving one never resubmits or resets another, and one
// section's validation error never shows in another (EPIC-011E §6).

function DetailsSection({ project }: { project: ProjectDetail }) {
    const form = useForm<ProjectDetailsData>({
        name: project.name,
        description: project.description ?? '',
        start_date: project.startDate ?? '',
        target_date: project.targetDate ?? '',
        status: project.status,
        budget: project.budget ?? '',
    });
    const errors: Record<string, string | undefined> = form.errors;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(update.url(project.id), {
            preserveScroll: true,
            errorBag: 'updateProject',
            onError: (submitted) => {
                const field = firstDetailsErrorField(submitted);
                if (field) document.getElementById(`edit-details-${field}`)?.focus();
            },
        });
    }

    return (
        <SectionPanel title="Project details" description="Name, dates, status and budget.">
            <form onSubmit={submit} className="space-y-5">
                <ProjectDetailsFields
                    idPrefix="edit-details"
                    data={form.data}
                    errors={errors}
                    onChange={(key, value) =>
                        form.setData((current) => ({ ...current, [key]: value }))
                    }
                />
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Saving...' : 'Save changes'}
                    </Button>
                </div>
            </form>
        </SectionPanel>
    );
}

function CompaniesSection({
    projectId,
    companies,
    linkedCompanyIds,
}: {
    projectId: number;
    companies: CompanyOption[];
    linkedCompanyIds: number[];
}) {
    const form = useForm<{ companies: number[] }>({ companies: linkedCompanyIds });
    const errors: Record<string, string | undefined> = form.errors;

    function submit(event: FormEvent) {
        event.preventDefault();
        // An empty selection is sent as `companies: []`: the server requires the key.
        form.put(syncCompanies.url(projectId), { preserveScroll: true, errorBag: 'syncCompanies' });
    }

    return (
        <SectionPanel title="Linked companies">
            <form onSubmit={submit} className="space-y-4">
                <CompanySelector
                    idPrefix="edit"
                    companies={companies}
                    selected={form.data.companies}
                    onChange={(selected) => form.setData('companies', selected)}
                    error={
                        errors.companies ??
                        Object.entries(errors).find(([key]) => key.startsWith('companies.'))?.[1]
                    }
                />
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Saving...' : 'Update companies'}
                    </Button>
                </div>
            </form>
        </SectionPanel>
    );
}

/** Rendered only for projects.admin; it is the only place a candidate directory is used. */
function MembersForm({
    projectId,
    members,
    candidates,
}: {
    projectId: number;
    members: ProjectMemberRef[];
    candidates: MemberCandidate[];
}) {
    const owner = members.find((member) => member.isOwner) ?? null;
    const [initialRows] = useState(() =>
        members
            .filter((member) => !member.isOwner)
            .map((member) => newMemberRow({ user_id: member.id, role: member.role })),
    );
    const form = useForm<{ members: MemberRow[] }>({ members: initialRows });
    const errors: Record<string, string | undefined> = form.errors;

    function submit(event: FormEvent) {
        event.preventDefault();
        // The owner is not sent: the server keeps the creator as a manager whatever arrives.
        form.transform((data) => ({ members: toMemberPayload(data.members) }));
        form.put(syncMembers.url(projectId), {
            preserveScroll: true,
            errorBag: 'syncMembers',
            onError: (submitted) => {
                const found = firstMemberErrorField(submitted, form.data.members.length);
                const row = found ? form.data.members[found.index] : undefined;

                if (found && row) {
                    document.getElementById(`edit-members-${row.key}-${found.field}`)?.focus();
                }
            },
        });
    }

    return (
        <form onSubmit={submit} className="space-y-4">
            <MemberRowsEditor
                idPrefix="edit-members"
                owner={owner ? { id: owner.id, name: owner.name } : null}
                rows={form.data.members}
                onChange={(rows) => form.setData('members', rows)}
                candidates={candidates}
                errors={errors}
            />
            <div className="flex justify-end">
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving...' : 'Update members'}
                </Button>
            </div>
        </form>
    );
}

function DangerZone({ project }: { project: ProjectDetail }) {
    const [open, setOpen] = useState(false);
    const form = useForm({});
    const errors: Record<string, string | undefined> = form.errors;

    return (
        <SectionPanel
            title="Danger zone"
            description="Deleting a project removes its board, tasks and milestones. A project with recorded time cannot be deleted; set its status to Archived instead."
        >
            <ConfirmationDialog
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);
                    if (!next) form.clearErrors();
                }}
                title="Delete this project?"
                description={`This permanently removes "${project.name}", its board, tasks and milestones. It cannot be undone.`}
                confirmLabel="Delete project"
                processing={form.processing}
                error={errors.delete}
                onConfirm={() =>
                    // Not optimistic: the project stays on screen until the server confirms. A
                    // refusal (recorded time) comes back as an error and the dialog stays open.
                    form.delete(destroy.url(project.id), {
                        preserveScroll: true,
                        errorBag: 'deleteProject',
                    })
                }
            >
                <Button
                    type="button"
                    variant="outline"
                    className="border-[var(--border-danger)] text-[var(--text-danger)]"
                >
                    Delete project
                </Button>
            </ConfirmationDialog>
        </SectionPanel>
    );
}

export function EditProjectPage({
    project,
    members,
    companies,
    linkedCompanyIds,
    abilities,
    memberCandidates,
}: EditProjectProps) {
    const canEditMembers = abilities.editMembers && memberCandidates !== undefined;

    return (
        <>
            <Head title={`Edit ${project.name}`} />
            <div className="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader
                    title="Edit project"
                    description={project.name}
                    actions={
                        // The board is a React page as of WP5 (EPIC-011E §21): an Inertia Link.
                        <Link
                            href={board.url(project.id)}
                            className={buttonVariants({ variant: 'outline' })}
                        >
                            Back to board
                        </Link>
                    }
                />

                <DetailsSection project={project} />

                {companies.length > 0 ? (
                    <CompaniesSection
                        projectId={project.id}
                        companies={companies}
                        linkedCompanyIds={linkedCompanyIds}
                    />
                ) : null}

                <SectionPanel
                    title="Members"
                    description={
                        canEditMembers
                            ? 'The owner stays a manager. Anyone else can be added, removed, or given a different role.'
                            : 'Project membership is managed by an administrator.'
                    }
                >
                    {canEditMembers ? (
                        <MembersForm
                            projectId={project.id}
                            members={members}
                            candidates={memberCandidates}
                        />
                    ) : (
                        <ProjectMemberList members={members} />
                    )}
                </SectionPanel>

                {abilities.delete ? <DangerZone project={project} /> : null}
            </div>
        </>
    );
}

EditProjectPage.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;

export default EditProjectPage;
