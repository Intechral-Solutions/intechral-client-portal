<?php

namespace App\Http\Presenters;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Policies\ProjectTimeAccess;
use App\Services\TimeEntryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The Project Time tab's time DTO (EPIC-015 §5.2 S3, WP5): one project's attributed time, in the
 * viewer's permitted scope. The caller must already have authorized `ProjectPolicy::view` and
 * resolved a non-null `ProjectTimeAccess` scope.
 *
 * It restates no rule:
 * - **Attribution** is `TimeEntry::scopeAttributedToProject` (§10, INV-P4): task time under the task's
 *   project, direct time under its own project, a malformed or non-board task under none. Never a
 *   plain `project_id` filter.
 * - **The total** is `TimeEntryService::totalMinutes`, the call the Overview makes, so the tab and the
 *   Overview cannot disagree (§10: "the Overview and the Time workspace never use different
 *   definitions").
 * - **Settled entries only** (`timer_started_at IS NULL`), as the Overview, the report and the task time
 *   panel: a running timer's minutes are not final, and the global timer already shows it. Rows and
 *   total therefore always describe the same set.
 *
 * Minimal like the task time panel it mirrors (`TaskTimeSummaryPresenter`, D6): date, duration and
 * context; the person's display name only in `all` scope (no key at all in `own`); no email, user
 * id, description, billing, invoice or lock flag, and no money. Read only: entries are edited where
 * they are owned, on `/time`.
 */
final class ProjectTimePresenter
{
    public const PER_PAGE = 25;

    /**
     * @param  'all'|'own'  $scope
     * @return array{summary: array{scope: string, totalMinutes: int}, entries: LengthAwarePaginator}
     */
    public static function page(Project $project, User $viewer, string $scope): array
    {
        $own = $scope === ProjectTimeAccess::SCOPE_OWN;

        $entries = TimeEntry::query()
            ->attributedToProject($project->id)
            ->whereNull('time_entries.timer_started_at')
            ->when($own, fn ($query) => $query->where('time_entries.user_id', $viewer->id))
            // Eager loaded, so a page of rows costs no query per entry. The user is loaded only
            // when the scope may show a name; `own` never reads another person.
            ->with(['task:id,title,project_id', ...($own ? [] : ['user:id,name'])])
            ->orderByDesc('time_entries.date')
            ->orderByDesc('time_entries.id')
            ->paginate(self::PER_PAGE)
            ->through(fn (TimeEntry $entry) => self::row($entry, $project, $own));

        return [
            'summary' => [
                'scope' => $scope,
                'totalMinutes' => app(TimeEntryService::class)->totalMinutes([
                    'project_id' => $project->id,
                    'user_id' => $own ? $viewer->id : null,
                ]),
            ],
            'entries' => $entries,
        ];
    }

    /**
     * An entry the canonical scope admitted has either a valid board task of this project or no
     * task at all (direct project time), so those are the only two contexts.
     *
     * @return array<string, mixed>
     */
    private static function row(TimeEntry $entry, Project $project, bool $own): array
    {
        return [
            'id' => $entry->id,
            'date' => $entry->date->toDateString(),
            'durationMinutes' => (int) $entry->duration_minutes,
            'context' => $entry->task_id === null
                ? ['kind' => 'project']
                : [
                    'kind' => 'task',
                    'id' => $entry->task_id,
                    'title' => $entry->task?->title ?? 'Task',
                    // Any viewer of the project may open its board tasks (TaskPolicy::view), so the
                    // link never answers 403 (INV-P15).
                    'url' => route('projects.tasks.show', [$project->id, $entry->task_id]),
                ],
            ...($own ? [] : ['userName' => $entry->user?->name ?? 'Unknown']),
        ];
    }
}
