<?php

namespace App\Http\Controllers;

use App\Http\Presenters\ProjectTaskPresenter;
use App\Http\Presenters\TaskTimeSummaryPresenter;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\User;
use App\Rules\ProjectTaskAssignee;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProjectTaskController extends Controller
{
    /** A task can carry at most this many checklist items (D5). */
    private const CHECKLIST_LIMIT = 100;

    public function __construct(private ProjectService $service) {}

    public function show(Project $project, Task $task): Response
    {
        $this->authorize('view', $project);
        abort_unless($task->project_id === $project->id, 404);

        // Everything the page renders is loaded up front: the assignee, milestone, and column
        // for the DTO, and each comment's author, so the query count does not grow with the
        // number of comments or checklist items (EPIC-011E §25).
        $task->load([
            'assignee:id,name',
            'milestone:id,name',
            'column:id,project_id,name,is_done_column',
            'checklistItems',
            'comments.user:id,name',
        ]);

        $manage = Gate::allows('manage', $project);
        $user = auth()->user();

        // Saving a task unchanged is not a new assignment (I8): a departed assignee stays on
        // the task and is shown with a "no longer a project member" indicator rather than a
        // silent unassignment. This one query (bounded to a single task) is what decides that,
        // not a re-derivation inside the presenter.
        $assigneeIsMember = $task->assignee_id === null
            || $project->members()->where('users.id', $task->assignee_id)->exists();

        $options = null;
        if ($manage) {
            $options = [
                'members' => $project->members()->orderBy('name')->get(['users.id', 'users.name'])
                    ->map(fn (User $member) => ProjectTaskPresenter::userRef($member))
                    ->values(),
                'milestones' => $project->milestones()->get(['id', 'name'])
                    ->map(fn (ProjectMilestone $milestone) => ['id' => $milestone->id, 'name' => $milestone->name])
                    ->values(),
                'priorities' => collect(Task::PRIORITIES)
                    ->map(fn (string $priority) => ['value' => $priority, 'label' => ucfirst($priority)])
                    ->values(),
            ];
        }

        return Inertia::render('projects/tasks/show', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'task' => ProjectTaskPresenter::detail($task, $assigneeIsMember),
            'checklist' => $task->checklistItems
                ->map(fn (TaskChecklistItem $item) => ProjectTaskPresenter::checklistItem($item))
                ->values(),
            'comments' => $task->comments
                ->map(fn (TaskComment $comment) => ProjectTaskPresenter::comment($comment))
                ->values(),
            'options' => $options,
            'abilities' => [
                // Structural mutation: edit fields, assign, milestone, delete, checklist
                // add/remove (D1). The policy alone, no route middleware, exactly what the
                // structural task routes below authorize.
                'manage' => $manage,
                // Comment and checklist toggle require only `view`, already enforced by the
                // authorize() call above: anyone who can open this page may use both
                // (EPIC-011E §13, §14) — unchanged from the page they replace.
                'comment' => true,
                'toggleChecklist' => true,
                'logTime' => $user->can('time.log'),
            ],
            'timeSummary' => TaskTimeSummaryPresenter::summary($task, $user),
        ]);
    }

    // ── Structural mutations: ProjectPolicy::manage (D1) ─────────────────────

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('manage', $project);

        $data = $request->validate([
            'column_id' => ['required', Rule::exists('project_columns', 'id')->where('project_id', $project->id)],
            ...$this->taskRules($project),
        ]);

        $this->service->createTask($project, $request->user(), $data);

        return back()->with('success', 'Task created.');
    }

    public function update(Request $request, Project $project, Task $task): RedirectResponse
    {
        $this->authorize('manage', $project);
        abort_unless($task->project_id === $project->id, 404);

        $data = $request->validate($this->taskRules($project, $task));

        $task->update($data);

        return back()->with('success', 'Task updated.');
    }

    public function destroy(Project $project, Task $task): RedirectResponse
    {
        $this->authorize('manage', $project);
        abort_unless($task->project_id === $project->id, 404);

        $this->service->deleteTask($task);

        // Explicitly the board, never back(): the previous URL is the task's own page (its last
        // GET load), which no longer exists once the task is deleted (EPIC-011E §11).
        return redirect()->route('projects.board', $project)->with('success', 'Task deleted.');
    }

    public function move(Request $request, Project $project, Task $task): RedirectResponse
    {
        $this->authorize('manage', $project);
        abort_unless($task->project_id === $project->id, 404);

        $data = $request->validate([
            'column_id' => ['required', Rule::exists('project_columns', 'id')->where('project_id', $project->id)],
            'position' => 'required|integer|min:0',
        ]);

        $this->service->moveTask($task, (int) $data['column_id'], (int) $data['position']);

        // Redirect-back so Inertia's partial reload (`only: ['columns', 'flash']`, EPIC-011E §8)
        // returns the authoritative board state; the optimistic overlay is replaced by it. The
        // Blade board's fire-and-forget JSON contract ({ok:true}) ends here (WP5).
        return back()->with('success', 'Task moved.');
    }

    public function storeChecklistItem(Request $request, Project $project, Task $task): RedirectResponse
    {
        $this->authorize('manage', $project);
        abort_unless($task->project_id === $project->id, 404);

        $data = $request->validate(['title' => 'required|string|max:255']);

        DB::transaction(function () use ($task, $data) {
            // The task row serialises concurrent adds so the cap and the tail position hold.
            Task::whereKey($task->id)->lockForUpdate()->first();

            if ($task->checklistItems()->count() >= self::CHECKLIST_LIMIT) {
                throw ValidationException::withMessages([
                    'title' => 'A task can have at most '.self::CHECKLIST_LIMIT.' checklist items.',
                ]);
            }

            $tail = $task->checklistItems()->max('position');

            $task->checklistItems()->create([
                'title' => $data['title'],
                'completed' => false,
                'position' => $tail === null ? 0 : $tail + 1,
            ]);
        });

        return back()->with('success', 'Checklist item added.');
    }

    public function destroyChecklistItem(Project $project, Task $task, int $item): RedirectResponse
    {
        $this->authorize('manage', $project);
        abort_unless($task->project_id === $project->id, 404);

        $task->checklistItems()->findOrFail($item)->delete();

        return back()->with('success', 'Checklist item removed.');
    }

    // ── Collaboration: ProjectPolicy::view (any member or administrator) ─────

    public function addComment(Request $request, Project $project, Task $task): RedirectResponse
    {
        $this->authorize('view', $project);
        abort_unless($task->project_id === $project->id, 404);

        $data = $request->validate(['body' => 'required|string|max:5000']);

        $task->comments()->create([
            'user_id' => auth()->id(),
            'body' => $data['body'],
        ]);

        return back()->with('success', 'Comment added.');
    }

    public function toggleChecklistItem(Request $request, Project $project, Task $task, int $item): RedirectResponse
    {
        $this->authorize('view', $project);
        abort_unless($task->project_id === $project->id, 404);

        $data = $request->validate(['completed' => 'sometimes|boolean']);

        $checklistItem = $task->checklistItems()->findOrFail($item);

        // An explicit value is idempotent (a replayed request cannot flip it back); omitting it
        // keeps the original toggle behaviour for any older caller.
        $checklistItem->update(['completed' => $data['completed'] ?? ! $checklistItem->completed]);

        // Redirect-back (WP7): the React panel's optimistic toggle reconciles against the
        // authoritative `checklist` prop via a partial reload (`only: ['checklist']`), the same
        // pattern the board's move contract already uses (EPIC-011E §8, §14). No flash message:
        // a toggle is too frequent to announce. The old JSON contract's only consumer, the
        // Blade `fetch` + `location.reload()` script, is deleted with this work package.
        return back();
    }

    // ── Private ──────────────────────────────────────────────

    /**
     * Fields shared by create and update. Every referenced id must belong to the route's own
     * project (A1 to A3): milestones are per project, assignees are project members.
     */
    private function taskRules(Project $project, ?Task $task = null): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            // A current member, or the unchanged departed assignee (I8): ProjectTaskAssignee.
            'assignee_id' => ['nullable', 'integer', new ProjectTaskAssignee($project, $task)],
            'milestone_id' => ['nullable', Rule::exists('project_milestones', 'id')->where('project_id', $project->id)],
            'priority' => 'required|in:low,medium,high,critical',
            'due_date' => 'nullable|date',
        ];
    }
}
