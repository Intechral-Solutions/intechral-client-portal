import { Head, Link, router } from '@inertiajs/react';
import { FolderKanban, Plus } from 'lucide-react';
import type { ReactElement } from 'react';

import { PageFrame } from '@/components/page-frame';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { healthReasonText, ProjectHealthStatus } from '@/components/projects/project-health';
import { ProjectStatusBadge } from '@/components/projects/project-status-badge';
import { AppShell } from '@/components/shell/app-shell';
import { Button, buttonVariants } from '@/components/ui/button';
import { FilterChip } from '@/components/ui/chip';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar, FilterField } from '@/components/ui/filter-bar';
import { NativeSelect } from '@/components/ui/native-select';
import { Progress } from '@/components/ui/progress';
import { formatDate } from '@/lib/dates';
import { create, index, show } from '@/routes/projects';
import type { Paginated } from '@/types/pagination';
import type { ProjectIndexFilters, ProjectIndexRow, ProjectStatus } from '@/types/projects';

/**
 * EPIC-015 WP4 — the projects index (§11.1, §14.1): a scanning list on the canvas frame, not a grid of
 * Overview cards.
 *
 * One row per project, from the server's aggregates: the name (opening the project's Overview, Q5),
 * its **lifecycle** status (what someone set), its derived **health** (what the data says, the index's
 * count-only form), task progress, target date, next milestone and member count. Lifecycle and health
 * are separate columns and are never read as one signal. Nothing here derives health, progress or the
 * next milestone; it renders what the server sent.
 *
 * The one filter is lifecycle status, in the URL. Absent, it is every status (P5), as the index
 * always showed. At S each row is two bands (D9): the name, then status, health and progress; the
 * target, next milestone and member count stay in the accessibility tree.
 */
export type ProjectsIndexProps = {
    projects: Paginated<ProjectIndexRow>;
    filters: ProjectIndexFilters;
    filterOptions: { statuses: { value: ProjectStatus; label: string }[] };
    /** Whether any project is visible at all, so "none yet" and "none with this status" stay apart. */
    hasProjects: boolean;
    /** `create` is what the create route actually admits, not merely the create policy. */
    abilities: { create: boolean };
};

function plural(count: number, one: string, many: string) {
    return `${count} ${count === 1 ? one : many}`;
}

function NewProjectLink() {
    return (
        <Link href={create.url()} className={buttonVariants()}>
            <Plus aria-hidden="true" />
            New project
        </Link>
    );
}

function Missing({ label }: { label: string }) {
    return (
        <span className="text-text-muted">
            <span aria-hidden="true">—</span>
            <span className="sr-only">{label}</span>
        </span>
    );
}

function HealthCell({ project }: { project: ProjectIndexRow }) {
    // On hold and archived projects have no derived health: the lifecycle status says it.
    if (!project.health) return <Missing label="No health while not active" />;

    const reasons = project.health.reasons
        .map(healthReasonText)
        .filter((text): text is string => text !== null);

    return (
        <div className="flex flex-col gap-0.5">
            <ProjectHealthStatus health={project.health} />
            {reasons.length ? (
                <span className="text-xs text-text-secondary max-md:sr-only">
                    {reasons.join(' · ')}
                </span>
            ) : null}
        </div>
    );
}

function ProgressCell({ project }: { project: ProjectIndexRow }) {
    const { total, done, completion } = project.tasks;
    if (total === 0) return <span className="text-text-muted">No tasks</span>;

    const text = `${done} of ${plural(total, 'task', 'tasks')} done`;

    return (
        <div className="flex flex-col gap-1">
            <span className="text-text-secondary">{text}</span>
            <Progress
                className="w-28 max-md:hidden"
                value={done}
                max={total}
                label={`${project.name} task progress`}
                valueText={`${text}, ${completion}%`}
            />
        </div>
    );
}

