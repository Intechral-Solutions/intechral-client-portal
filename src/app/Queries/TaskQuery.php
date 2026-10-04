<?php

namespace App\Queries;

use App\Models\CrmCompany;
use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\ProjectMilestone;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The Tasks-workspace query (EPIC-014 §9): the one place My Tasks, All Tasks, their filters,
 * search, sort and pagination are built. The composition order is the security contract (§9.1,
 * INV-16):
 *
 *   1. the actor's MAXIMUM AUTHORIZED SURFACED SET (`authorizedFor`);
 *   2. the view (My Tasks, or All Tasks, which is step 1 unchanged), AND-ed on;
 *   3. filters, 4. search: each an AND-ed `where`;
 *   5. sort, then pagination.
 *
 * Steps 1 and 2 are a single parenthesised group, so no later clause can escape it through
 * operator precedence, and nothing after step 1 ORs into anything. Authorization is SQL, never a
 * PHP pass over fetched rows.
 *
 * Project scope (EPIC-015 §13.1, Q6) is the same pipeline with a different step 2: `forProject`
 * replaces the view with `tasks.project_id = project`, inside the same parenthesised group. It has no
 * Mine/All semantics and no second query: every filter, the search, the sort and the eager loads
 * below are shared. Its caller has already authorized `ProjectPolicy::view(project)`; step 1 still
 * runs, so the project set is a subset of `TaskPolicy::view` by construction (INV-P12).
 *
 * The view is a surface capability (`TaskPolicy::viewAll`, resolved by `resolveView`), not an
 * object grant: an All Tasks query built for an actor without `tasks.view_all` still returns only
 * rows that actor may view. That keeps the capability and the row rule independently testable.
 *
 * Request-context code: the actor must be the authenticated user. Authorization above is composed
 * from `$actor`, but the organization filter and its options go through `CrmCompany`, whose
 * global `OrganizationScope` reads `Auth::user()`. Run for anyone else (a queue job, a console
 * command, an acting-as context) the company options and filter would follow the wrong tenant
 * scope. That use is not supported, and no cross-domain bridge is added for it.
 */
final class TaskQuery
{
    public const VIEW_MINE = 'mine';

    public const VIEW_ALL = 'all';

    public const VIEWS = [self::VIEW_MINE, self::VIEW_ALL];

    /**
     * The project-scoped list (EPIC-015 §13). Not one of `VIEWS`: no request resolves to it, only
     * `forProject` builds it, and `TaskListState` admits the project-only vocabulary under it alone.
     */
    public const VIEW_PROJECT = 'project';

    /** §9.1 step 5: as the list always had. */
    public const PER_PAGE = 30;

    public function __construct(
        private readonly User $actor,
        private readonly string $view,
        private readonly ?Project $project = null,
    ) {
        // The project view and a project travel together; a global view never carries one.
        if ($project === null ? ! in_array($view, self::VIEWS, true) : $view !== self::VIEW_PROJECT) {
            throw new InvalidArgumentException("Unknown Tasks view [{$view}].");
        }
    }

    /**
     * The project Tasks tab (EPIC-015 §13.1): the actor's authorized surfaced set, then this project.
     * The caller authorizes `ProjectPolicy::view` first; this adds no authorization of its own.
     */
    public static function forProject(User $actor, Project $project): self
    {
        return new self($actor, self::VIEW_PROJECT, $project);
    }

    /**
     * The view the server serves (§9.4): `all` only when asked for and allowed; anything else,
     * including a missing value, the retired `org` and `all` without the permission, is `mine`.
     */
    public static function resolveView(User $actor, mixed $requested): string
    {
        return $requested === self::VIEW_ALL && $actor->can('viewAll', Task::class)
            ? self::VIEW_ALL
            : self::VIEW_MINE;
    }

    /**
     * Step 1 (§9.1.1): the surfaced kinds (`ticket_id IS NULL`, which also excludes a malformed
     * row linked to both a project and a ticket), restricted to board tasks of projects the actor
     * may view (`Project::visibleTo`, equal to `ProjectPolicy::view`) and standalone tasks the
     * actor created or currently holds. Assignment never admits a board task on its own, and
     * `tasks.view_all` plays no part here.
     */
    public static function authorizedFor(User $actor): Builder
    {
        return Task::query()->where(fn (Builder $q) => self::authorized($q, $actor));
    }

    /** Steps 1–2: the view with no filter applied. Option lists are drawn from this set. */
    public function inView(): Builder
    {
        return Task::query()->where(function (Builder $q) {
            self::authorized($q, $this->actor);

            // Project scope (EPIC-015 §13.1): this project's rows of step 1, whoever they belong to.
            // Step 1 already excludes standalone, ticket and malformed project+ticket rows.
            if ($this->project !== null) {
                $q->where('tasks.project_id', $this->project->id);

                return;
            }

            // My Tasks (Q4): assigned to me, plus the unassigned standalone tasks I created. All
            // Tasks adds nothing: it is step 1, so All ⊇ Mine by construction.
            if ($this->view === self::VIEW_MINE) {
                $q->where(fn (Builder $mine) => $mine
                    ->where('tasks.assignee_id', $this->actor->id)
                    ->orWhere(fn (Builder $own) => $own
                        ->whereNull('tasks.project_id')
                        ->whereNull('tasks.assignee_id')
                        ->where('tasks.created_by', $this->actor->id)));
            }
        });
    }

