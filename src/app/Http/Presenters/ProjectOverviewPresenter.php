<?php

namespace App\Http\Presenters;

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\User;
use App\Policies\ProjectSettingsAccess;
use App\Queries\ProjectHealth;
use App\Services\TimeEntryService;
use Illuminate\Support\Collection;

/**
 * The project Overview DTO (EPIC-015 §12.3). Backend only in WP1: no page renders it yet.
 *
 * The caller must already have authorized `ProjectPolicy::view`. Every gated field is decided from
 * the viewer's capabilities BEFORE the array is built (INV-P8), so a forbidden value is never
 * serialized, and an unauthorized field is ABSENT AS A KEY (never null, never an empty list):
 *
 *   key                      present only when
 *   budget                   effective Settings/Edit access (present, possibly null, when allowed)
 *   members                  effective Settings/Edit access (names and project roles, no email)
 *   abilities.openSettings   effective Settings/Edit access
 *   time                     `time.view_all` (scope all) or `time.log` (scope own)
 *
 * Effective Settings/Edit access is `ProjectSettingsAccess` (INV-P16), never restated here.
 * Health is `ProjectHealth`, milestone state is `ProjectMilestone`, project time is the PR-A
 * canonical attribution through `TimeEntryService::totalMinutes` (the report's own total), so
 * this class restates none of those rules. Milestone `completedBy {id, name}` is accepted
 * provenance (§9.4) and is the only member name sent to a viewer without Settings access.
 *
 * Constant query count whatever the number of tasks, milestones, members and time entries.
 */
final class ProjectOverviewPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function overview(Project $project, User $viewer): array
    {
        $settings = ProjectSettingsAccess::allows($viewer, $project);
        $time = self::timeScope($viewer);

        // Aggregates for progress and health, in one query.
        $stats = ProjectHealth::withFacts(Project::query())->whereKey($project->id)->firstOrFail();

        $milestones = $project->milestones()
            ->orderBy('id')
            ->withTaskCounts()
            ->with('completer:id,name')
            ->get();

        $dto = [
            'project' => [
                'id' => $stats->id,
                'name' => $stats->name,
                'description' => $stats->description,
                'status' => $stats->status,
                'startDate' => $stats->start_date?->toDateString(),
                'targetDate' => $stats->target_date?->toDateString(),
            ],
            'health' => ProjectHealth::forOverview($stats),
            'tasks' => self::tasks($stats),
            'milestones' => self::milestones($stats, $milestones),
            'abilities' => $settings ? ['openSettings' => true] : [],
        ];

        if ($settings) {
            // Metadata only (Q3): the stored decimal string, no derived figure.
            $dto['budget'] = $stats->budget;
            $dto['members'] = ProjectPresenter::members($stats);
        }

        if ($time !== null) {
            $dto['time'] = [
                'scope' => $time,
                'totalMinutes' => app(TimeEntryService::class)->totalMinutes([
                    'project_id' => $stats->id,
                    'user_id' => $time === 'own' ? $viewer->id : null,
                ]),
            ];
        }

        return $dto;
    }

    /** `all` with time.view_all, `own` with time.log alone, null (no `time` key) otherwise. */
    private static function timeScope(User $viewer): ?string
    {
        if ($viewer->can('time.view_all')) {
            return 'all';
        }

        return $viewer->can('time.log') ? 'own' : null;
    }

    /**
     * Valid-kind tasks only, board column authoritative for done (the `withTaskStats` aggregates).
     *
     * @return array{total: int, done: int, open: int, overdue: int, completion: int}
     */
    private static function tasks(Project $stats): array
    {
        $total = (int) $stats->tasks_count;
        $done = (int) $stats->done_tasks_count;

        return [
            'total' => $total,
            'done' => $done,
            'open' => $total - $done,
            'overdue' => (int) $stats->overdue_tasks_count,
            'completion' => $stats->completionFromCounts(),
        ];
    }

    /**
     * Counts from the aggregates, plus every milestone ordered by (`due_date`, `id`) for the WP2
     * StagePath mapping (§14.2): `currentId` is the first incomplete milestone; `nextId` the first
     * incomplete one that is not overdue (the next upcoming). Completion is `completed_at`, never
     * task progress.
     *
     * @param  Collection<int, ProjectMilestone>  $milestones
     * @return array<string, mixed>
     */
    private static function milestones(Project $stats, $milestones): array
    {
        $current = $milestones->first(fn (ProjectMilestone $milestone) => ! $milestone->isCompleted());
        $next = $milestones->first(fn (ProjectMilestone $milestone) => ! $milestone->isCompleted() && ! $milestone->isOverdue());

        return [
            'total' => (int) $stats->milestones_count,
            'completed' => (int) $stats->completed_milestones_count,
            'overdue' => (int) $stats->overdue_milestones_count,
            'currentId' => $current?->id,
            'nextId' => $next?->id,
            'items' => $milestones->map(fn (ProjectMilestone $milestone) => ProjectMilestonePresenter::item($milestone))->values()->all(),
        ];
    }
}
