<?php

use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-011E WP1: defects fixed in the Blade pages while they still serve the routes. The
 * create/edit pages left Blade in WP3 (S1, S2 and the project half of D4 are now covered by the
 * React page suites and ProjectInertiaPagesTest); the board left Blade in WP5 (D1's read-only
 * board is now covered by ProjectInertiaPagesTest and the Vitest board suites); the task page
 * left Blade in WP7 (its D1 and D6 assertions below moved to ProjectTaskDetailInertiaTest.php,
 * which pins the same behavior against the DTO instead of the deleted view's raw HTML).
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->admin = makeUser('operator');
    $this->project = makeProject($this->admin);
    $this->todo = $this->project->columns[1];
});

// ── D4: the blocked delete is shown ──────────────────────────────────────────

it('shows the recorded-time error on the task page', function () {
    $manager = projectActor('project_manager', $this->project);
    $task = makeTask($this->todo);
    TimeEntry::factory()->create(['user_id' => makeUser()->id, 'task_id' => $task->id]);

    $this->actingAs($manager)->from(route('projects.tasks.show', [$this->project, $task]))->followingRedirects()
        ->delete(route('projects.tasks.destroy', [$this->project, $task]))
        ->assertOk()->assertSee('This task has recorded time and cannot be deleted.');
});

// ── D6: the ticket embed is unaffected by the task page's move to React ──────

it('leaves the ticket time panel untouched', function () {
    $owner = makeUser('user');
    $other = makeUser('user', ['name' => 'Ticket Coworker']);
    $ticket = Ticket::factory()->create(['user_id' => $owner->id, 'status' => 'open']);
    TimeEntry::factory()->create(['user_id' => $other->id, 'ticket_id' => $ticket->id, 'duration_minutes' => 60, 'date' => today()]);

    $this->actingAs($owner)->get(route('tickets.show', $ticket))->assertOk()
        ->assertSee('Ticket Coworker')->assertSee('1h');
});

// ── Non-Blade sanity: pages still render for the roles that reach them ───────

it('still renders every project page for an administrator with tasks present', function () {
    $task = makeTask($this->todo, ['title' => 'Everywhere']);
    Task::factory()->standalone()->create(['title' => 'Loose', 'assignee_id' => $this->admin->id]);

    foreach ([
        route('projects.index'), route('projects.board', $this->project), route('projects.milestones.index', $this->project),
        route('projects.tasks.show', [$this->project, $task]), route('tasks.index'),
        route('projects.create'), route('projects.edit', $this->project),
    ] as $url) {
        $this->actingAs($this->admin)->get($url)->assertOk();
    }
});
