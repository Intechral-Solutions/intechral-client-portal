<?php

use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectService;

/*
 * Shared fixtures for the EPIC-011E project/task tests. Loaded with require_once from each
 * test file; the file name deliberately does not end in "Test" so Pest does not treat it as
 * a test. Actors follow EPIC-011E §16.
 */

/** Every actor of the §16 matrix, in matrix order. */
const PROJECT_ACTORS = [
    'guest',
    'outsider',
    'member',
    'manager_role',      // manager pivot role, but no projects.manage permission
    'project_manager',   // manager pivot role and projects.manage
    'admin',             // operator role: every permission
    'admin_only',        // projects.admin alone, no projects.manage (A9)
    'assignee',          // assigned a task but not a member
];

function makeUser(string $role = 'user', array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user;
}

function makeProject(?User $creator = null, string $name = 'Test Project'): Project
{
    $creator ??= makeUser('operator');

    return app(ProjectService::class)->create($creator, ['name' => $name]);
}

/** A board task appended to a column with an explicit position. */
function makeTask(ProjectColumn $column, array $attributes = []): Task
{
    return Task::factory()->inColumn($column)->create([
        'created_by' => Project::find($column->project_id)->created_by,
        'assignee_id' => null,
        'milestone_id' => null,
        'due_date' => null,
        'priority' => 'medium',
        'status' => 'todo',
        'position' => 0,
        ...$attributes,
    ]);
}

/**
 * Build the user for one actor against a project (and optionally the task the "assignee"
 * actor is assigned to). Returns null for the guest.
 */
function projectActor(string $kind, Project $project, ?Task $task = null): ?User
{
    switch ($kind) {
        case 'guest':
            return null;
        case 'outsider':
            return makeUser();
        case 'member':
            $user = makeUser();
            $project->members()->attach($user->id, ['role' => 'member']);

            return $user;
        case 'manager_role':
            $user = makeUser();
            $project->members()->attach($user->id, ['role' => 'manager']);

            return $user;
        case 'project_manager':
            $user = makeUser();
            $user->givePermissionTo('projects.manage');
            $project->members()->attach($user->id, ['role' => 'manager']);

            return $user;
        case 'admin':
            return makeUser('operator');
        case 'admin_only':
            $user = makeUser();
            $user->givePermissionTo('projects.admin');

            return $user;
        case 'assignee':
            $user = makeUser();
            if ($task !== null) {
                $task->update(['assignee_id' => $user->id]);
            }

            return $user;
    }

    throw new InvalidArgumentException("Unknown actor {$kind}");
}

/** Task ids of a column in the order the board renders them. */
function columnOrder(ProjectColumn|int $column): array
{
    $id = $column instanceof ProjectColumn ? $column->id : $column;

    return Task::where('column_id', $id)->orderBy('position')->orderBy('id')->pluck('id')->all();
}

/** @return array<int, int> task id => position */
function columnPositions(ProjectColumn|int $column): array
{
    $id = $column instanceof ProjectColumn ? $column->id : $column;

    return Task::where('column_id', $id)->orderBy('position')->orderBy('id')->pluck('position', 'id')->all();
}

/** True when the column holds exactly positions 0..n-1 with no duplicates. */
function columnIsDense(ProjectColumn|int $column): bool
{
    $positions = array_values(columnPositions($column));

    return $positions === ($positions === [] ? [] : range(0, count($positions) - 1));
}

/**
 * Maximum <form> nesting depth in an HTML document, computed with a plain tag stack (the
 * container runs PHP 8.3, so the HTML5 tree-builder Dom\HTMLDocument of PHP 8.4 is not
 * available). A depth above 1 means a form is opened before the previous one is closed.
 * Script and template bodies are ignored.
 */
function formNestingDepth(string $html): int
{
    $html = preg_replace('#<script\b.*?</script>#is', '', $html);
    $html = preg_replace('#<template\b.*?</template>#is', '', $html);

    $depth = 0;
    $max = 0;
    preg_match_all('#<(/?)form\b#i', $html, $matches);
    foreach ($matches[1] as $closing) {
        $depth += $closing === '/' ? -1 : 1;
        $max = max($max, $depth);
    }

    return $max;
}

/** Sanity check that every opened form is closed. */
function formsAreBalanced(string $html): bool
{
    $html = preg_replace('#<script\b.*?</script>#is', '', $html);
    $html = preg_replace('#<template\b.*?</template>#is', '', $html);

    return preg_match_all('#<form\b#i', $html) === preg_match_all('#</form>#i', $html);
}
