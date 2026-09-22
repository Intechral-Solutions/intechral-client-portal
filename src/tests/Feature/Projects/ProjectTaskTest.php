<?php

use App\Models\Task;
use App\Models\User;
use App\Services\ProjectService;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

// ── Task creation ─────────────────────────────────────────────────────────────

it('allows project members to create tasks', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Task Test Project']);

    $column = $project->columns()->first();

    $this->actingAs($operator)->post(route('projects.tasks.store', $project), [
        'column_id' => $column->id,
        'title' => 'First Task',
        'priority' => 'medium',
    ])->assertRedirect();

    expect(Task::where('title', 'First Task')->exists())->toBeTrue();
});

it('forbids non-members from creating tasks', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Private Tasks']);

    $column = $project->columns()->first();
    $outsider = User::factory()->create();
    $outsider->assignRole('user');

    $this->actingAs($outsider)->post(route('projects.tasks.store', $project), [
        'column_id' => $column->id,
        'title' => 'Injected Task',
        'priority' => 'low',
    ])->assertForbidden();
});

it('validates required fields when creating a task', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Validation Project']);

    $this->actingAs($operator)->post(route('projects.tasks.store', $project), [
        'column_id' => $project->columns()->first()->id,
        // missing title and priority
    ])->assertSessionHasErrors(['title', 'priority']);
});

// ── Task view ─────────────────────────────────────────────────────────────────

it('allows members to view a task', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'View Task Project']);
    $column = $project->columns()->first();

    $task = $project->tasks()->create([
        'column_id' => $column->id,
        'created_by' => $operator->id,
        'title' => 'Viewable Task',
        'priority' => 'low',
        'position' => 0,
    ]);

    $this->actingAs($operator)->get(route('projects.tasks.show', [$project, $task]))->assertOk();
});

it('returns 404 if task does not belong to the project', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $project1 = app(ProjectService::class)->create($operator, ['name' => 'Project 1']);
    $project2 = app(ProjectService::class)->create($operator, ['name' => 'Project 2']);

    $column = $project2->columns()->first();
    $task = $project2->tasks()->create([
        'column_id' => $column->id,
        'created_by' => $operator->id,
        'title' => 'Task from P2',
        'priority' => 'low',
        'position' => 0,
    ]);

    $this->actingAs($operator)->get(route('projects.tasks.show', [$project1, $task]))->assertNotFound();
});

// ── Task move ─────────────────────────────────────────────────────────────────

it('moves a task to another column via the move endpoint', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Move Test Project']);

    $columns = $project->columns;
    $source = $columns->first();
    $target = $columns->skip(1)->first();

    $task = $project->tasks()->create([
        'column_id' => $source->id,
        'created_by' => $operator->id,
        'title' => 'Moveable Task',
        'priority' => 'medium',
        'position' => 0,
    ]);

    $this->actingAs($operator)
        ->putJson(route('projects.tasks.move', [$project, $task]), [
            'column_id' => $target->id,
            'position' => 0,
        ])
        // Redirect-back (WP5): Inertia's partial reload returns the authoritative board state.
        ->assertRedirect();

    expect($task->fresh()->column_id)->toBe($target->id);
});

// ── Task update & delete ──────────────────────────────────────────────────────

it('allows the project manager to update a task', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Update Task Project']);
    $column = $project->columns()->first();

    $task = $project->tasks()->create([
        'column_id' => $column->id,
        'created_by' => $operator->id,
        'title' => 'Old Title',
        'priority' => 'low',
        'position' => 0,
    ]);

    $this->actingAs($operator)->put(route('projects.tasks.update', [$project, $task]), [
        'title' => 'New Title',
        'priority' => 'high',
    ])->assertRedirect();

    expect($task->fresh()->title)->toBe('New Title');
    expect($task->fresh()->priority)->toBe('high');
});

it('deletes a task', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Delete Task Project']);
    $column = $project->columns()->first();

    $task = $project->tasks()->create([
        'column_id' => $column->id,
        'created_by' => $operator->id,
        'title' => 'Delete Me',
        'priority' => 'low',
        'position' => 0,
    ]);

    $this->actingAs($operator)->delete(route('projects.tasks.destroy', [$project, $task]))
        ->assertRedirect(route('projects.board', $project));

    expect(Task::find($task->id))->toBeNull();
});

// ── Task position re-ordering ─────────────────────────────────────────────────

it('re-orders remaining tasks when a task is moved out of a column', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Reorder Test']);
    $columns = $project->columns;
    $source = $columns->first();
    $target = $columns->skip(1)->first();

    $t1 = $project->tasks()->create(['column_id' => $source->id, 'created_by' => $operator->id, 'title' => 'T1', 'priority' => 'low', 'position' => 0]);
    $t2 = $project->tasks()->create(['column_id' => $source->id, 'created_by' => $operator->id, 'title' => 'T2', 'priority' => 'low', 'position' => 1]);
    $t3 = $project->tasks()->create(['column_id' => $source->id, 'created_by' => $operator->id, 'title' => 'T3', 'priority' => 'low', 'position' => 2]);

    // Move T2 out
    app(ProjectService::class)->moveTask($t2, $target->id, 0);

    expect($t1->fresh()->position)->toBe(0);
    expect($t3->fresh()->position)->toBe(1); // gap closed
    expect($t2->fresh()->column_id)->toBe($target->id);
    expect($t2->fresh()->position)->toBe(0);
});
