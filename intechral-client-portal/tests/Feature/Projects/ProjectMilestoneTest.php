<?php

use App\Models\ProjectMilestone;
use App\Models\User;
use App\Services\ProjectService;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

// ── Viewing milestones ────────────────────────────────────────────────────────

it('allows members to view milestones', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Milestone View Test']);

    $member = User::factory()->create();
    $member->assignRole('user');
    $project->members()->attach($member->id, ['role' => 'member']);

    $this->actingAs($member)->get(route('projects.milestones.index', $project))->assertOk();
});

it('returns 403 for non-members viewing milestones', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Private Milestones']);

    $outsider = User::factory()->create();
    $outsider->assignRole('user');

    $this->actingAs($outsider)->get(route('projects.milestones.index', $project))->assertForbidden();
});

// ── Creating milestones ───────────────────────────────────────────────────────

it('allows project managers to create milestones', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Milestone Create Test']);

    $this->actingAs($operator)->post(route('projects.milestones.store', $project), [
        'name' => 'v1.0 Launch',
        'due_date' => '2026-06-30',
    ])->assertRedirect();

    expect(ProjectMilestone::where('name', 'v1.0 Launch')->exists())->toBeTrue();
});

it('forbids regular members from creating milestones', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Milestone Access Test']);

    $member = User::factory()->create();
    $member->assignRole('user');
    $project->members()->attach($member->id, ['role' => 'member']);

    $this->actingAs($member)->post(route('projects.milestones.store', $project), [
        'name' => 'Unauthorized Milestone',
        'due_date' => '2026-07-01',
    ])->assertForbidden();
});

it('validates required fields when creating a milestone', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Validation Test']);

    $this->actingAs($operator)->post(route('projects.milestones.store', $project), [
        // missing name and due_date
    ])->assertSessionHasErrors(['name', 'due_date']);
});

// ── Updating milestones ───────────────────────────────────────────────────────

it('allows project managers to update milestones', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Milestone Update Test']);
    $milestone = $project->milestones()->create(['name' => 'Beta', 'due_date' => '2026-05-01']);

    $this->actingAs($operator)->put(route('projects.milestones.update', [$project, $milestone]), [
        'name' => 'Beta Release',
        'due_date' => '2026-05-15',
    ])->assertRedirect();

    expect($milestone->fresh()->name)->toBe('Beta Release');
    expect($milestone->fresh()->due_date->format('Y-m-d'))->toBe('2026-05-15');
});

// ── Deleting milestones ───────────────────────────────────────────────────────

it('allows project managers to delete milestones', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Milestone Delete Test']);
    $milestone = $project->milestones()->create(['name' => 'Delete Me', 'due_date' => '2026-04-01']);

    $this->actingAs($operator)->delete(route('projects.milestones.destroy', [$project, $milestone]))
        ->assertRedirect();

    expect(ProjectMilestone::find($milestone->id))->toBeNull();
});

it('returns 404 if milestone does not belong to the project', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project1 = app(ProjectService::class)->create($operator, ['name' => 'P1']);
    $project2 = app(ProjectService::class)->create($operator, ['name' => 'P2']);
    $milestone = $project2->milestones()->create(['name' => 'P2 Milestone', 'due_date' => '2026-04-01']);

    $this->actingAs($operator)->delete(route('projects.milestones.destroy', [$project1, $milestone]))
        ->assertNotFound();
});

// ── Milestone completion ──────────────────────────────────────────────────────

it('calculates milestone completion percentage correctly', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Completion Test']);
    $milestone = $project->milestones()->create(['name' => 'M1', 'due_date' => '2026-06-01']);

    $doneColumn = $project->columns()->where('is_done_column', true)->first();
    $notDoneColumn = $project->columns()->where('is_done_column', false)->first();

    // Create 3 tasks: 2 done, 1 not
    $project->tasks()->create(['column_id' => $doneColumn->id, 'milestone_id' => $milestone->id, 'created_by' => $operator->id, 'title' => 'Done 1', 'priority' => 'low', 'position' => 0]);
    $project->tasks()->create(['column_id' => $doneColumn->id, 'milestone_id' => $milestone->id, 'created_by' => $operator->id, 'title' => 'Done 2', 'priority' => 'low', 'position' => 1]);
    $project->tasks()->create(['column_id' => $notDoneColumn->id, 'milestone_id' => $milestone->id, 'created_by' => $operator->id, 'title' => 'Not Done', 'priority' => 'low', 'position' => 0]);

    $milestone->load('tasks.column');
    expect($milestone->completionPercentage())->toBe(67);
});
