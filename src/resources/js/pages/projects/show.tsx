import { Head, Link } from '@inertiajs/react';
import { CalendarClock, Clock, ListChecks } from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';

import { PageFrame } from '@/components/page-frame';
import { milestoneStages, milestoneTaskText } from '@/components/projects/milestone-stages';
import { ProjectHealthSummary } from '@/components/projects/project-health';
import { ProjectWorkspaceHeader } from '@/components/projects/project-workspace-header';
import { Section } from '@/components/section';
import { AppShell } from '@/components/shell/app-shell';
import { buttonVariants } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Progress } from '@/components/ui/progress';
import { StagePath, stagePathWindow } from '@/components/ui/stage-path';
import { Status } from '@/components/ui/status';
import { Tag } from '@/components/ui/tag';
import { formatDate } from '@/lib/dates';
import { formatMinutes } from '@/lib/duration';
import { layoutPageProps } from '@/lib/inertia-layout';
import { cn } from '@/lib/utils';
import { board } from '@/routes/projects';
import { index as milestonesIndex } from '@/routes/projects/milestones';
import { index as tasksIndex } from '@/routes/projects/tasks';
import { index as timeIndex } from '@/routes/projects/time';
import type { MemberRole, MilestoneItem, ProjectOverviewProps } from '@/types/projects';

/**
 * EPIC-015 WP2 — the project Overview, the canonical `projects.show` page (§11, §12).
 *
 * It answers "how is this going?" for one project from one server DTO
 * (`ProjectOverviewPresenter`), and decides nothing the server already decided: health, progress,
 * milestone state and project time arrive computed, and every gated field (budget, the
 * names-and-roles roster, time, the Settings ability) is **absent as a key** for a viewer who may not
 * see it. This page renders what it was given and never hides a value it received (INV-P8).
 *
 * Composition (Amendment 2, the D3 gate waived): a `grid` frame. The entity header carries the
 * identity, the lifecycle status, the dates, the Settings action and the project tabs on the strata.
 * The main column is the operational summary in reading order: health and why, task progress with the
 * open and overdue counts, then milestones. The context rail holds the description and the gated
 * facts (time, budget, people), and stacks under the main column below XL.
 *
 * Lifecycle status (what someone set) sits beside the name; derived health (what the data says) is
 * its own labelled section, so the two are never read as one signal (§8, Direction D §10.1).
 */

const linkClass =
    'rounded-control text-sm font-medium text-text-secondary hover:text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';

const roleLabels: Record<MemberRole, string> = { manager: 'Manager', member: 'Member' };

/** Overdue milestone rows listed before the rest are summarised (the Milestones page has them all). */
const OVERDUE_ROWS = 5;

function plural(count: number, one: string, many: string) {
    return `${count} ${count === 1 ? one : many}`;
}

/**
 * The stored decimal string with thousands separators, in the edit form's unit ("Budget ($)"). The
 * string is never parsed into a JS number, so no value is rounded or re-derived (Q3: metadata only).
 */
function formatBudget(value: string): string {
    const [whole = '', fraction] = value.split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return `$${grouped}${fraction !== undefined ? `.${fraction}` : ''}`;
}

function DetailRow({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="font-medium text-text-muted">{label}</dt>
            <dd className="mt-0.5 text-text">{children}</dd>
        </div>
    );
}

// ── Tasks ──────────────────────────────────────────────────────────────────

/**
 * Task progress and the open/overdue counts. The board column decides "done" on the server; nothing
 * here derives it, and the copy says "tasks", never effort (every task weighs the same).
 *
 * The counts open the project's Tasks tab with the matching filter (§11.3.1, WP3): open tasks with
 * `completion=open`, overdue ones with `due=overdue` as well, so the list shows what was counted. The
 * empty state still points at the Board, where tasks are created (P8).
 */
