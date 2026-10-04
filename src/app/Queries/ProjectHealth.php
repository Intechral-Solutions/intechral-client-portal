<?php

namespace App\Queries;

use App\Models\Project;
use App\Models\ProjectMilestone;
use Illuminate\Database\Eloquent\Builder;

/**
 * THE project health authority (EPIC-015 §8, Q1, INV-P13). Derived only: no schema, no manual
 * override, no thresholds, no calendar-percentage inference. React renders the result and never
 * re-derives it.
 *
 * Three entry points, one rule:
 *   - `withFacts()` adds the aggregates to a project query (one query for any number of projects);
 *   - `forIndex()` derives from those aggregates with COUNT-ONLY reasons (`earliest` is null), so a
 *     page of projects costs no query per row;
 *   - `forOverview()` adds the earliest overdue milestone for a single project from one bounded
 *     query.
 * Both feed `derive()`, the pure function the truth table pins.
 *
 * Result: `null` for `on_hold`/`archived` (the lifecycle status carries the meaning), otherwise
 * `{state, label, reasons}` where `reasons` is a list of structured `{code, ...data}` items.
 */
final class ProjectHealth
{
    public const COMPLETE = 'complete';

    public const NOT_STARTED = 'not_started';

    public const INSUFFICIENT_DATA = 'insufficient_data';

    public const OFF_TRACK = 'off_track';

    public const AT_RISK = 'at_risk';

    public const ON_TRACK = 'on_track';

    public const LABELS = [
        self::COMPLETE => 'Complete',
        self::NOT_STARTED => 'Not started',
        self::INSUFFICIENT_DATA => 'Not enough data',
        self::OFF_TRACK => 'Off track',
        self::AT_RISK => 'At risk',
        self::ON_TRACK => 'On track',
    ];

    /** The aggregates `ProjectHealthFacts::fromAggregates()` reads, in the project query itself. */
    public static function withFacts(Builder $projects): Builder
    {
        return $projects->withTaskStats()->withMilestoneStats();
    }

    /**
     * Index form (§8.2 "Index versus Overview"): count-only reasons, never a named milestone.
     *
     * @return array{state: string, label: string, reasons: array<int, array<string, mixed>>}|null
     */
    public static function forIndex(Project $project): ?array
    {
        return self::derive(ProjectHealthFacts::fromAggregates($project));
    }

    /**
     * Overview form: `milestones_overdue.earliest` names the first overdue milestone by
     * (`due_date`, `id`), read by one bounded query only when one exists.
     *
     * @return array{state: string, label: string, reasons: array<int, array<string, mixed>>}|null
     */
    public static function forOverview(Project $project): ?array
    {
        $earliest = (int) $project->overdue_milestones_count > 0
            ? ProjectMilestone::query()
                ->where('project_id', $project->id)
                ->overdue()
                ->orderBy('due_date')
                ->orderBy('id')
                ->first(['id', 'name', 'due_date'])
            : null;

        return self::derive(ProjectHealthFacts::fromAggregates($project, $earliest));
    }

    /**
     * The §8.2 derivation, evaluated in order, first match wins. Pure.
     *
     * @return array{state: string, label: string, reasons: array<int, array<string, mixed>>}|null
     */
    public static function derive(ProjectHealthFacts $facts): ?array
    {
        // 1-2. Lifecycle first. Anything that is not `active` or `completed` (on_hold, archived) has
        // no derived health: the lifecycle status carries the meaning.
        if ($facts->status === 'completed') {
            return self::result(self::COMPLETE, []);
        }
        if ($facts->status !== 'active') {
            return null;
        }

        // 3. Not started beats any overdue signal.
        if ($facts->startsInFuture) {
            return self::result(self::NOT_STARTED, [['code' => 'starts_in_future', 'date' => $facts->startDate]]);
        }

        // 4. Nothing tracked: a neutral non-health state.
        if ($facts->taskCount === 0 && $facts->milestoneCount === 0) {
            return self::result(self::INSUFFICIENT_DATA, [['code' => 'no_tracked_work']]);
        }

        // Every fired signal, in the fixed order; bounded (at most three items).
        $reasons = [];
        $targetPassed = $facts->targetPassed && $facts->openTaskCount > 0;
        if ($targetPassed) {
            $reasons[] = ['code' => 'target_passed', 'openTaskCount' => $facts->openTaskCount];
        }
        if ($facts->overdueMilestoneCount > 0) {
            $reasons[] = [
                'code' => 'milestones_overdue',
                'count' => $facts->overdueMilestoneCount,
                'earliest' => $facts->earliestOverdueMilestone,
            ];
        }
        if ($facts->overdueTaskCount > 0) {
            $reasons[] = ['code' => 'tasks_overdue', 'count' => $facts->overdueTaskCount];
        }

        // 5-7.
        $state = match (true) {
            $targetPassed || $facts->overdueMilestoneCount > 0 => self::OFF_TRACK,
            $facts->overdueTaskCount > 0 => self::AT_RISK,
            default => self::ON_TRACK,
        };

        return self::result($state, $reasons);
    }

    /** @param  array<int, array<string, mixed>>  $reasons */
    private static function result(string $state, array $reasons): array
    {
        return ['state' => $state, 'label' => self::LABELS[$state], 'reasons' => $reasons];
    }
}