const columns: DataTableColumn<ProjectIndexRow>[] = [
    {
        id: 'name',
        header: 'Project',
        area: 'title',
        className: 'min-w-0 md:w-[24%]',
        cell: (project) => (
            // Opening a project opens its Overview, the canonical project page (EPIC-015 Q5).
            <Link
                href={show.url(project.id)}
                className="font-medium break-words text-text hover:underline max-md:block max-md:truncate"
            >
                {project.name}
            </Link>
        ),
    },
    {
        id: 'status',
        header: 'Status',
        cell: (project) => <ProjectStatusBadge status={project.status} />,
    },
    {
        id: 'health',
        header: 'Health',
        className: 'md:w-[22%]',
        cell: (project) => <HealthCell project={project} />,
    },
    {
        id: 'progress',
        header: 'Tasks',
        cell: (project) => <ProgressCell project={project} />,
    },
    {
        id: 'target',
        header: 'Target',
        area: 'detail',
        cell: (project) =>
            project.targetDate ? (
                <time dateTime={project.targetDate} className="whitespace-nowrap">
                    {formatDate(project.targetDate)}
                </time>
            ) : (
                <Missing label="No target date" />
            ),
    },
    {
        id: 'next',
        header: 'Next milestone',
        area: 'detail',
        cell: (project) =>
            project.nextMilestone ? (
                <span className="flex flex-col">
                    <span className="break-words text-text">{project.nextMilestone.name}</span>
                    <span className="text-xs text-text-secondary">
                        Due{' '}
                        <time dateTime={project.nextMilestone.dueDate}>
                            {formatDate(project.nextMilestone.dueDate)}
                        </time>
                    </span>
                </span>
            ) : (
                <Missing label="No upcoming milestone" />
            ),
    },
    {
        id: 'members',
        header: 'Members',
        area: 'detail',
        cell: (project) => (
            <span className="whitespace-nowrap text-text-secondary">
                {plural(project.memberCount, 'member', 'members')}
            </span>
        ),
    },
];

export function ProjectsIndexPage({
    projects,
    filters,
    filterOptions,
    hasProjects,
    abilities,
}: ProjectsIndexProps) {
    const statusLabel = filterOptions.statuses.find(
        (option) => option.value === filters.status,
    )?.label;

    function visit(status: ProjectStatus | null) {
        router.get(index.url(), status ? { status } : {}, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    let empty: ReactElement | null = null;
    if (projects.data.length === 0) {
        empty = hasProjects ? (
            <EmptyState
                icon={FolderKanban}
                title="No projects with this status"
                description="Choose another status, or show every project."
                action={
                    <Button type="button" variant="secondary" onClick={() => visit(null)}>
                        Show all projects
                    </Button>
                }
            />
        ) : (
            <EmptyState
                icon={FolderKanban}
                title="No projects yet"
                description="Projects you create or are added to will appear here."
                action={
                    // Named apart from the header's New project, so the page never offers two
                    // identically named links.
                    abilities.create ? (
                        <Link
                            href={create.url()}
                            className={buttonVariants({ variant: 'secondary' })}
                        >
                            Create your first project
                        </Link>
                    ) : undefined
                }
            />
        );
    }

    return (
        <>
            <Head title="Projects" />
            <PageFrame width="canvas" className="flex flex-col gap-5">
                <PageHeader
                    title="Projects"
                    description="Every project you can see, with its health and progress."
                    actions={abilities.create ? <NewProjectLink /> : undefined}
                />

                <FilterBar
                    label="Filter projects"
                    chips={
                        statusLabel
                            ? [
                                  <FilterChip
                                      key="status"
                                      label={`Status: ${statusLabel}`}
                                      onRemove={() => visit(null)}
                                  />,
                              ]
                            : null
                    }
                    onClear={() => visit(null)}
                >
                    <FilterField label="Status">
                        <NativeSelect
                            className="w-full"
                            value={filters.status ?? ''}
                            onChange={(event) =>
                                visit((event.target.value || null) as ProjectStatus | null)
                            }
                        >
                            <option value="">All statuses</option>
                            {filterOptions.statuses.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FilterField>
                </FilterBar>

                {empty ?? (
                    <DataTable<ProjectIndexRow, number>
                        label="Projects"
                        columns={columns}
                        rows={projects.data}
                        getRowKey={(project) => project.id}
                    />
                )}

                {projects.last_page > 1 ? <Pagination paginator={projects} /> : null}
            </PageFrame>
        </>
    );
}

ProjectsIndexPage.layout = (page: ReactElement) => <AppShell>{page}</AppShell>;

export default ProjectsIndexPage;
