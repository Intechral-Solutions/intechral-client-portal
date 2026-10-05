import { Head, Link } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import type { ReactElement } from 'react';

import { PageFrame } from '@/components/page-frame';
import { Pagination } from '@/components/pagination';
import { ProjectWorkspaceHeader } from '@/components/projects/project-workspace-header';
import type { ProjectWorkspaceTabs } from '@/components/projects/project-workspace-nav';
import { AppShell } from '@/components/shell/app-shell';
import { buttonVariants } from '@/components/ui/button';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { EmptyState } from '@/components/ui/empty-state';
import { formatDate } from '@/lib/dates';
import { formatMinutes } from '@/lib/duration';
import { layoutPageProps } from '@/lib/inertia-layout';
import { show } from '@/routes/projects';
import { index as timeIndex } from '@/routes/projects/time';
import type { Paginated } from '@/types/pagination';
import type { ProjectStatus } from '@/types/projects';

/** One settled entry attributed to this project (`ProjectTimePresenter::row`). */
export type ProjectTimeRow = {
    id: number;
    /** `YYYY-MM-DD`, a calendar day, never timezone-converted. */
    date: string;
    durationMinutes: number;
    /** A valid board task of this project, or direct project time with no task. */
    context: { kind: 'task'; id: number; title: string; url: string } | { kind: 'project' };
    /** Present only in `all` scope (`time.view_all`); the key is absent in `own` scope. */
    userName?: string;
};

export type ProjectTimeProps = {
    project: { id: number; name: string; status: ProjectStatus };
    /** `ProjectTimeAccess`: `all` with `time.view_all`, `own` with `time.log` alone. */
    summary: { scope: 'all' | 'own'; totalMinutes: number };
    entries: Paginated<ProjectTimeRow>;
    tabs?: ProjectWorkspaceTabs;
    /** `openSettings` only with effective Settings access (A9); absent otherwise (PHP sends `[]`). */
    abilities: Partial<{ openSettings: true }>;
};

/**
 * EPIC-015 WP5 (optional S3) — the project Time tab: this project's time, read only.
 *
 * Everything arrives decided. The rows and the total are the canonical project-time attribution
 * (§10: task time under the task's project, direct time under its own) over **settled** entries, and
 * the total is the very figure the Overview shows, so the two never disagree. The scope is the
 * server's: "All team members" names each person; "Your entries only" sends no other person at all,
 * so nothing here can reveal who else logged time or how much. Scope is always said in words.
 *
 * Read only by design: no timer, no logging, no edit. Entries are owned and edited on My time, and
 * this tab adds no time-entry behaviour (WP5 keeps Timer UX out of scope). No money, no charts.
 */
export function ProjectTimePage({ project, summary, entries, tabs, abilities }: ProjectTimeProps) {
    const all = summary.scope === 'all';

    const columns: DataTableColumn<ProjectTimeRow>[] = [
        {
            id: 'date',
            header: 'Date',
            area: 'meta',
            className: 'whitespace-nowrap',
            cell: (row) => <time dateTime={row.date}>{formatDate(row.date)}</time>,
        },
        ...(all
            ? [
                  {
                      id: 'person',
                      header: 'Person',
                      area: 'meta' as const,
                      cell: (row: ProjectTimeRow) => row.userName,
                  },
              ]
            : []),
        {
            id: 'context',
            header: 'Logged against',
            area: 'title',
            cell: (row) =>
                row.context.kind === 'task' ? (
                    <Link
                        href={row.context.url}
                        className="font-medium break-words text-text underline-offset-2 hover:underline"
                    >
                        {row.context.title}
                    </Link>
                ) : (
                    <span className="text-text-secondary">Project (no task)</span>
                ),
        },
        {
            id: 'duration',
            header: 'Duration',
            area: 'meta',
            // Left-aligned like its header: the shared DataTable aligns every header left.
            className: 'whitespace-nowrap',
            cell: (row) => (
                <span className="font-mono tabular-nums">{formatMinutes(row.durationMinutes)}</span>
            ),
        },
    ];

    return (
        <>
            <Head title={`${project.name} — Time`} />
            <PageFrame width="canvas" className="flex flex-col gap-5">
                <ProjectWorkspaceHeader
                    project={project}
                    current="time"
                    // The server's answer (ProjectSettingsAccess, A9), never a role check here.
                    openSettings={abilities.openSettings === true}
                    tabs={tabs}
                />

                <h2 className="sr-only">Time</h2>

                <dl
                    aria-label="Project time summary"
                    className="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-y border-rule py-3"
                >
                    <dt className="text-sm text-text-secondary">
                        {all ? 'Time logged' : 'Your time'}
                    </dt>
                    <dd className="font-mono text-lg text-text tabular-nums">
                        {formatMinutes(summary.totalMinutes)}
                    </dd>
                    <dd className="text-sm text-text-muted">
                        {all ? 'All team members' : 'Your entries only'}
                    </dd>
                </dl>

                {entries.total === 0 ? (
                    <EmptyState
                        icon={Clock}
                        title={
                            all
                                ? 'No time logged on this project yet'
                                : 'You have not logged time on this project yet'
                        }
                        description={
                            all
                                ? 'Time logged against this project or its tasks appears here.'
                                : 'Time you log against this project or its tasks appears here.'
                        }
                    />
                ) : entries.data.length === 0 ? (
                    // The scope has entries (the total above says so) but this page is past the last
                    // one, e.g. a stale `?page=`. Never claim there is no time; offer the way back.
                    <EmptyState
                        icon={Clock}
                        title="No entries on this page"
                        description="This page is past the end of the list."
                        action={
                            <Link
                                href={timeIndex.url(project.id)}
                                className={buttonVariants({ variant: 'secondary' })}
                            >
                                Back to the first page
                            </Link>
                        }
                    />
                ) : (
                    <DataTable
                        label="Time entries"
                        columns={columns}
                        rows={entries.data}
                        getRowKey={(row) => row.id}
                    />
                )}

                {entries.last_page > 1 ? <Pagination paginator={entries} /> : null}
            </PageFrame>
        </>
    );
}

/**
 * The shell's one breadcrumb: `Projects › All projects › {project} › Time`, the project segment
 * opening its Overview (§11.3). No page-owned breadcrumb.
 */
ProjectTimePage.layout = (page: ReactElement) => {
    const props = layoutPageProps<ProjectTimeProps>(page);

    return (
        <AppShell
            trail={
                props && [
                    { label: props.project.name, href: show.url(props.project.id) },
                    { label: 'Time' },
                ]
            }
        >
            {page}
        </AppShell>
    );
};

export default ProjectTimePage;
