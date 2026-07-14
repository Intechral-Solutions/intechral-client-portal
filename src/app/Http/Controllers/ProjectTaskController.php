<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectTaskController extends Controller
{
    public function __construct(private ProjectService $service) {}

    public function show(Project $project, Task $task): View
    {
        $this->authorize('view', $project);
        abort_unless($task->project_id === $project->id, 404);

        $task->load(['assignee', 'milestone', 'checklistItems', 'comments.user', 'column']);

        return view('projects.tasks.show', compact('project', 'task'));
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('view', $project);

        $data = $request->validate([
            'column_id' => 'required|exists:project_columns,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'assignee_id' => 'nullable|exists:users,id',
            'milestone_id' => 'nullable|exists:project_milestones,id',
            'priority' => 'required|in:low,medium,high,critical',
            'due_date' => 'nullable|date',
        ]);

        $position = Task::where('column_id', $data['column_id'])->max('position') + 1;

        $project->tasks()->create([
            ...$data,
            'project_id' => $project->id,
            'created_by' => auth()->id(),
            'position' => $position,
            'status' => 'todo',
        ]);

        return back()->with('success', 'Task created.');
    }

    public function update(Request $request, Project $project, Task $task): RedirectResponse
    {
        $this->authorize('view', $project);
        abort_unless($task->project_id === $project->id, 404);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'assignee_id' => 'nullable|exists:users,id',
            'milestone_id' => 'nullable|exists:project_milestones,id',
            'priority' => 'required|in:low,medium,high,critical',
            'due_date' => 'nullable|date',
        ]);

        $task->update($data);

        return back()->with('success', 'Task updated.');
    }

    public function destroy(Project $project, Task $task): RedirectResponse
    {
        $this->authorize('view', $project);
        abort_unless($task->project_id === $project->id, 404);

        $task->delete();

        return back()->with('success', 'Task deleted.');
    }

    public function move(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('view', $project);
        abort_unless($task->project_id === $project->id, 404);

        $data = $request->validate([
            'column_id' => 'required|exists:project_columns,id',
            'position' => 'required|integer|min:0',
        ]);

        $this->service->moveTask($task, $data['column_id'], $data['position']);

        return response()->json(['ok' => true]);
    }

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

        $checklistItem = $task->checklistItems()->findOrFail($item);
        $checklistItem->update(['completed' => ! $checklistItem->completed]);

        return response()->json(['completed' => $checklistItem->completed]);
    }
}
