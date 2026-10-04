<?php

namespace App\Queries;

use App\Models\Project;
use App\Models\ProjectMilestone;

/**
 * The server facts project health is derived from (EPIC-015 §8.1). A plain value: building it
 * reads only aggregates already on the project, so it never queries.
 *
 * Task counts are VALID-KIND tasks only (`Task::scopeOfValidKind` through
 * `Project::scopeWithTaskStats`): the malformed project+ticket row has no valid product kind and is
 * no health input (§8.4, owner ruling).
 */
final readonly class ProjectHealthFacts
{
    /**
     * @param  array{id: int, name: string, dueDate: string}|null  $earliestOverdueMilestone  Overview only; null on the index
     */
    public function __construct(
        public string $status,
        public ?string $startDate,
        public bool $startsInFuture,
        public bool $targetPassed,
        public int $taskCount,
        public int $openTaskCount,
        public int $overdueTaskCount,
        public int $milestoneCount,
        public int $overdueMilestoneCount,
        public ?array $earliestOverdueMilestone = null,
    ) {}

    /**
     * From a project loaded through `ProjectHealth::withFacts()`. Dates compare against today in the
     * application timezone; a target date of today is not past (the overdue rule).
     */
    public static function fromAggregates(Project $project, ?ProjectMilestone $earliestOverdue = null): self
    {
        $taskCount = (int) $project->tasks_count;

        return new self(
            status: $project->status,
            startDate: $project->start_date?->toDateString(),
            startsInFuture: $project->start_date !== null && $project->start_date->gt(today()),
            targetPassed: $project->target_date !== null && $project->target_date->lt(today()),
            taskCount: $taskCount,
            openTaskCount: $taskCount - (int) $project->done_tasks_count,
            overdueTaskCount: (int) $project->overdue_tasks_count,
            milestoneCount: (int) $project->milestones_count,
            overdueMilestoneCount: (int) $project->overdue_milestones_count,
            earliestOverdueMilestone: $earliestOverdue === null ? null : [
                'id' => $earliestOverdue->id,
                'name' => $earliestOverdue->name,
                'dueDate' => $earliestOverdue->due_date->toDateString(),
            ],
        );
    }
}
