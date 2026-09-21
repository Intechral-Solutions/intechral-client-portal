<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Services\ProjectService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProjectTaskController extends Controller
{
    /** A task can carry at most this many checklist items (D5). */
    private const CHECKLIST_LIMIT = 100;

    public function __construct(private ProjectService $service) {}

    public function show(Project $project, Task $task): View
    {
        $this->authorize('view', $project);
        abort_unless($task->project_id === $project->id, 404);

        $task->load(['assignee', 'milestone', 'checklistItems', 'comments.user', 'column']);

        return view('projects.tasks.show', compact('project', 'task'));
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

        return back()->with('success', 'Task deleted.');
    }

    public function move(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('manage', $project);
        abort_unless($task->project_id === $project->id, 404);

        $data = $request->validate([
            'column_id' => ['required', Rule::exists('project_columns', 'id')->where('project_id', $project->id)],
            'position' => 'required|integer|min:0',
        ]);

        $this->service->moveTask($task, (int) $data['column_id'], (int) $data['position']);

        return response()->json(['ok' => true]);
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

    public function toggleChecklistItem(Request $request, Project $project, Task $task, int $item): JsonResponse
    {
        $this->authorize('view', $project);
        abort_unless($task->project_id === $project->id, 404);

        $data = $request->validate(['completed' => 'sometimes|boolean']);

        $checklistItem = $task->checklistItems()->findOrFail($item);

        // An explicit value is idempotent (a replayed request cannot flip it back); omitting it
        // keeps the original toggle behaviour for any older caller.
        $checklistItem->update(['completed' => $data['completed'] ?? ! $checklistItem->completed]);

        return response()->json(['completed' => $checklistItem->completed]);
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
            'assignee_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($project, $task) {
                    // Saving a task unchanged is not a new assignment: someone who has since
                    // left the project keeps the task rather than being silently unassigned (I8).
                    if ($task !== null && (int) $value === $task->assignee_id) {
                        return;
                    }

                    if (! $project->members()->where('users.id', $value)->exists()) {
                        $fail('The selected assignee is invalid.');
                    }
                },
            ],
            'milestone_id' => ['nullable', Rule::exists('project_milestones', 'id')->where('project_id', $project->id)],
            'priority' => 'required|in:low,medium,high,critical',
            'due_date' => 'nullable|date',
        ];
    }
}
