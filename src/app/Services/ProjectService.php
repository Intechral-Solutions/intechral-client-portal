<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class ProjectService
{
    /** Default columns created with every new project */
    private const DEFAULT_COLUMNS = [
        ['name' => 'Backlog',     'position' => 0, 'is_done_column' => false],
        ['name' => 'To Do',       'position' => 1, 'is_done_column' => false],
        ['name' => 'In Progress', 'position' => 2, 'is_done_column' => false],
        ['name' => 'In Review',   'position' => 3, 'is_done_column' => false],
        ['name' => 'Done',        'position' => 4, 'is_done_column' => true],
    ];

    public function create(User $creator, array $data): Project
    {
        $project = Project::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'created_by' => $creator->id,
            'start_date' => $data['start_date'] ?? null,
            'target_date' => $data['target_date'] ?? null,
            'status' => $data['status'] ?? 'active',
            'budget' => $data['budget'] ?? null,
        ]);

        // Seed default columns
        foreach (self::DEFAULT_COLUMNS as $col) {
            $project->columns()->create($col);
        }

        // Add creator as manager
        $project->members()->attach($creator->id, ['role' => 'manager']);

        return $project;
    }

    public function syncMembers(Project $project, array $memberData): void
    {
        // $memberData = [['user_id' => X, 'role' => 'member|manager'], ...]
        $sync = collect($memberData)->mapWithKeys(fn ($m) => [
            $m['user_id'] => ['role' => $m['role'] ?? 'member'],
        ])->all();

        $project->members()->sync($sync);

        // Ensure creator always stays as manager
        $project->members()->syncWithoutDetaching([
            $project->created_by => ['role' => 'manager'],
        ]);
    }

    public function moveTask(Task $task, int $targetColumnId, int $position): void
    {
        // Re-order tasks in source column to close the gap
        Task::where('column_id', $task->column_id)
            ->where('position', '>', $task->position)
            ->decrement('position');

        // Make room in target column
        Task::where('column_id', $targetColumnId)
            ->where('position', '>=', $position)
            ->increment('position');

        $task->update([
            'column_id' => $targetColumnId,
            'position' => $position,
        ]);
    }
}