    /** Steps 1–5 short of pagination: every filter, the search and the sort, each AND-ed. */
    public function results(TaskListState $state): Builder
    {
        $query = $this->inView();

        match ($state->completion) {
            'open' => $query->open(),
            'done' => $query->done(),
            default => null,
        };

        if ($state->priorities !== []) {
            $query->whereIn('tasks.priority', $state->priorities);
        }

        match ($state->due) {
            'overdue' => $query->overdue(),
            'today' => $query->whereDate('tasks.due_date', today()),
            // Seven calendar days in the application timezone, today included.
            'next7' => $query->whereBetween('tasks.due_date', [today()->toDateString(), today()->addDays(6)->toDateString()]),
            'none' => $query->whereNull('tasks.due_date'),
            default => null,
        };

        match ($state->kind) {
            'project' => $query->whereNotNull('tasks.project_id'),
            'standalone' => $query->whereNull('tasks.project_id'),
            default => null,
        };

        if ($state->project !== null) {
            $query->where('tasks.project_id', $state->project);
        }

        // Only meaningful inside its project; a milestone never applies on its own (§9.5). In project
        // scope the project is fixed, and the state admitted only one of its milestones.
        if ($state->milestone !== null && ($state->project !== null || $this->project !== null)) {
            $query->where('tasks.milestone_id', $state->milestone);
        }

        if ($state->assignee === 'none') {
            $query->whereNull('tasks.assignee_id');
        } elseif ($state->assignee !== null) {
            $query->where('tasks.assignee_id', $state->assignee);
        }

        // A company link narrows board tasks; a standalone task has no project, so never matches.
        if ($state->organization !== null) {
            $query->whereHas('project.companies', fn (Builder $companies) => $companies->whereKey($state->organization));
        }

        if ($state->search !== '') {
            // Title only (P7). The fragment is bound, and its LIKE metacharacters (and the
            // backslash, MariaDB's default LIKE escape) are escaped so they match literally.
            $query->where('tasks.title', 'like', '%'.addcslashes($state->search, '\\%_').'%');
        }

        return $this->sorted($query, $state);
    }

    /**
     * The page of rows the list renders, with the relations TaskListPresenter reads.
     *
     * One logical read, one snapshot: the count, the rows and the eager loads all run inside one
     * transaction, so under InnoDB's REPEATABLE READ they read the same consistent snapshot. Read
     * statement by statement instead, a project deleted (cascading its tasks) between the row query
     * and the `project` eager load left a board row with a null `project`. The paginator is fully
     * materialized before the closure returns; no lock is taken and no writer waits on it.
     */
    public function paginate(TaskListState $state, ?int $page = null): LengthAwarePaginator
    {
        if ($this->project !== null) {
            return $this->paginateProject($state, $page);
        }

        return DB::transaction(fn () => $this->results($state)
            ->with([
                'assignee:id,name',
                'project:id,name',
                'column:id,project_id,name,is_done_column',
            ])
            ->paginate(self::PER_PAGE, ['tasks.*'], 'page', $page));
    }

    /**
     * The project page (EPIC-015 §13.3, P4): the same snapshot, plus each row's milestone, which only
     * project scope loads, so the global list's eager loads and query budget are unchanged. Every row
     * belongs to the scoped project, so that relation is set from the instance already in hand rather
     * than queried again.
     */
    private function paginateProject(TaskListState $state, ?int $page): LengthAwarePaginator
    {
        $tasks = DB::transaction(fn () => $this->results($state)
            ->with([
                'assignee:id,name',
                'column:id,project_id,name,is_done_column',
                'milestone:id,name',
            ])
            ->paginate(self::PER_PAGE, ['tasks.*'], 'page', $page));

        $tasks->getCollection()->each(fn (Task $task) => $task->setRelation('project', $this->project));

        return $tasks;
    }

