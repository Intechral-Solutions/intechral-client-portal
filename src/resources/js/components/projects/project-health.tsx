import { Status, type StatusGlyph, type StatusTone } from '@/components/ui/status';
import { formatDate } from '@/lib/dates';
import { cn } from '@/lib/utils';
import type { ProjectHealthDto, ProjectHealthReason, ProjectHealthState } from '@/types/projects';

/**
 * EPIC-015 WP2 — derived project health, rendered (§8, Direction D §10.1).
 *
 * Health is a pure function of server facts (`ProjectHealth`, INV-P13): this file renders the state,
 * label and reasons it is given and never re-runs a rule. It is **separate from lifecycle status**
 * (`ProjectStatusBadge`): Active/On hold/Completed/Archived is what someone set; On track/At risk/Off
 * track is what the data says. A project with no derived health (on hold, archived) receives `null`
 * and this renders nothing; React never invents a state for it.
 */

/** Direction D §10.1's glyphs. "Not enough data" is deliberately absent: it is muted text, not health. */
const marks: Record<
    Exclude<ProjectHealthState, 'insufficient_data'>,
    { tone: StatusTone; glyph: StatusGlyph }
> = {
    on_track: { tone: 'success', glyph: 'dot' },
    at_risk: { tone: 'warning', glyph: 'triangle' },
    off_track: { tone: 'danger', glyph: 'square' },
    not_started: { tone: 'neutral', glyph: 'circle' },
    complete: { tone: 'success', glyph: 'check' },
};

function plural(count: number, one: string, many: string) {
    return `${count} ${count === 1 ? one : many}`;
}

/**
 * One structured reason as a sentence. Unknown codes return null and are skipped, so a reason added on
 * the server later is never rendered as a raw code.
 */
export function healthReasonText(reason: ProjectHealthReason): string | null {
    switch (reason.code) {
        case 'target_passed':
            return `Target date has passed with ${plural(reason.openTaskCount, 'open task', 'open tasks')}`;
        case 'milestones_overdue': {
            if (!reason.earliest) {
                return `${plural(reason.count, 'milestone', 'milestones')} overdue`;
            }

            const due = formatDate(reason.earliest.dueDate);

            return reason.count === 1
                ? `Milestone “${reason.earliest.name}” is overdue (due ${due})`
                : `${reason.count} milestones overdue, earliest “${reason.earliest.name}” (due ${due})`;
        }
        case 'tasks_overdue':
            return `${plural(reason.count, 'task', 'tasks')} overdue`;
        case 'starts_in_future':
            return `Starts ${formatDate(reason.date)}`;
        case 'no_tracked_work':
            return 'No tasks or milestones yet';
        default:
            return null;
    }
}

/** The health mark alone: glyph + label, or muted text for the neutral "Not enough data" state. */
export function ProjectHealthStatus({ health }: { health: ProjectHealthDto }) {
    if (health.state === 'insufficient_data') {
        return <span className="text-sm font-medium text-text-muted">{health.label}</span>;
    }

    const mark = marks[health.state];

    return mark ? (
        <Status tone={mark.tone} glyph={mark.glyph} data-health-state={health.state}>
            {health.label}
        </Status>
    ) : (
        <span className="text-sm font-medium text-text-muted">{health.label}</span>
    );
}

/** The mark plus the reasons, in the server's fixed order, as a compact list. */
export function ProjectHealthSummary({
    health,
    className,
}: {
    health: ProjectHealthDto;
    className?: string;
}) {
    const reasons = health.reasons
        .map((reason) => ({ code: reason.code, text: healthReasonText(reason) }))
        .filter(
            (reason): reason is { code: ProjectHealthReason['code']; text: string } =>
                reason.text !== null,
        );

    return (
        <div className={cn('flex flex-col gap-2', className)}>
            <ProjectHealthStatus health={health} />
            {reasons.length ? (
                <ul
                    role="list"
                    aria-label="Health reasons"
                    className="space-y-1 text-sm text-text-secondary"
                >
                    {reasons.map((reason) => (
                        <li key={reason.code} data-reason={reason.code}>
                            {reason.text}
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}
