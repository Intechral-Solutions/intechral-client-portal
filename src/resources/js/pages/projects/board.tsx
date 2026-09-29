import { Head, Link } from '@inertiajs/react';
import type { ReactElement } from 'react';

import { EntityHeader } from '@/components/entity-header';
import { PageFrame } from '@/components/page-frame';
import { Board } from '@/components/projects/board';
import { ProjectStatusBadge } from '@/components/projects/project-status-badge';
import { buttonVariants } from '@/components/ui/button';
import { AppShell } from '@/components/shell/app-shell';
import { edit } from '@/routes/projects';
import { index as milestonesIndex } from '@/routes/projects/milestones';
import type { BoardColumn, ProjectStatus } from '@/types/projects';

export type ProjectBoardProps = {
    project: { id: number; name: string; status: ProjectStatus };
    columns: BoardColumn[];
    abilities: {
        /** Structural mutation: quick-add, move, reorder (ProjectPolicy::manage, D1). */
        manage: boolean;
        /** Whether projects.edit will actually admit this actor today (A9); see ProjectBoardController. */
        openSettings: boolean;
    };
};

/**
 * EPIC-013 WP7 — the board on a `canvas` frame (§19.3).
 *
 * Canvas is the genuine win here: the board was already full-bleed, so moving it onto the frame hands
 * it the whole viewport minus the page gutters and lets its columns use the width the new shell
 * freed. Nothing about the board's own behaviour changes — drag, quick-add, move and reorder are the
 * `Board` component's, untouched.
 *
 * The header is the WP7 change worth naming. A project board is an *entity* page in Direction D's
 * grammar (§6: an entity page shows the record's name, its state, its actions, then the strata), so
 * the hand-rolled title row this page carried becomes the real `EntityHeader` and is where the strata
 * motif legitimately appears (§17 lists the project workspace by name). That replaced row also held a
 * hand-built "Projects /" trail; the shell's utility bar has owned the breadcrumb since WP4, so the
 * page no longer draws a second one beside it.
 */
export function ProjectBoardPage({ project, columns, abilities }: ProjectBoardProps) {
    return (
        <>
            <Head title={`${project.name} — Board`} />

            <PageFrame width="canvas" className="flex flex-col gap-4">
                <EntityHeader
                    overline="Project"
                    title={project.name}
                    status={<ProjectStatusBadge status={project.status} />}
                    actions={
                        <>
                            <Link
                                href={milestonesIndex.url(project.id)}
                                className={buttonVariants({ variant: 'secondary', size: 'sm' })}
                            >
                                Milestones
                            </Link>
                            {/* Still gated on the server's own answer: `openSettings` is whether
                                projects.edit will actually admit this actor (A9), not a guess. */}
                            {abilities.openSettings ? (
                                <Link
                                    href={edit.url(project.id)}
                                    className={buttonVariants({ variant: 'secondary', size: 'sm' })}
                                >
                                    Settings
                                </Link>
                            ) : null}
                        </>
                    }
                />

                <Board projectId={project.id} columns={columns} abilities={abilities} />
            </PageFrame>
        </>
    );
}

ProjectBoardPage.layout = (page: ReactElement) => <AppShell>{page}</AppShell>;

export default ProjectBoardPage;
