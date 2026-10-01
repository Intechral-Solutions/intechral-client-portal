<?php

namespace App\Http\Controllers;

use App\Exceptions\DoneColumnConfigurationException;
use App\Exceptions\UnsupportedTaskOperationException;
use App\Http\Presenters\StandaloneTaskPresenter;
use App\Http\Presenters\TaskListPresenter;
use App\Http\Presenters\TaskTimeSummaryPresenter;
use App\Models\Task;
use App\Models\User;
use App\Queries\TaskAssigneeOptions;
use App\Queries\TaskListState;
use App\Queries\TaskQuery;
use App\Queries\TaskRowAbilities;
use App\Rules\AccessibleTimeContext;
use App\Rules\ProjectTaskAssignee;
use App\Rules\StandaloneTaskAssignee;
use App\Services\TaskService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The Tasks workspace (EPIC-014 §13). Every mutation is thin: authorize through TaskPolicy,
 * validate, call TaskService, redirect with a flash (the Inertia contract `move` already uses, so
 * a partial reload reconciles). Unauthorized is 403; a route that serves one kind answers another
 * kind with 404, and only after authorization, so nobody unauthorized learns a task's kind.
 */
class TaskController extends Controller
{
    /** One page of the list (§15.2): a bulk request never acts on more distinct tasks. */
    private const BULK_LIMIT = 30;

    public function __construct(private TaskService $service) {}

    /**
     * My Tasks / All Tasks (§9). The server resolves the view (an unauthorized or unknown `view`
     * is My Tasks, never a 403), normalizes the URL state against options drawn from the
     * authorized set, and TaskQuery builds the page from the maximum authorized set outward.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Task::class);

        /** @var User $user */
        $user = $request->user();
        $view = TaskQuery::resolveView($user, $request->query('view'));
        $query = new TaskQuery($user, $view);

        $options = $query->filterOptions($request->query('project'));
        $state = TaskListState::fromInput($request->query(), $view, $options);

        // Links carry the normalized state, not the raw query: a malformed value never rides along.
        $tasks = $query->paginate($state)->appends($state->query() + ($view === TaskQuery::VIEW_ALL ? ['view' => $view] : []));
        $abilities = TaskRowAbilities::for($user, $tasks->getCollection());
        // Before `through`, which replaces the page's models with row arrays.
        $assigneeOptions = TaskAssigneeOptions::forPage($user, $tasks->getCollection(), $abilities);
        $tasks->through(fn (Task $task) => TaskListPresenter::row($task, $abilities));

