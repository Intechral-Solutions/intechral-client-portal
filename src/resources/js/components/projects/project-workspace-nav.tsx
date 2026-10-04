import { PageTabs } from '@/components/page-tabs';
import { board, show } from '@/routes/projects';
import { index as milestonesIndex } from '@/routes/projects/milestones';

/**
 * EPIC-015 WP2 — the project workspace navigation (§11.1, §11.3.1, §11.4).
 *
 * The final workspace is **Overview · Board · Tasks · Milestones**, but a package renders only the
 * destinations that exist when it merges, so no tab ever answers 404/405. After WP2 that is Overview,
 * Board and Milestones; WP3 adds Tasks (`projects.tasks.index`) here, and WP4 puts this same component
 * on the Board and Milestones pages. Every destination is `ProjectPolicy::view`-gated, the same gate as
 * the page that renders this, so every viewer of a project page may open every tab shown.
 */

export type ProjectWorkspaceSection = 'overview' | 'board' | 'milestones';

export function ProjectWorkspaceNav({
    projectId,
    current,
}: {
    projectId: number;
    current: ProjectWorkspaceSection;
}) {
    return (
        <PageTabs
            label="Project"
            current={current}
            tabs={[
                { key: 'overview', label: 'Overview', href: show.url(projectId) },
                { key: 'board', label: 'Board', href: board.url(projectId) },
                { key: 'milestones', label: 'Milestones', href: milestonesIndex.url(projectId) },
            ]}
        />
    );
}
