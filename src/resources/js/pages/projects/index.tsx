import { Head, Link } from '@inertiajs/react';
import { FolderKanban, Plus } from 'lucide-react';
import type { ReactElement } from 'react';

import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { ProjectCard } from '@/components/projects/project-card';
import { buttonVariants } from '@/components/ui/button';
import { AppLayout } from '@/layouts/app-layout';
import { create } from '@/routes/projects';
import type { Paginated } from '@/types/pagination';
import type { ProjectCardData } from '@/types/projects';

export type ProjectsIndexProps = {
    projects: Paginated<ProjectCardData>;
    /** `create` is what the create route actually admits, not merely the create policy. */
    abilities: { create: boolean };
};

function NewProjectLink() {
    return (
        <Link href={create.url()} className={buttonVariants()}>
            <Plus aria-hidden="true" />
            New project
        </Link>
    );
}

export function ProjectsIndexPage({ projects, abilities }: ProjectsIndexProps) {
    return (
        <>
            <Head title="Projects" />
            <div className="mx-auto max-w-7xl space-y-7 px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader
                    title="Projects"
                    description="Manage and track your projects."
                    actions={abilities.create ? <NewProjectLink /> : undefined}
                />

                {projects.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-md border border-dashed border-border px-4 py-14 text-center">
                        <FolderKanban
                            className="h-10 w-10 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p className="text-sm font-medium text-muted-foreground">
                            No projects found.
                        </p>
                        {abilities.create ? (
                            <Link
                                href={create.url()}
                                className="legacy-text-primary text-sm font-medium hover:underline"
                            >
                                Create your first project
                            </Link>
                        ) : null}
                    </div>
                ) : (
                    <section aria-label="Projects" className="space-y-6">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {projects.data.map((project) => (
                                <ProjectCard key={project.id} project={project} />
                            ))}
                        </div>
                        {projects.last_page > 1 ? <Pagination paginator={projects} /> : null}
                    </section>
                )}
            </div>
        </>
    );
}

ProjectsIndexPage.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;

export default ProjectsIndexPage;
