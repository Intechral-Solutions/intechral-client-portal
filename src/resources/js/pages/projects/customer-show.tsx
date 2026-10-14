import { Head, Link } from '@inertiajs/react';
import { Flag } from 'lucide-react';
import type { ReactElement } from 'react';

import { PageFrame } from '@/components/page-frame';
import { PageHeader } from '@/components/page-header';
import { ProjectStatusBadge } from '@/components/projects/project-status-badge';
import { Section } from '@/components/section';
import { AppShell } from '@/components/shell/app-shell';
import { EmptyState } from '@/components/ui/empty-state';
import { formatDate } from '@/lib/dates';
import { index } from '@/routes/projects';
import type { CustomerMilestone, ProjectStatus } from '@/types/projects';

/**
 * The project overview a Release 0.9 pilot customer receives (R0.9-CS1): the project's name and
 * lifecycle status, and its milestones by name and due date. The server
 * sends nothing else (`CustomerProjectPresenter`), so there is no board, task, comment, checklist,
 * time, budget, member or health section to render or to hide.
 */
export type CustomerProjectShowProps = {
    project: { name: string; status: ProjectStatus };
    milestones: CustomerMilestone[];
};

export function CustomerProjectShowPage({ project, milestones }: CustomerProjectShowProps) {
    return (
        <>
            <Head title={project.name} />
            <PageFrame width="canvas" className="flex flex-col gap-8">
                <PageHeader
                    overline="Project"
                    title={project.name}
                    actions={
                        <Link
                            href={index.url()}
                            className="rounded-control text-sm font-medium text-text-secondary hover:text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                        >
                            All projects
                        </Link>
                    }
                />

                <Section title="Status">
                    <ProjectStatusBadge status={project.status} />
                </Section>

                <Section title="Milestones">
                    {milestones.length === 0 ? (
                        <EmptyState
                            icon={Flag}
                            title="No milestones yet"
                            description="Milestones appear here once they are planned."
                        />
                    ) : (
                        <ul className="divide-y divide-rule border-b border-rule">
                            {milestones.map((milestone, position) => (
                                <li
                                    key={position}
                                    className="flex items-center justify-between gap-4 px-1 py-3"
                                >
                                    <div className="min-w-0">
                                        <p className="text-sm font-medium break-words text-text">
                                            {milestone.name}
                                        </p>
                                        <p className="mt-0.5 text-xs text-text-secondary">
                                            Due{' '}
                                            <time dateTime={milestone.dueDate}>
                                                {formatDate(milestone.dueDate)}
                                            </time>
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            </PageFrame>
        </>
    );
}

CustomerProjectShowPage.layout = (page: ReactElement) => <AppShell>{page}</AppShell>;

export default CustomerProjectShowPage;
