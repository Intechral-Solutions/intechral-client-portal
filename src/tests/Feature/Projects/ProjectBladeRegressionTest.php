<?php

use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-011E WP1: defects fixed in the Blade pages while they still serve the routes. The
 * create/edit pages left Blade in WP3 (S1, S2 and the project half of D4 are now covered by the
 * React page suites and ProjectInertiaPagesTest); the board left Blade in WP5 (D1's read-only
 * board is now covered by ProjectInertiaPagesTest and the Vitest board suites); the rest goes
 * with the task page (WP7).
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->admin = makeUser('operator');
    $this->project = makeProject($this->admin);
    $this->todo = $this->project->columns[1];
});

// ── D1: Blade offers no structural action to non-managers ────────────────────

it('hides the edit form and delete button on the task page from non-managers', function () {
    $task = makeTask($this->todo, ['title' => 'Detail']);

    foreach (['member' => false, 'manager_role' => false, 'project_manager' => true, 'admin' => true] as $actor => $sees) {
        $user = projectActor($actor, $this->project);
        $html = $this->actingAs($user)->get(route('projects.tasks.show', [$this->project, $task]))->assertOk()->getContent();

        // update and destroy share the task URL with the page's own links, so match the form
        expect(str_contains($html, 'action="'.route('projects.tasks.update', [$this->project, $task]).'"'))->toBe($sees, $actor)
            ->and(str_contains($html, 'Delete Task'))->toBe($sees, $actor);
        // comments stay open to every member
        expect($html)->toContain('action="'.route('projects.tasks.comments.store', [$this->project, $task]).'"');
    }
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

// ── D6: the Blade task time panel ────────────────────────────────────────────

it('shows the viewer only their own time on the task panel unless they hold time.view_all', function () {
    $task = makeTask($this->todo, ['title' => 'Timed']);
    $member = projectActor('member', $this->project);
    $manager = projectActor('project_manager', $this->project);
    // Not a project member, so the name can only come from the time panel (the manager's edit
    // form lists members).
    $other = makeUser('user', ['name' => 'Zed Otherperson']);

    TimeEntry::factory()->create(['user_id' => $member->id, 'task_id' => $task->id, 'duration_minutes' => 90, 'date' => today()]);
    TimeEntry::factory()->create(['user_id' => $other->id, 'task_id' => $task->id, 'duration_minutes' => 45, 'date' => today()]);
    TimeEntry::factory()->running()->create(['user_id' => $other->id, 'task_id' => $task->id]);
    $url = route('projects.tasks.show', [$this->project, $task]);

    $own = $this->actingAs($member)->get($url)->assertOk();
    $own->assertSee('1h 30m')->assertDontSee('Zed Otherperson')->assertDontSee('2h 15m');

    // Project-manager status grants nothing extra.
    $this->actingAs($manager)->get($url)->assertOk()
        ->assertDontSee('Zed Otherperson')->assertDontSee('1h 30m')->assertDontSee('2h 15m');

    $all = $this->actingAs($this->admin)->get($url)->assertOk();
    $all->assertSee('Zed Otherperson')->assertSee('2h 15m');
});

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
