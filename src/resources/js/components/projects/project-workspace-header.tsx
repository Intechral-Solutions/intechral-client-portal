import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { EntityHeader } from '@/components/entity-header';
import { ProjectStatusBadge } from '@/components/projects/project-status-badge';
import {
    ProjectWorkspaceNav,
    type ProjectWorkspaceSection,
    type ProjectWorkspaceTabs,
} from '@/components/projects/project-workspace-nav';
import { buttonVariants } from '@/components/ui/button';
import { edit } from '@/routes/projects';
import type { ProjectStatus } from '@/types/projects';

/**
 * EPIC-015 WP4 — the one project header, shared by every workspace page (Overview, Board, Tasks,
 * Milestones, and Time from WP5; §11.1, §14.1, Direction D §6).
 *
 * It is the `EntityHeader` grammar the Overview established in WP2, in one place so the four pages
 * cannot drift apart: overline "Project", the name as the page's one `h1`, the **lifecycle** status
 * beside it (what someone set; derived health is the Overview's own section), the Settings action, and
 * `ProjectWorkspaceNav` on the strata with the current page marked.
 *
 * Settings is a header action, never a fifth tab. It renders only when the caller passes
 * `openSettings`, which must be the server's `ProjectSettingsAccess` answer (A9): this component makes
 * no capability decision of its own.
 */
export function ProjectWorkspaceHeader({
    project,
    current,
    openSettings = false,
    meta,
    tabs,
}: {
    project: { id: number; name: string; status: ProjectStatus };
    current: ProjectWorkspaceSection;
    /** The server's optional-tab answers (WP5 Time), passed straight to the navigation. */
    tabs?: ProjectWorkspaceTabs;
    /** The server's Settings-access answer. Absent or false: no Settings action. */
    openSettings?: boolean;
    /** Key facts beside the status (the Overview's dates). */
    meta?: ReactNode;
}) {
    return (
        <EntityHeader
            overline="Project"
            title={project.name}
            status={<ProjectStatusBadge status={project.status} />}
            meta={meta}
            actions={
                openSettings ? (
                    <Link
                        href={edit.url(project.id)}
                        className={buttonVariants({ variant: 'secondary', size: 'sm' })}
                    >
                        Settings
                    </Link>
                ) : undefined
            }
            navigation={
                <ProjectWorkspaceNav projectId={project.id} current={current} tabs={tabs} />
            }
        />
    );
}
