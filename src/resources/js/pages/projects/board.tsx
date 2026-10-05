import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';

import { PageFrame } from '@/components/page-frame';
import { Board } from '@/components/projects/board';
import { ProjectWorkspaceHeader } from '@/components/projects/project-workspace-header';
import { AppShell } from '@/components/shell/app-shell';
import { layoutPageProps } from '@/lib/inertia-layout';
import { show } from '@/routes/projects';
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
 * EPIC-013 WP7 — the board on a `canvas` frame (§19.3); EPIC-015 WP4 — inside the project workspace.
 *
 * Canvas is the genuine win here: the board was already full-bleed, so the frame hands it the whole
 * viewport minus the page gutters. Nothing about the board's own behaviour changes — drag, quick-add,
 * move and reorder are the `Board` component's, untouched, and the Board stays where tasks are created
 * (P8).
 *
 * The header is the project's shared one (`ProjectWorkspaceHeader`, EPIC-015 §14.1): identity,
 * lifecycle, Settings when the server allows it, and the four project tabs with Board current. The
 * Milestones header link this page carried became the Milestones tab. The page's section heading is
 * visually hidden, as on the Tasks tab: the current tab and the trail already name it on screen.
 */
export function ProjectBoardPage({ project, columns, abilities }: ProjectBoardProps) {
    return (
        <>
            <Head title={`${project.name} — Board`} />

            <PageFrame width="canvas" className="flex flex-col gap-4">
                <ProjectWorkspaceHeader
                    project={project}
                    current="board"
                    // Still gated on the server's own answer: `openSettings` is whether
                    // projects.edit will actually admit this actor (A9), not a guess.
                    openSettings={abilities.openSettings}
                />

                <h2 className="sr-only">Board</h2>

                <Board projectId={project.id} columns={columns} abilities={abilities} />
            </PageFrame>
        </>
    );
}

/**
 * The shell's one breadcrumb names the project, linking to its Overview, then the Board (EPIC-015
 * §11.3). No page-owned breadcrumb.
 */
ProjectBoardPage.layout = (page: ReactElement) => {
    const props = layoutPageProps<ProjectBoardProps>(page);

    return (
        <AppShell
            trail={
                props && [
                    { label: props.project.name, href: show.url(props.project.id) },
                    { label: 'Board' },
                ]
            }
        >
            {page}
        </AppShell>
    );
};

export default ProjectBoardPage;