function TasksSection({
    projectId,
    tasks,
}: {
    projectId: number;
    tasks: ProjectOverviewProps['tasks'];
}) {
    const boardUrl = board.url(projectId);
    const tasksUrl = tasksIndex.url(projectId);

    if (tasks.total === 0) {
        return (
            <Section title="Tasks">
                <EmptyState
                    className="py-5"
                    icon={ListChecks}
                    title="No tasks yet"
                    description="Tasks are added and moved on the project board."
                    action={
                        <Link
                            href={boardUrl}
                            className={buttonVariants({ variant: 'secondary', size: 'sm' })}
                        >
                            Open board
                        </Link>
                    }
                />
            </Section>
        );
    }

    const figures = [
        {
            key: 'open',
            label: 'Open',
            value: tasks.open,
            spoken: plural(tasks.open, 'open task', 'open tasks'),
            href: tasksIndex.url(projectId, { query: { completion: 'open' } }),
        },
        {
            key: 'overdue',
            label: 'Overdue',
            value: tasks.overdue,
            spoken: plural(tasks.overdue, 'overdue task', 'overdue tasks'),
            href: tasksIndex.url(projectId, { query: { completion: 'open', due: 'overdue' } }),
        },
    ];

    return (
        <Section
            title="Tasks"
            actions={
                <Link href={tasksUrl} className={linkClass}>
                    View tasks
                </Link>
            }
        >
            <div className="space-y-4">
                <div className="space-y-1.5">
                    <div className="flex items-baseline justify-between gap-4 text-sm">
                        <span className="text-text-secondary">
                            {tasks.done} of {plural(tasks.total, 'task', 'tasks')} complete
                        </span>
                        <span className="font-mono text-text tabular-nums">
                            {tasks.completion}%
                        </span>
                    </div>
                    <Progress
                        value={tasks.done}
                        max={tasks.total}
                        label="Task progress"
                        valueText={`${tasks.done} of ${plural(tasks.total, 'task', 'tasks')} complete, ${tasks.completion}%`}
                    />
                </div>

                <dl className="grid grid-cols-2 gap-px border-y border-rule bg-rule">
                    {figures.map((figure) => (
                        <div
                            key={figure.key}
                            data-task-figure={figure.key}
                            className="relative flex flex-col gap-1 bg-canvas px-4 py-3 transition-colors duration-motion-fast hover:bg-surface-hover"
                        >
                            <dt className="text-xs font-medium tracking-wide text-text-muted uppercase">
                                {figure.label}
                            </dt>
                            <dd
                                className={cn(
                                    'font-mono text-2xl leading-none font-semibold tabular-nums',
                                    figure.key === 'overdue' && figure.value > 0
                                        ? 'text-danger'
                                        : 'text-text',
                                )}
                            >
                                {figure.value}
                            </dd>
                            <p className="text-xs text-text-secondary">View in Tasks</p>
                            <Link
                                href={figure.href}
                                className="absolute inset-0 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus"
                                aria-label={`${figure.spoken}: view in Tasks`}
                            />
                        </div>
                    ))}
                </dl>
            </div>
        </Section>
    );
}

// ── Milestones ─────────────────────────────────────────────────────────────

type MilestoneRole = 'current' | 'next' | 'overdue';

const roleTags: Record<MilestoneRole, string> = {
    current: 'Current',
    next: 'Next',
    overdue: 'Overdue',
};

/**
 * The milestones worth naming on a summary: the current one, the next upcoming one, and any other
 * overdue ones, in the server's order and **each at most once**. `nextId` can equal `currentId` (the
 * current milestone is not overdue, so it is also the next upcoming one); it is then shown once, as
 * Current (Amendment 1 carried finding).
 */
export function keyMilestones(milestones: ProjectOverviewProps['milestones']) {
    const rows: { item: MilestoneItem; role: MilestoneRole }[] = [];

    for (const item of milestones.items) {
        if (item.id === milestones.currentId) rows.push({ item, role: 'current' });
        else if (item.id === milestones.nextId) rows.push({ item, role: 'next' });
        else if (item.overdue) rows.push({ item, role: 'overdue' });
    }

    return rows;
}

function MilestoneRow({ item, role }: { item: MilestoneItem; role: MilestoneRole }) {
    return (
        <li
            data-milestone-role={role}
            className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 py-3"
        >
            <div className="min-w-0">
                <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <Tag>{roleTags[role]}</Tag>
                    <span className="text-sm font-medium break-words text-text">{item.name}</span>
                </p>
                <p className="mt-1 text-xs text-text-muted">{milestoneTaskText(item)}</p>
            </div>

            {item.overdue ? (
                <span className="inline-flex items-center gap-1 text-sm font-medium text-danger">
                    <Clock className="size-3.5 shrink-0" aria-hidden="true" />
                    Overdue · <time dateTime={item.dueDate}>{formatDate(item.dueDate)}</time>
                </span>
            ) : (
                <span className="text-sm text-text-secondary">
                    Due <time dateTime={item.dueDate}>{formatDate(item.dueDate)}</time>
                </span>
            )}
        </li>
    );
}

