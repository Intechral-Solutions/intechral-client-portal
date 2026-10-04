import type { CSSProperties } from 'react';

import { cn } from '@/lib/utils';

/**
 * EPIC-015 WP2 — the staged path (Direction D §11.2, EPIC-015 §14.2).
 *
 * A path of **named, ordered, discrete** stages, never a quantity: "12 of 30 tasks" is a `Progress`
 * bar, and a lifecycle is never a percentage (§11.1). Segments rise like the brand's strata: 6px, then
 * +3px per step, capped at 24 (14 in the compact variant).
 *
 * **It knows nothing about any domain.** No milestone, phase or project concept appears here: the
 * caller maps its own records to `stages` and writes every piece of copy (the `label`, each stage's
 * `meta`, and the summaries of hidden stages). The only thing this component decides is which stages
 * are visible when there are more than seven, by stage position and state alone (`stagePathWindow`).
 *
 * State is always in text, never colour or position alone: each stage carries its state word
 * (visible in `full`, visually hidden in `compact`), the list is a real list, and the current stage is
 * `aria-current="step"`.
 */

export type StageState = 'done' | 'current' | 'planned' | 'blocked';

export type Stage = {
    key: string;
    label: string;
    state: StageState;
    /** Supplementary plain text after the state word, such as a date ("Due 3 Oct"). */
    meta?: string;
};

/** Direction D §11.2's ceiling for a staged path. */
export const STAGE_PATH_LIMIT = 7;

const stateWords: Record<StageState, string> = {
    done: 'Done',
    current: 'Current',
    planned: 'Planned',
    blocked: 'Blocked',
};

/**
 * The visible window, `[start, end)` over `stages` (EPIC-015 §14.2): at most `limit` stages in stable
 * index order; the current stage is always visible (without one, anchor on the first stage, or on the
 * last when every stage is done); the stages after the anchor come next; earlier stages backfill
 * whatever room remains. Exported so a caller can count what is hidden on each side for its own copy.
 */
export function stagePathWindow(
    stages: readonly Pick<Stage, 'state'>[],
    limit: number = STAGE_PATH_LIMIT,
): { start: number; end: number } {
    const total = stages.length;

    if (total <= limit) return { start: 0, end: total };

    const current = stages.findIndex((stage) => stage.state === 'current');
    const anchor =
        current !== -1 ? current : stages.every((stage) => stage.state === 'done') ? total - 1 : 0;
    const end = Math.min(total, anchor + limit);

    return { start: Math.max(0, end - limit), end };
}

const segmentClass: Record<StageState, string> = {
    done: 'bg-progress-fill',
    current: 'bg-live',
    planned: 'border border-dashed border-stage-future bg-transparent',
    blocked: 'bg-warning-glyph',
};

function segmentHeight(position: number, cap: number) {
    return Math.min(6 + position * 3, cap);
}

type StagePathProps = {
    stages: Stage[];
    /** `full` names each stage with its state and meta; `compact` is segments only (§11.2). */
    variant?: 'full' | 'compact';
    /** The list's accessible name, stating the whole path ("Milestones: 3 of 12 complete"). */
    label: string;
    /** Caller-written summary of the stages hidden before the window, e.g. "5 earlier". */
    hiddenBefore?: string;
    /** Caller-written summary of the stages hidden after the window, e.g. "3 later". */
    hiddenAfter?: string;
    className?: string;
};

export function StagePath({
    stages,
    variant = 'full',
    label,
    hiddenBefore,
    hiddenAfter,
    className,
}: StagePathProps) {
    const { start, end } = stagePathWindow(stages);
    const visible = stages.slice(start, end);
    const compact = variant === 'compact';
    const cap = compact ? 14 : 24;
    const summaryClass = 'shrink-0 font-mono text-xs text-text-muted';

    return (
        <div
            data-stage-path={variant}
            className={cn(
                'flex min-w-0 gap-3',
                compact ? 'items-end' : 'flex-col sm:flex-row sm:items-start',
                className,
            )}
        >
            {start > 0 && hiddenBefore ? <p className={summaryClass}>{hiddenBefore}</p> : null}

            <ol
                // Tailwind's list reset strips list semantics in some engines; the role restores it.
                role="list"
                aria-label={label}
                className={cn(
                    'min-w-0 flex-1',
                    // Compact segments always sit side by side. A full path does from S/M up; at
                    // phone widths seven labelled columns cannot fit, so it stacks in the same order.
                    compact ? 'grid items-end gap-1' : 'flex flex-col gap-2 sm:grid sm:items-start sm:gap-1',
                )}
                style={{ gridTemplateColumns: `repeat(${visible.length}, minmax(0, 1fr))` }}
            >
                {visible.map((stage, position) => (
                    <li
                        key={stage.key}
                        data-stage-state={stage.state}
                        aria-current={stage.state === 'current' ? 'step' : undefined}
                        className={cn(
                            'min-w-0',
                            compact ? 'flex flex-col justify-end' : 'flex items-center gap-2 sm:block',
                        )}
                    >
                        {/* The segment row is the tallest segment's height and every segment sits on
                            its floor, so the path rises left to right like the strata. */}
                        <span
                            aria-hidden="true"
                            className={cn(
                                'flex shrink-0 items-end',
                                compact ? 'w-full' : 'w-6 sm:h-6 sm:w-full',
                            )}
                        >
                            <span
                                data-stage-segment=""
                                className={cn(
                                    'block w-full rounded-[2px]',
                                    // Phone-width full path: a short marker beside the text.
                                    !compact && 'h-1.5 sm:h-[var(--stage-height)]',
                                    segmentClass[stage.state],
                                )}
                                style={
                                    compact
                                        ? { height: segmentHeight(position, cap) }
                                        : ({
                                              '--stage-height': `${segmentHeight(position, cap)}px`,
                                          } as CSSProperties)
                                }
                            />
                        </span>

                        {compact ? (
                            <span className="sr-only">
                                {`${stage.label}: ${stateWords[stage.state]}${stage.meta ? `, ${stage.meta}` : ''}`}
                            </span>
                        ) : (
                            <span className="block min-w-0 sm:mt-2">
                                <span className="block text-sm font-medium break-words text-text">
                                    {stage.label}
                                </span>
                                <span
                                    className={cn(
                                        'block text-xs',
                                        stage.state === 'current'
                                            ? 'text-live-text'
                                            : stage.state === 'blocked'
                                              ? 'text-warning'
                                              : 'text-text-muted',
                                    )}
                                >
                                    {stateWords[stage.state]}
                                    {stage.meta ? ` · ${stage.meta}` : ''}
                                </span>
                            </span>
                        )}
                    </li>
                ))}
            </ol>

            {end < stages.length && hiddenAfter ? (
                <p className={summaryClass}>{hiddenAfter}</p>
            ) : null}
        </div>
    );
}
