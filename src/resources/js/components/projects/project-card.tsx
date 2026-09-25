import { Link } from '@inertiajs/react';

import { ProjectStatusBadge } from '@/components/projects/project-status-badge';
import { Progress } from '@/components/ui/progress';
import { formatDate } from '@/lib/dates';
import { board } from '@/routes/projects';
import type { ProjectCardData } from '@/types/projects';

export function ProjectCard({ project }: { project: ProjectCardData }) {
    return (
        <article
            aria-labelledby={`project-${project.id}-title`}
            className="relative flex flex-col gap-4 rounded-md border border-border bg-card p-5 text-card-foreground transition-shadow focus-within:ring-2 focus-within:ring-ring hover:shadow-md"
        >
            <div className="flex items-start justify-between gap-3">
                <h2
                    id={`project-${project.id}-title`}
                    className="text-base leading-snug font-semibold"
                >
                    {/* The board is a React page as of WP5 (EPIC-011E §21): an Inertia Link. The
                        stretched link makes the whole card clickable while the accessible name
                        stays the title. */}
                    <Link
                        href={board.url(project.id)}
                        className="outline-none after:absolute after:inset-0 after:content-['']"
                    >
                        {project.name}
                    </Link>
                </h2>
                <ProjectStatusBadge status={project.status} />
            </div>

            {project.description ? (
                <p className="line-clamp-2 text-sm text-muted-foreground">{project.description}</p>
            ) : null}

            <div className="space-y-1">
                <div className="flex justify-between text-xs text-muted-foreground">
                    <span>Completion</span>
                    <span>{project.completion}%</span>
                </div>
                <Progress
                    value={project.completion}
                    label={`${project.name} completion`}
                    valueText={`${project.completion}% complete`}
                />
            </div>

            <div className="flex items-center justify-between text-xs text-muted-foreground">
                <span>
                    {project.memberCount} {project.memberCount === 1 ? 'member' : 'members'}
                </span>
                {project.overdueCount > 0 ? (
                    <span className="font-medium text-[var(--text-danger)]">
                        {project.overdueCount} overdue
                    </span>
                ) : project.targetDate ? (
                    <span>Due {formatDate(project.targetDate)}</span>
                ) : null}
            </div>
        </article>
    );
}