    /**
     * Filter options (§9.5), each drawn from an already-authorized set so no list can enumerate a
     * record the actor could not otherwise reach (INV-17). Names only. `milestones` are those of
     * the selected project, offered only while exactly one project the actor can view is selected
     * (whether or not it holds a row in this view). The options are labels only: a well-formed id
     * the actor was not offered is still applied by `results`, as a narrowing predicate.
     *
     * @return array{projects: array<int, array{id: int, name: string}>, milestones: array<int, array{id: int, name: string}>, assignees: array<int, array{id: int, name: string}>, organizations: array<int, array{id: int, name: string}>}
     */
    public function filterOptions(mixed $requestedProject = null): array
    {
        // Projects the actor can view that hold at least one row of the current view.
        $projects = Project::visibleTo($this->actor)
            ->whereIn('projects.id', $this->inView()->whereNotNull('tasks.project_id')->select('tasks.project_id'))
            ->orderBy('name')->orderBy('id')
            ->get(['projects.id', 'projects.name']);

        // The selected project must be one the actor can view; the id is parsed strictly and never
        // looked up through anything but the canonical visibility scope.
        $selectedId = TaskListState::idFrom($requestedProject);

        return [
            'projects' => self::named($projects),
            'milestones' => $selectedId === null ? [] : self::named(
                ProjectMilestone::where('project_id', $selectedId)
                    ->whereIn('project_id', Project::visibleTo($this->actor)->select('projects.id'))
                    ->orderBy('due_date')->orderBy('id')->get(['id', 'name'])
            ),
            // All Tasks only; distinct assignees of rows in the view, whatever their membership now.
            'assignees' => $this->view !== self::VIEW_ALL ? [] : self::named(
                User::whereIn('id', $this->inView()->whereNotNull('tasks.assignee_id')->select('tasks.assignee_id'))
                    ->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ),
            // Companies linked to projects the actor can view. CrmCompany's own tenant scope still
            // applies on top, so this never names a company the actor's CRM scope would hide.
            'organizations' => self::named(
                CrmCompany::whereHas('projects', fn (Builder $project) => $project->visibleTo($this->actor))
                    ->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ),
        ];
    }

    // ── Internals ──────────────────────────────────────────────────────────

    private static function authorized(Builder $q, User $actor): void
    {
        $q->whereNull('tasks.ticket_id')
            ->where(fn (Builder $kinds) => $kinds
                ->where(fn (Builder $board) => $board
                    ->whereNotNull('tasks.project_id')
                    ->whereIn('tasks.project_id', Project::visibleTo($actor)->select('projects.id')))
                ->orWhere(fn (Builder $standalone) => $standalone
                    ->whereNull('tasks.project_id')
                    ->where(fn (Builder $own) => $own
                        ->where('tasks.created_by', $actor->id)
                        ->orWhere('tasks.assignee_id', $actor->id))));
    }

    /** Allowlisted ORDER BY only, then `id` so equal keys page deterministically. */
    private function sorted(Builder $query, TaskListState $state): Builder
    {
        $dir = $state->direction;

        match ($state->sort) {
            // Undated rows last either way, then the newest first among equal dates.
            'due' => $query->orderByRaw('CASE WHEN tasks.due_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('tasks.due_date', $dir)
                ->orderByDesc('tasks.created_at'),
            // By rank, never alphabetically: critical is the highest.
            'priority' => $query->orderByRaw(
                "CASE tasks.priority WHEN 'low' THEN 1 WHEN 'medium' THEN 2 WHEN 'high' THEN 3 WHEN 'critical' THEN 4 END {$dir}"
            ),
            'title' => $query->orderBy('tasks.title', $dir),
            'updated' => $query->orderBy('tasks.updated_at', $dir),
            'created' => $query->orderBy('tasks.created_at', $dir),
            // Project scope only (EPIC-015 §13.2): the board's own order, column position then task
            // position (`ProjectBoardController`), read from the columns the board already uses.
            'board' => $query->orderBy(
                ProjectColumn::select('position')->whereColumn('project_columns.id', 'tasks.column_id'),
                $dir,
            )->orderBy('tasks.position', $dir),
        };

        return $query->orderBy('tasks.id', $state->sort === 'due' ? 'desc' : $dir);
    }

    /**
     * Project-scope filter options (EPIC-015 §13.2): the project's milestones, and the distinct
     * assignees of the project's rows in the authorized set (names only, whatever their membership
     * now, as All Tasks). Nothing is fetched by a requested id, so a forged parameter never earns a
     * label.
     *
     * @return array{milestones: array<int, array{id: int, name: string}>, assignees: array<int, array{id: int, name: string}>}
     */
    public function projectFilterOptions(): array
    {
        if ($this->project === null) {
            throw new InvalidArgumentException('Project filter options need a project scope.');
        }

        return [
            'milestones' => self::named(
                ProjectMilestone::where('project_id', $this->project->id)
                    ->orderBy('due_date')->orderBy('id')->get(['id', 'name'])
            ),
            'assignees' => self::named(
                User::whereIn('id', $this->inView()->whereNotNull('tasks.assignee_id')->select('tasks.assignee_id'))
                    ->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ),
        ];
    }

    /** @return array<int, array{id: int, name: string}> */
    private static function named(iterable $models): array
    {
        $options = [];
        foreach ($models as $model) {
            $options[] = ['id' => $model->id, 'name' => $model->name];
        }

        return $options;
    }
}