        return Inertia::render('tasks/index', [
            'tasks' => $tasks,
            'view' => $view,
            'filters' => $state->filters(),
            'filterOptions' => [
                'completion' => self::labelled(['open' => 'Open', 'done' => 'Done', 'any' => 'Any']),
                'priorities' => self::labelled(array_combine(Task::PRIORITIES, array_map('ucfirst', Task::PRIORITIES))),
                'due' => self::labelled(['overdue' => 'Overdue', 'today' => 'Due today', 'next7' => 'Next 7 days', 'none' => 'No due date']),
                'kinds' => self::labelled(['project' => 'Project', 'standalone' => 'Standalone']),
                'sorts' => self::labelled(['due' => 'Due date', 'priority' => 'Priority', 'title' => 'Title', 'updated' => 'Last updated', 'created' => 'Created']),
                ...$options,
            ],
            'sort' => $state->sort(),
            'canViewAll' => $user->can('viewAll', Task::class),
            // The server is the source of truth for task vocabulary (§15); the standalone
            // create form receives labelled options rather than hard-coding labels in React.
            'createOptions' => self::formOptions(),
            // R6 (WP5): who a row may be assigned to, from the rows' own abilities and one query
            // for the whole page. Self for a standalone row; current members for a managed board
            // project; nothing for a project whose rows the actor may not assign.
            'assigneeOptions' => $assigneeOptions,
        ]);
    }

    /**
     * The standalone task detail (§10, WP5). Authorization comes first, as on every other `tasks.*`
     * route (A2): `TaskPolicy::view` answers 403, so an unauthorized actor is given no kind oracle by
     * comparing this route with the others. Only then does the kind matter: a board task has one
     * canonical page (P6), so it redirects there; a standalone task renders here; a ticket-kind task is
     * not surfaced by EPIC-014 (Q6) and is 404 for the ticket's authorized viewer. A malformed
     * project-and-ticket row (INV-13) is denied by the policy, never treated as a board task.
     */
    public function show(Request $request, Task $task): Response|RedirectResponse
    {
        $this->authorize('view', $task);
        abort_if($task->ticket_id !== null, 404);

        if ($task->kind() === Task::KIND_BOARD) {
            return redirect()->route('projects.tasks.show', [$task->project_id, $task->id]);
        }

        /** @var User $user */
        $user = $request->user();
        $task->load('assignee:id,name');

        $abilities = [
            'update' => $user->can('update', $task),
            'complete' => $user->can('complete', $task),
            'reopen' => $user->can('reopen', $task),
            'delete' => $user->can('delete', $task),
            'assign' => $user->can('assign', $task),
            // Starting a timer needs the personal-time permission AND current eligibility for this
            // task (AccessibleTimeContext): the creator of an unassigned task is not eligible until
            // they take it (P5). The panel offers Start only when the server says so.
            'logTime' => $user->can('time.log') && AccessibleTimeContext::allows($user, 'task', $task->id),
        ];

        return Inertia::render('tasks/show', [
            'task' => StandaloneTaskPresenter::detail($task),
            'abilities' => $abilities,
            // Priorities and statuses are server-named (INV-19). The assignee set is the actor and
            // nobody (§7.3, Q4): no other user is ever named to populate a control.
            'options' => $abilities['update'] ? [
                ...self::formOptions(),
                'assignees' => ['self' => ['id' => $user->id, 'name' => $user->name]],
            ] : null,
            'timeSummary' => TaskTimeSummaryPresenter::summary($task, $user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            // The form only offers "Me" or "Unassigned"; the server enforces exactly that (A7;
            // EPIC-014 §7.3): no route hands a standalone task to anyone but the actor.
            'assignee_id' => ['nullable', Rule::in([$request->user()->id])],
            'priority' => 'required|in:low,medium,high,critical',
            'due_date' => 'nullable|date',
            'status' => 'required|in:todo,in_progress,done',
        ]);

        $this->service->createStandalone($request->user(), $data);

        return back()->with('success', 'Task created.');
    }

    /** Standalone edit (§10). A board task is edited on projects.tasks.update, so it is 404 here. */
    public function update(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);
        abort_unless($task->kind() === Task::KIND_STANDALONE, 404);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'required|in:low,medium,high,critical',
            'due_date' => 'nullable|date',
            'status' => 'required|in:todo,in_progress,done',
            // Optional here: absent leaves the assignee alone; null releases the task (§7.3).
            'assignee_id' => ['sometimes', 'nullable', 'integer', new StandaloneTaskAssignee($request->user(), $task)],
        ]);

        if (array_key_exists('assignee_id', $data) && $data['assignee_id'] !== $task->assignee_id) {
            $this->authorize('assign', $task);
        }

        $this->service->updateStandalone($task, $data, $request->user());

        return back()->with('success', 'Task updated.');
    }

    /** Standalone delete (§10, §12.1). A board task is deleted on projects.tasks.destroy. */
    public function destroy(Task $task): RedirectResponse
    {
        $this->authorize('delete', $task);
        abort_unless($task->kind() === Task::KIND_STANDALONE, 404);

        $this->service->delete($task);

        // Never back(): the previous page may be this task's own, which no longer exists.
        return redirect()->route('tasks.index')->with('success', 'Task deleted.');
    }

    public function complete(Task $task): RedirectResponse
    {
        $this->authorize('complete', $task);
        $this->withConfigurationErrorsOn('complete', fn () => $this->service->complete($task));

        return back()->with('success', 'Task completed.');
    }

    public function reopen(Task $task): RedirectResponse
    {
        $this->authorize('reopen', $task);
        $this->withConfigurationErrorsOn('reopen', fn () => $this->service->reopen($task));

        return back()->with('success', 'Task reopened.');
    }

    /**
     * The narrow assign endpoint (§7.3): board targets are current project members or the
     * unchanged departed assignee (ProjectTaskAssignee, the rule projects.tasks.update runs);
     * standalone targets are the actor, the unchanged assignee, or nobody.
     */
    public function assignee(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('assign', $task);

        $rule = $task->kind() === Task::KIND_BOARD
            ? new ProjectTaskAssignee($task->project, $task)
            : new StandaloneTaskAssignee($request->user(), $task);

        $data = $request->validate(['assignee_id' => ['present', 'nullable', 'integer', $rule]]);

        $this->service->assign($task, $data['assignee_id'] === null ? null : (int) $data['assignee_id'], $request->user());

        return back()->with('success', 'Assignee updated.');
    }

    /**
     * Bulk Complete/Reopen (§15.2). Each distinct id is authorized and executed on its own, in
     * its own transaction(s), so INV-4's lock order is never widened and one refusal never undoes
     * another task's success. Every id lands in exactly one bucket of the `bulk` flash. An id the
     * actor may not act on, one that does not exist, or a ticket-kind/malformed row is
     * `notPermitted`, so the endpoint reveals nothing about ids the actor cannot see.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => 'required|in:complete,reopen',
            'ids' => 'required|array|max:100',
            'ids.*' => 'integer',
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        if (count($ids) > self::BULK_LIMIT) {
            throw ValidationException::withMessages(['ids' => 'Select at most '.self::BULK_LIMIT.' tasks at a time.']);
        }

        $action = $data['action'];
        $result = ['action' => $action, 'succeeded' => [], 'notPermitted' => [], 'configurationError' => [], 'failed' => []];

        foreach ($ids as $id) {
            $task = Task::find($id);

            if ($task === null || Gate::denies($action, $task)) {
                $result['notPermitted'][] = $id;

                continue;
            }

            try {
                $this->service->{$action}($task);
                $result['succeeded'][] = $id;
            } catch (DoneColumnConfigurationException) {
                $result['configurationError'][] = $id;
            } catch (UnsupportedTaskOperationException|ModelNotFoundException) {
                $result['notPermitted'][] = $id;
            } catch (ConflictHttpException) {
                $result['failed'][] = $id;
            } catch (QueryException $e) {
                // A database failure that outlived the operation's own retries (a deadlock or lock
                // wait): this id failed, the ones already committed stand, and the summary survives.
                // Anything else is a programmer error and is deliberately not caught.
                report($e);
                $result['failed'][] = $id;
            }
        }

        $verb = $action === 'complete' ? 'completed' : 'reopened';
        $done = count($result['succeeded']);
        $refused = count($ids) - $done;
        $response = back()->with('bulk', $result);

        if ($done > 0) {
            $response->with('success', $done.' '.str('task')->plural($done)." {$verb}.");
        }
        if ($refused > 0) {
            $response->with('error', $refused.' '.str('task')->plural($refused).' could not be '.$verb.'.');
        }

        return $response;
    }

    /**
     * The standalone form's labelled vocabulary (INV-19): the create dialog and the detail page's
     * edit form share it, so the two forms cannot name a priority or status differently.
     *
     * @return array{priorities: list<array{value: string, label: string}>, statuses: list<array{value: string, label: string}>}
     */
    private static function formOptions(): array
    {
        return [
            'priorities' => collect(Task::PRIORITIES)
                ->map(fn (string $value) => ['value' => $value, 'label' => ucfirst($value)])
                ->values()
                ->all(),
            'statuses' => collect(Task::STATUSES)
                ->map(fn (string $value) => ['value' => $value, 'label' => match ($value) {
                    'in_progress' => 'In Progress',
                    'done' => 'Done',
                    default => 'To Do',
                }])
                ->values()
                ->all(),
        ];
    }

    /**
     * Server-named vocabulary (INV-19), in the order the filter controls offer it.
     *
     * @param  array<string, string>  $labels  value => label
     * @return array<int, array{value: string, label: string}>
     */
    private static function labelled(array $labels): array
    {
        return array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys($labels), $labels);
    }

    /**
     * Map a Done-column misconfiguration to a validation error on the operation's key (§8),
     * never a 500 and never a guess.
     */
    private function withConfigurationErrorsOn(string $operation, callable $call): void
    {
        try {
            $call();
        } catch (DoneColumnConfigurationException $e) {
            $verb = $operation === 'complete' ? 'completed' : 'reopened';

            throw ValidationException::withMessages([$operation => $e->noOpenColumn
                ? "This project's board has no open column, so tasks cannot be {$verb} from here. A project administrator needs to correct the board."
                : "This project's board has no single Done column, so tasks cannot be {$verb} from here. A project administrator needs to correct the board."]);
        }
    }
}
