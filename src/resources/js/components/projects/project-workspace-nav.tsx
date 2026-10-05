import { PageTabs } from '@/components/page-tabs';
import { board, show } from '@/routes/projects';
import { index as milestonesIndex } from '@/routes/projects/milestones';
import { index as tasksIndex } from '@/routes/projects/tasks';
import { index as timeIndex } from '@/routes/projects/time';

/**
 * EPIC-015 — the project workspace navigation (§11.1, §11.3.1, §11.4).
 *
 * The final workspace is **Overview · Board · Tasks · Milestones**, and a package renders only the
 * destinations that exist when it merges, so no tab ever answers 404/405. WP2 shipped Overview, Board
 * and Milestones; WP3 adds Tasks (`projects.tasks.index`), completing the set; WP4 puts this same
 * component on the Board and Milestones pages. Every destination is `ProjectPolicy::view`-gated, the
 * same gate as the page that renders this, so every viewer of a project page may open every tab shown.
 *
 * WP5 (optional S3) adds **Time** last, and only when the server's `tabs.time` says the viewer has a
 * project-time scope (`ProjectTimeAccess`, the gate `projects.time.index` itself applies), so the tab
 * never leads to a 403. This component decides nothing from roles or permissions.
 */

export type ProjectWorkspaceSection = 'overview' | 'board' | 'tasks' | 'milestones' | 'time';

/** The server's answer for the optional tabs (`ProjectPresenter::workspaceTabs`). */
export type ProjectWorkspaceTabs = { time: boolean };

export function ProjectWorkspaceNav({
    projectId,
    current,
    tabs,
}: {
    projectId: number;
    current: ProjectWorkspaceSection;
    /** Absent: only the four `view`-gated tabs. */
    tabs?: ProjectWorkspaceTabs;
}) {
    return (
        <PageTabs
            label="Project"
            current={current}
            tabs={[
                { key: 'overview', label: 'Overview', href: show.url(projectId) },
                { key: 'board', label: 'Board', href: board.url(projectId) },
                { key: 'tasks', label: 'Tasks', href: tasksIndex.url(projectId) },
                { key: 'milestones', label: 'Milestones', href: milestonesIndex.url(projectId) },
                ...(tabs?.time
                    ? [{ key: 'time', label: 'Time', href: timeIndex.url(projectId) }]
                    : []),
            ]}
        />
    );
}
