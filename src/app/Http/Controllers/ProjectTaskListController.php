<?php

namespace App\Http\Controllers;

use App\Http\Presenters\ProjectPresenter;
use App\Http\Presenters\TaskListPresenter;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Policies\ProjectSettingsAccess;
use App\Queries\TaskAssigneeOptions;
use App\Queries\TaskListState;
use App\Queries\TaskQuery;
use App\Queries\TaskRowAbilities;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The project Tasks tab (EPIC-015 §13, WP3): the Tasks workspace list scoped to one project.
 *
 * It is the global list's pipeline with the project as step 2 (`TaskQuery::forProject`), not a
 * second task query: `ProjectPolicy::view` first (403 otherwise), then the URL state normalized
 * against options drawn from the project, then the shared rows, batched row abilities and
 * assignment candidates. Read only: Complete/Reopen, assignment and bulk stay on the `tasks.*`
 * endpoints, which redirect back here, and creating a task stays on the Board (P8).
 */
class ProjectTaskListController extends Controller
{
    public function index(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        /** @var User $user */
        $user = $request->user();
        $query = TaskQuery::forProject($user, $project);

        $options = $query->projectFilterOptions();
        $state = TaskListState::fromProjectInput($request->query(), $options);

        // Links carry the normalized state, not the raw query: a dropped value never rides along.
        $tasks = $query->paginate($state)->appends($state->query());
        $abilities = TaskRowAbilities::for($user, $tasks->getCollection());
        // Before `through`, which replaces the page's models with row arrays.
        $assigneeOptions = TaskAssigneeOptions::forPage($user, $tasks->getCollection(), $abilities);
        $tasks->through(fn (Task $task) => TaskListPresenter::projectRow($task, $abilities));
        $vocabulary = TaskListPresenter::vocabulary();

        return Inertia::render('projects/tasks/index', [
            'project' => ['id' => $project->id, 'name' => $project->name, 'status' => $project->status],
            'tasks' => $tasks,
            'filters' => $state->projectFilters(),
            'filterOptions' => [
                'completion' => $vocabulary['completion'],
                'priorities' => $vocabulary['priorities'],
                'due' => $vocabulary['due'],
                'sorts' => [...$vocabulary['sorts'], ['value' => 'board', 'label' => 'Board order']],
                ...$options,
            ],
            'sort' => $state->sort(),
            // Tells "this project has no tasks yet" from "nothing matches": asked only when the
            // page is empty, of the unfiltered project set.
            'projectHasTasks' => $tasks->total() > 0 || $query->inView()->exists(),
            'assigneeOptions' => $assigneeOptions,
            'tabs' => ProjectPresenter::workspaceTabs($user),
            // The Settings link: what projects.edit actually admits (A9), through the shared
            // resolver, exactly as the Overview and the Board. Absent, not false, otherwise.
            'abilities' => ProjectSettingsAccess::allows($user, $project) ? ['openSettings' => true] : [],
        ]);
    }
}
