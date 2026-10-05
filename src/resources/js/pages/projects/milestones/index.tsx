import { Head } from '@inertiajs/react';
import { CalendarClock, Plus } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';

import { PageFrame } from '@/components/page-frame';
import { MilestoneFormDialog } from '@/components/projects/milestone-form-dialog';
import { MilestoneListItem } from '@/components/projects/milestone-list-item';
import { milestoneStages } from '@/components/projects/milestone-stages';
import { ProjectWorkspaceHeader } from '@/components/projects/project-workspace-header';
import type { ProjectWorkspaceTabs } from '@/components/projects/project-workspace-nav';
import { Section } from '@/components/section';
import { AppShell } from '@/components/shell/app-shell';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { StagePath, stagePathWindow } from '@/components/ui/stage-path';
import { layoutPageProps } from '@/lib/inertia-layout';
import { show } from '@/routes/projects';
import type { MilestoneItem, ProjectStatus } from '@/types/projects';

/**
 * EPIC-015 WP4 — the project's Milestones tab (§11.1, §14.1), inside the shared project header.
 *
 * The full `StagePath` of every milestone (the same mapping as the Overview's compact one), then the
 * milestones in order with their explicit completion, due date and linked-task progress, and, for an
 * actor the server allows (`manage`, the Settings-access answer, A9), Complete/Reopen, Edit, Delete
 * and New milestone. Completion is explicit stored state; linked-task progress is informational and
 * never worded as completion (Q2, INV-P9). Overdue and the current milestone are the server's.
 */
export type MilestonesIndexProps = {
    project: { id: number; name: string; status: ProjectStatus };
    /** In the server's order: due date, then id. */
    milestones: MilestoneItem[];
    /** The first incomplete milestone, the StagePath's current stage (the Overview's rule). */
    currentId: number | null;
    /** `manage` is `ProjectSettingsAccess` (A9): milestone mutations and the Settings action. */
    abilities: { manage: boolean };
    /**
     * The workspace navigation's optional tabs (`ProjectPresenter::workspaceTabs`, WP5): `time` is
     * the server's project-time answer. Sent by every workspace page; absent reads as "no Time tab".
     */
    tabs?: ProjectWorkspaceTabs;
};

type DialogState = { mode: 'create' } | { mode: 'edit'; milestone: MilestoneItem } | null;

function plural(count: number, one: string, many: string) {
    return `${count} ${count === 1 ? one : many}`;
}

export function MilestonesIndexPage({
    project,
    milestones,
    currentId,
    abilities,
    tabs,
}: MilestonesIndexProps) {
    const [dialog, setDialog] = useState<DialogState>(null);
    const openCreate = () => setDialog({ mode: 'create' });

    const total = milestones.length;
    const completed = milestones.filter((milestone) => milestone.completedAt !== null).length;
    const overdue = milestones.filter((milestone) => milestone.overdue).length;
    const stages = milestoneStages(milestones, currentId);
    const { start, end } = stagePathWindow(stages);

    return (
        <>
            <Head title={`${project.name} — Milestones`} />
            <PageFrame
                width="grid"
                header={
                    <ProjectWorkspaceHeader
                        project={project}
                        current="milestones"
                        // The same resolver as every milestone mutation (ProjectSettingsAccess).
                        openSettings={abilities.manage}
                        tabs={tabs}
                    />
                }
            >
                <Section
                    title="Milestones"
                    description="Dated checkpoints. A milestone is complete only when someone completes it; its linked tasks show progress toward it."
                    actions={
                        abilities.manage ? (
                            <Button type="button" size="sm" onClick={openCreate}>
                                <Plus aria-hidden="true" />
                                New milestone
                            </Button>
                        ) : undefined
                    }
                >
                    {total === 0 ? (
                        <EmptyState
                            icon={CalendarClock}
                            title="No milestones yet"
                            description="Milestones are dated checkpoints, such as kickoff or go-live."
                            action={
                                abilities.manage ? (
                                    <Button type="button" variant="secondary" onClick={openCreate}>
                                        Create the first milestone
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <div className="space-y-5">
                            <p
                                className="text-sm text-text-secondary"
                                data-testid="milestone-summary"
                            >
                                {completed} of {plural(total, 'milestone', 'milestones')} complete
                                {overdue > 0 ? (
                                    <span className="font-medium text-danger">
                                        {' '}
                                        · {overdue} overdue
                                    </span>
                                ) : null}
                            </p>

                            <StagePath
                                variant="full"
                                stages={stages}
                                label={`Milestones: ${completed} of ${total} complete`}
                                hiddenBefore={start > 0 ? `+${start} earlier` : undefined}
                                hiddenAfter={end < total ? `+${total - end} later` : undefined}
                            />

                            <ul
                                role="list"
                                aria-label="Milestones"
                                className="divide-y divide-rule border-y border-rule"
                            >
                                {milestones.map((milestone) => (
                                    <MilestoneListItem
                                        key={milestone.id}
                                        milestone={milestone}
                                        projectId={project.id}
                                        canManage={abilities.manage}
                                        onEdit={() => setDialog({ mode: 'edit', milestone })}
                                    />
                                ))}
                            </ul>
                        </div>
                    )}
                </Section>
            </PageFrame>

            {abilities.manage ? (
                <MilestoneFormDialog
                    // Always mounted (never conditionally torn down on close): Radix's own
                    // close lifecycle, and the WP2 focus-return fix built on it, needs one
                    // stable Dialog.Root to run against. It resyncs its fields from `milestone`
                    // whenever it opens, so switching targets never carries a stale draft.
                    open={dialog !== null}
                    onOpenChange={(open) => {
                        if (!open) setDialog(null);
                    }}
                    projectId={project.id}
                    milestone={dialog?.mode === 'edit' ? dialog.milestone : undefined}
                />
            ) : null}
        </>
    );
}

/**
 * The shell's one breadcrumb: `Projects › All projects › {project} › Milestones`, the project segment
 * opening its Overview (§11.3). The page draws no trail of its own.
 */
MilestonesIndexPage.layout = (page: ReactElement) => {
    const props = layoutPageProps<MilestonesIndexProps>(page);

    return (
        <AppShell
            trail={
                props && [
                    { label: props.project.name, href: show.url(props.project.id) },
                    { label: 'Milestones' },
                ]
            }
        >
            {page}
        </AppShell>
    );
};

export default MilestonesIndexPage;
