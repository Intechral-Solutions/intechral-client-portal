<?php

namespace App\Http\Controllers;

use App\Http\Presenters\TaskListPresenter;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
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
            // The form only offers "Me" or "Unassigned"; the server enforces exactly that (A7,
            // D3): no route exists to expand a standalone task's assignee beyond the actor.
            'assignee_id' => ['nullable', Rule::in([$request->user()->id])],
            'priority' => 'required|in:low,medium,high,critical',
            'due_date' => 'nullable|date',
            'status' => 'required|in:todo,in_progress,done',
        ]);

        Task::create([
            ...$data,
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', 'Task created.');
    }
}
