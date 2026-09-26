import { Head, Link } from '@inertiajs/react';
import type { ReactElement } from 'react';

import { Board } from '@/components/projects/board';
import { ProjectStatusBadge } from '@/components/projects/project-status-badge';
import { buttonVariants } from '@/components/ui/button';
import { AppShell } from '@/components/shell/app-shell';
import { edit, index } from '@/routes/projects';
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

export function ProjectBoardPage({ project, columns, abilities }: ProjectBoardProps) {
    return (
        <>
            <Head title={`${project.name} — Board`} />
            <div className="flex flex-col gap-4 px-4 py-6 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-3">
                        <Link
                            href={index.url()}
                            className="shrink-0 text-sm text-muted-foreground hover:underline"
                        >
                            Projects
                        </Link>
                        <span aria-hidden="true" className="text-border">
                            /
                        </span>
                        <h1 className="truncate text-lg font-semibold">{project.name}</h1>
                        <ProjectStatusBadge status={project.status} />
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                        <Link
                            href={milestonesIndex.url(project.id)}
                            className={buttonVariants({ variant: 'outline', size: 'sm' })}
                        >
                            Milestones
                        </Link>
                        {abilities.openSettings ? (
                            <Link
                                href={edit.url(project.id)}
                                className={buttonVariants({ variant: 'outline', size: 'sm' })}
                            >
                                Settings
                            </Link>
                        ) : null}
                    </div>
                </div>

                <Board projectId={project.id} columns={columns} abilities={abilities} />
            </div>
        </>
    );
}

ProjectBoardPage.layout = (page: ReactElement) => <AppShell>{page}</AppShell>;

export default ProjectBoardPage;
