<?php

use App\Http\Presenters\TaskListPresenter;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Queries\TaskListState;
use App\Queries\TaskQuery;
use App\Queries\TaskRowAbilities;
use App\Services\ProjectService;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-014 WP5 remediation: the Tasks list is one logical read, so `TaskQuery::paginate` runs its
 * count, its task rows and the eager loads TaskListPresenter reads under one REPEATABLE-READ
 * snapshot. Hosted CI caught the race this closes: a project deleted (cascading its tasks) between
 * the row query and the `project` eager load left a board row whose `project` was null, and the
 * presenter's `$task->project->name` turned `/tasks` into a 500.
 *
 * The race is injected deterministically, not hoped for: a second connection deletes the project
 * and commits from a listener on the first connection's task-row SELECT, i.e. after the rows are
 * read and before any eager load runs. The second connection must see committed rows, so, as in
 * TaskCompletionConcurrencyTest, the RefreshDatabase transaction is ended and everything created
 * here is deleted in a finally block.
 */

/** A second connection to the same testing database, as a concurrent request would hold. */
function raceConnection(): Connection
{
    config(['database.connections.task_query_race' => config('database.connections.'.config('database.default'))]);

    return DB::connection('task_query_race');
}

/**
 * Delete $project through $race, committed, the moment the default connection finishes the list's
 * task-row SELECT. Collects, by reference, each statement the default connection ran after that,
 * with the transaction level it ran at.
 */
function deleteProjectAfterTaskRows(Connection $race, Project $project, array &$after): void
{
    $injected = false;
    DB::listen(function (QueryExecuted $query) use ($race, $project, &$injected, &$after) {
        if ($query->connectionName !== config('database.default')) {
            return;
        }
        if ($injected) {
            $after[] = [$query->sql, $query->connection->transactionLevel()];

            return;
        }
        if (str_starts_with($query->sql, 'select `tasks`.* from `tasks`')) {
            $injected = true;
            $race->table('projects')->where('id', $project->id)->delete();   // autocommit
        }
    });
}

/** The transaction level the `project` eager load ran at, after the injected delete. */
function projectEagerLoad(array $after): ?int
{
    foreach ($after as [$sql, $level]) {
        if (str_starts_with($sql, 'select `id`, `name` from `projects`')) {
            return $level;
        }
    }

    return null;
}

/** One committed board task in a fresh project; the creator manages it, so may view the row. */
function raceWorld(): array
{
    DB::commit(); // end the RefreshDatabase transaction so the second connection sees our rows

    $creator = User::factory()->create();
    $project = app(ProjectService::class)->create($creator, ['name' => 'Read race '.uniqid()]);
    // Assigned, so every one of the presenter's eager loads (assignee, project, column) runs.
    $task = makeTask($project->columns[1], ['title' => 'Raced board task', 'assignee_id' => $creator->id]);

    return [$creator, $project, $task];
}

function cleanUpRaceWorld(User $creator, Project $project): void
{
    Project::whereKey($project->id)->delete();   // cascades columns, members and tasks
    User::whereKey($creator->id)->delete();
    DB::purge('task_query_race');
}

it('reproduces the race without a snapshot: the row is read, its project is gone by the eager load', function () {
    [$creator, $project, $task] = raceWorld();
    $race = raceConnection();
    $after = [];

    try {
        deleteProjectAfterTaskRows($race, $project, $after);

        // The pre-remediation `paginate`: the same query and eager loads, statement by statement
        // in autocommit, so each one reads whatever is committed when it runs.
        $page = (new TaskQuery($creator, TaskQuery::VIEW_ALL))
            ->results(new TaskListState(view: TaskQuery::VIEW_ALL, completion: 'any'))
            ->with(['assignee:id,name', 'project:id,name', 'column:id,project_id,name,is_done_column'])
            ->paginate(TaskQuery::PER_PAGE, ['tasks.*'], 'page', 1);
        $eager = $after;   // the statements of the read itself, after the row query

        $row = $page->getCollection()->sole();
        $abilities = TaskRowAbilities::for($creator, $page->getCollection());

        expect($race->table('projects')->where('id', $project->id)->exists())->toBeFalse()
            ->and(projectEagerLoad($eager))->toBe(0)
            ->and($row->id)->toBe($task->id)
            ->and($row->project_id)->toBe($project->id)
            ->and($row->project)->toBeNull()
            ->and($page->total())->toBe(1)
            ->and(fn () => TaskListPresenter::row($row, $abilities))->toThrow(ErrorException::class, 'name');
    } finally {
        cleanUpRaceWorld($creator, $project);
    }
});

it('reads the page, its count and its eager loads from one snapshot, so a concurrent delete cannot split a row from its project', function () {
    [$creator, $project, $task] = raceWorld();
    $race = raceConnection();
    $after = [];

    try {
        deleteProjectAfterTaskRows($race, $project, $after);

        $page = (new TaskQuery($creator, TaskQuery::VIEW_ALL))
            ->paginate(new TaskListState(view: TaskQuery::VIEW_ALL, completion: 'any'), 1);
        $eager = $after;   // the statements of the read itself, after the row query

        $row = $page->getCollection()->sole();

        // The delete really landed between the row query and the eager loads, and is committed.
        expect($race->table('projects')->where('id', $project->id)->exists())->toBeFalse()
            ->and(Project::whereKey($project->id)->exists())->toBeFalse()
            ->and(Task::whereKey($task->id)->exists())->toBeFalse()
            ->and(projectEagerLoad($eager))->toBe(1)
            // Every eager load ran inside the list's transaction, so on its snapshot.
            ->and(array_map(fn (array $statement) => [strtok(substr($statement[0], strpos($statement[0], ' from ') + 6), ' '), $statement[1]], $eager))
            ->toBe([['`users`', 1], ['`projects`', 1], ['`project_columns`', 1]]);

        // Yet the page is the snapshot's: the row, its count and its project agree.
        expect($row->id)->toBe($task->id)
            ->and($row->project)->not->toBeNull()
            ->and($row->project->id)->toBe($project->id)
            ->and($row->project->name)->toBe($project->name)
            ->and($row->column)->not->toBeNull()
            ->and($page->total())->toBe(1)
            ->and(DB::transactionLevel())->toBe(0);

        $presented = TaskListPresenter::row($row, TaskRowAbilities::for($creator, $page->getCollection()));

        expect($presented['kind'])->toBe(Task::KIND_BOARD)
            ->and($presented['context']['kind'])->toBe('project')
            ->and($presented['context']['label'])->toBe($project->name);
    } finally {
        cleanUpRaceWorld($creator, $project);
    }
});
