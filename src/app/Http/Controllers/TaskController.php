<?php

namespace App\Http\Controllers;

use App\Exceptions\DoneColumnConfigurationException;
use App\Exceptions\UnsupportedTaskOperationException;
use App\Http\Presenters\TaskListPresenter;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
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

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = auth()->user();
        // A closed two-value enum, clamped rather than rejected: this is a navigational tab
        // link, not a form submission, so an unrecognized value falls back to "mine" (the
        // narrower, always-safe view) instead of a validation redirect a stray/edited URL would
        // otherwise bounce through.
        $view = $request->query('view') === 'org' ? 'org' : 'mine';
        $companyIds = $user->can('tasks.view_org') ? $user->orgCompanyIds() : [];
        // The org tab only ever widens the query with company-linked rows (below); with no
        // company to link through it can show nothing beyond "mine", so it stays hidden rather
        // than rendering an org tab that behaves exactly like the one already shown.
        $canViewOrg = ! empty($companyIds);

        $query = Task::with([
            'assignee:id,name',
            'project:id,name',
            // `user_id` is loaded (never rendered) because TicketPolicy::view needs it to decide
            // openableTickets below; trimming it would silently deny every ticket owner's link.
            'ticket:id,ticket_number,user_id',
            'column:id,project_id,name,is_done_column',
        ])
            ->where(function ($q) use ($user, $companyIds, $view) {
                // "mine" tab — always includes tasks assigned directly to the user
                $q->where('assignee_id', $user->id);

                // "org" tab / view_org — also show tasks in the org's projects/tickets. A
                // company link is metadata (D2), so a project only counts when the viewer can
                // actually open it.
                if ($view === 'org' && ! empty($companyIds)) {
                    $q->orWhereHas('project', fn ($p) => $p->visibleTo($user)
                        ->whereHas('companies', fn ($c) => $c->whereIn('crm_companies.id', $companyIds)))
                        ->orWhereHas('ticket', fn ($t) => $t->whereIn('company_id', $companyIds));
                }
            })
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderByDesc('created_at');

        $tasks = $query->paginate(30)->withQueryString();

        // Which destinations the viewer may open, so no row renders a link that would 403.
        // Projects are answered with one query for the whole page (the same rule as
        // ProjectPolicy::view, D2); tickets ask TicketPolicy, which needs no query per row.
        $projectIds = $tasks->getCollection()->pluck('project_id')->filter()->unique()->all();
        $openableProjects = $projectIds === []
            ? []
            : array_flip(Project::visibleTo($user)->whereIn('id', $projectIds)->pluck('id')->all());
        $openableTickets = $tasks->getCollection()
            ->pluck('ticket')->filter()
            ->filter(fn ($ticket) => Gate::forUser($user)->allows('view', $ticket))
            ->pluck('id')->flip()->all();

        $tasks->through(fn (Task $task) => TaskListPresenter::row($task, $openableProjects, $openableTickets));

        return Inertia::render('tasks/index', [
            'tasks' => $tasks,
            'view' => $view,
            'canViewOrg' => $canViewOrg,
            // The server is the source of truth for task vocabulary (§15); the standalone
            // create form receives labelled options rather than hard-coding labels in React.
            'createOptions' => [
                'priorities' => collect(Task::PRIORITIES)
                    ->map(fn (string $value) => ['value' => $value, 'label' => ucfirst($value)])
                    ->values(),
                'statuses' => collect(Task::STATUSES)
                    ->map(fn (string $value) => ['value' => $value, 'label' => match ($value) {
                        'in_progress' => 'In Progress',
                        'done' => 'Done',
                        default => 'To Do',
                    }])
                    ->values(),
            ],
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