function MilestonesSection({
    projectId,
    milestones,
}: {
    projectId: number;
    milestones: ProjectOverviewProps['milestones'];
}) {
    const milestonesUrl = milestonesIndex.url(projectId);

    if (milestones.total === 0) {
        return (
            <Section title="Milestones">
                <EmptyState
                    className="py-5"
                    icon={CalendarClock}
                    title="No milestones yet"
                    description="Milestones are dated checkpoints, such as kickoff or go-live."
                    action={
                        <Link
                            href={milestonesUrl}
                            className={buttonVariants({ variant: 'secondary', size: 'sm' })}
                        >
                            Open milestones
                        </Link>
                    }
                />
            </Section>
        );
    }

    const stages = milestoneStages(milestones.items, milestones.currentId);
    const { start, end } = stagePathWindow(stages);
    const rows = keyMilestones(milestones);
    const overdueRows = rows.filter((row) => row.role === 'overdue');
    const shown = [
        ...rows.filter((row) => row.role !== 'overdue' || overdueRows.indexOf(row) < OVERDUE_ROWS),
    ];
    const moreOverdue = overdueRows.length - Math.min(overdueRows.length, OVERDUE_ROWS);
    const allComplete = milestones.completed === milestones.total;

    return (
        <Section
            title="Milestones"
            actions={
                <Link href={milestonesUrl} className={linkClass}>
                    Open milestones
                </Link>
            }
        >
            <div className="space-y-3">
                <p className="text-sm text-text-secondary">
                    {milestones.completed} of {plural(milestones.total, 'milestone', 'milestones')}{' '}
                    complete
                    {milestones.overdue > 0 ? (
                        <span className="font-medium text-danger">
                            {' '}
                            · {milestones.overdue} overdue
                        </span>
                    ) : null}
                </p>

                <StagePath
                    variant="compact"
                    stages={stages}
                    label={`Milestones: ${milestones.completed} of ${milestones.total} complete`}
                    hiddenBefore={start > 0 ? `+${start} earlier` : undefined}
                    hiddenAfter={end < stages.length ? `+${stages.length - end} later` : undefined}
                />

                {allComplete ? (
                    <Status tone="success" glyph="check">
                        All milestones complete
                    </Status>
                ) : (
                    <ul
                        role="list"
                        aria-label="Key milestones"
                        className="divide-y divide-rule border-y border-rule"
                    >
                        {shown.map((row) => (
                            <MilestoneRow key={row.item.id} item={row.item} role={row.role} />
                        ))}
                    </ul>
                )}

                {moreOverdue > 0 ? (
                    <p className="text-sm text-text-secondary">
                        {plural(moreOverdue, 'more overdue milestone', 'more overdue milestones')}{' '}
                        on the{' '}
                        <Link
                            href={milestonesUrl}
                            className="underline underline-offset-2 hover:text-text"
                        >
                            Milestones page
                        </Link>
                    </p>
                ) : null}
            </div>
        </Section>
    );
}

// ── Context rail ───────────────────────────────────────────────────────────

/**
 * The gated facts. Each block renders only when its key is present: an authorized viewer's budget
 * key exists even when null ("Not set"); an unauthorized viewer has no key and sees no Budget row at
 * all, not a placeholder. Time says whose time it is, so "your time" never reads as the project total.
 */
