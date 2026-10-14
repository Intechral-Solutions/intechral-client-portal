import { Head, Link } from '@inertiajs/react';
import { FolderKanban } from 'lucide-react';
import type { ReactElement } from 'react';

import { PageFrame } from '@/components/page-frame';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { ProjectStatusBadge } from '@/components/projects/project-status-badge';
import { AppShell } from '@/components/shell/app-shell';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { EmptyState } from '@/components/ui/empty-state';
import { show } from '@/routes/projects';
import type { Paginated } from '@/types/pagination';
import type { CustomerProjectRow } from '@/types/projects';

/**
 * The projects list a Release 0.9 pilot customer receives (R0.9-CS1). The server sends a name and a
 * lifecycle status per project and nothing more, so this page has no health, progress, target date,
 * member count or create action to render: it cannot show what it was never given.
 */
export type CustomerProjectsIndexProps = {
    projects: Paginated<CustomerProjectRow>;
};

const columns: DataTableColumn<CustomerProjectRow>[] = [
    {
        id: 'name',
        header: 'Project',
        area: 'title',
        className: 'min-w-0',
        cell: (project) => (
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
];

export function CustomerProjectsIndexPage({ projects }: CustomerProjectsIndexProps) {
    return (
        <>
            <Head title="Projects" />
            <PageFrame width="canvas" className="flex flex-col gap-5">
                <PageHeader
                    title="Projects"
                    description="The projects you have been given access to."
                />

                {projects.data.length === 0 ? (
                    <EmptyState
                        icon={FolderKanban}
                        title="No projects yet"
                        description="Projects you are added to will appear here."
                    />
                ) : (
                    <DataTable<CustomerProjectRow, number>
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

CustomerProjectsIndexPage.layout = (page: ReactElement) => <AppShell>{page}</AppShell>;

export default CustomerProjectsIndexPage;
