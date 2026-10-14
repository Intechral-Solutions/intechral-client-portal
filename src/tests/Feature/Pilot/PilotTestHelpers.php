<?php

use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ProjectService;
use Spatie\Permission\Models\Role;

/*
 * Shared fixtures for the R0.9-CS1 customer pilot suites. Loaded with require_once; the file name does not
 * end in "Test" so Pest does not run it. Function names carry the `pilot` prefix (Pest loads every test file
 * into one process).
 */

/**
 * Set the customer surface mode for the current test: 'closed', 'pilot' or 'legacy'. The suite's baseline is legacy
 * (phpunit.xml); every test of the boundary states its mode.
 */
function pilotMode(string $mode): void
{
    config(['portal.customer_surface_mode' => $mode]);
}

/** A customer: the built-in `user` role exactly as an invitation assigns it. */
function pilotCustomer(array $attributes = []): User
{
    return User::factory()->create($attributes)->assignRole('user');
}

/** An operator: the built-in `operator` role. */
function pilotOperator(array $attributes = []): User
{
    return User::factory()->create($attributes)->assignRole('operator');
}

/** A user holding a custom role with exactly the given permissions, never a built-in role. */
function pilotCustomRoleUser(string $roleName, array $permissions, array $attributes = []): User
{
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    return User::factory()->create($attributes)->assignRole($role);
}

/**
 * A project the operator created, with the customer added as a plain member, carrying every kind of
 * internal data the pilot must never send: a description, a budget, an operator member, a task with a
 * comment, a checklist item and a time entry, and a completed milestone with a description and a
 * completer. Every internal string contains "PILOT-SECRET" so a leak is greppable in a raw response.
 *
 * @return array{project: Project, operator: User, task: Task, comment: TaskComment, milestone: ProjectMilestone, entry: TimeEntry}
 */
function pilotProjectFor(User $customer, string $name = 'Customer Visible Project'): array
{
    $operator = pilotOperator(['name' => 'PILOT-SECRET Operator Name']);
    $project = app(ProjectService::class)->create($operator, [
        'name' => $name,
        'description' => 'PILOT-SECRET internal description',
        'budget' => 98765.43,
        'status' => 'active',
        'start_date' => '2026-01-05',
        'target_date' => '2026-12-31',
    ]);
    $project->members()->attach($customer->id, ['role' => 'member']);

    $column = $project->columns()->first();
    $task = Task::factory()->inColumn($column)->create([
        'created_by' => $operator->id,
        'assignee_id' => $operator->id,
        'title' => 'PILOT-SECRET task title',
        'description' => 'PILOT-SECRET task description',
        'position' => 0,
    ]);
    $comment = TaskComment::factory()->create([
        'task_id' => $task->id,
        'user_id' => $operator->id,
        'body' => 'PILOT-SECRET operator comment',
    ]);
    $task->checklistItems()->create(['title' => 'PILOT-SECRET checklist item', 'position' => 0]);

    $milestone = $project->milestones()->create([
        'name' => 'Go-live milestone',
        'description' => 'PILOT-SECRET milestone description',
        'due_date' => '2026-11-02',
    ]);
    // Completed on purpose: the completion state, its time and its completer must never reach a customer.
    $milestone->forceFill(['completed_at' => now(), 'completed_by' => $operator->id])->save();

    $entry = TimeEntry::factory()->create([
        'user_id' => $operator->id,
        'task_id' => $task->id,
        'project_id' => $project->id,
        'description' => 'PILOT-SECRET time entry',
    ]);

    return compact('project', 'operator', 'task', 'comment', 'milestone', 'entry');
}

function pilotInvoiceFor(User $client): Invoice
{
    return Invoice::factory()->create(['client_id' => $client->id, 'status' => 'sent']);
}