function contextRail(props: ProjectOverviewProps): ReactNode {
    const { project, budget, members, time, tabs } = props;
    const hasBudget = 'budget' in props;
    const sections: ReactNode[] = [];
    // The summary links to the detailed Time tab only when the server offers that tab (WP5).
    const timeLink = tabs?.time ? (
        <Link
            href={timeIndex.url(project.id)}
            className="mt-1 inline-block text-xs text-text-secondary underline underline-offset-2 hover:text-text"
        >
            View time entries
        </Link>
    ) : null;

    if (project.description) {
        sections.push(
            <Section key="about" title="About">
                <p className="text-sm break-words whitespace-pre-line text-text">
                    {project.description}
                </p>
            </Section>,
        );
    }

    if (time || hasBudget) {
        sections.push(
            <Section key="details" title="Details">
                <dl className="space-y-3 text-sm">
                    {time ? (
                        time.scope === 'all' ? (
                            <DetailRow label="Time logged">
                                <span className="font-mono tabular-nums">
                                    {formatMinutes(time.totalMinutes)}
                                </span>
                                <span className="block text-xs text-text-muted">
                                    All team members
                                </span>
                                {timeLink}
                            </DetailRow>
                        ) : (
                            <DetailRow label="Your time">
                                <span className="font-mono tabular-nums">
                                    {formatMinutes(time.totalMinutes)}
                                </span>
                                <span className="block text-xs text-text-muted">
                                    Your entries only
                                </span>
                                {timeLink}
                            </DetailRow>
                        )
                    ) : null}
                    {hasBudget ? (
                        <DetailRow label="Budget">
                            {budget ? (
                                <span className="font-mono tabular-nums">
                                    {formatBudget(budget)}
                                </span>
                            ) : (
                                <span className="text-text-muted">Not set</span>
                            )}
                        </DetailRow>
                    ) : null}
                </dl>
            </Section>,
        );
    }

    if (members) {
        sections.push(
            <Section key="people" title="People">
                {members.length ? (
                    <ul role="list" className="space-y-2 text-sm">
                        {members.map((member) => (
                            <li
                                key={member.id}
                                className="flex items-baseline justify-between gap-3"
                            >
                                <span className="min-w-0 break-words text-text">{member.name}</span>
                                <span className="shrink-0 text-xs text-text-muted">
                                    {member.isOwner ? 'Owner · ' : ''}
                                    {roleLabels[member.role] ?? member.role}
                                </span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-sm text-text-muted">No members.</p>
                )}
            </Section>,
        );
    }

    return sections.length ? <div className="space-y-8">{sections}</div> : null;
}

// ── Page ───────────────────────────────────────────────────────────────────

export function ProjectShowPage(props: ProjectOverviewProps) {
    const { project, health, tasks, milestones, abilities, tabs } = props;
    const rail = contextRail(props);

    const dates = [
        project.startDate ? (
            <span key="start">
                Start <time dateTime={project.startDate}>{formatDate(project.startDate)}</time>
            </span>
        ) : null,
        project.targetDate ? (
            <span key="target">
                Target <time dateTime={project.targetDate}>{formatDate(project.targetDate)}</time>
            </span>
        ) : null,
    ].filter(Boolean);

    const header = (
        <ProjectWorkspaceHeader
            project={project}
            current="overview"
            // The server's answer (ProjectSettingsAccess, A9), never a role check here.
            openSettings={abilities.openSettings === true}
            tabs={tabs}
            meta={
                dates.length ? (
                    <span className="flex flex-wrap gap-x-4 gap-y-1">{dates}</span>
                ) : undefined
            }
        />
    );

    const main = (
        <div className="space-y-8">
            {health ? (
                <Section title="Health">
                    <ProjectHealthSummary health={health} />
                </Section>
            ) : null}
            <TasksSection projectId={project.id} tasks={tasks} />
            <MilestonesSection projectId={project.id} milestones={milestones} />
        </div>
    );

    return (
        <>
            <Head title={`${project.name} — Overview`} />

            {rail ? (
                <PageFrame width="grid" header={header} aside={rail} asideLabel="Project details">
                    {main}
                </PageFrame>
            ) : (
                <PageFrame width="grid" header={header}>
                    {main}
                </PageFrame>
            )}
        </>
    );
}

/**
 * The project reaches the shell's one breadcrumb as the page's trail (`Projects › {project}`, §11.3):
 * the shell's active view supplies "All projects", the page supplies itself. No page-owned breadcrumb.
 */
ProjectShowPage.layout = (page: ReactElement) => {
    const props = layoutPageProps<ProjectOverviewProps>(page);

    return <AppShell trail={props && [{ label: props.project.name }]}>{page}</AppShell>;
};

export default ProjectShowPage;
