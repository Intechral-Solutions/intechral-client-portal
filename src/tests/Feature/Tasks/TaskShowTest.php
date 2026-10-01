<?php

use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-014 §10, §13.1, P6 (WP5): GET /tasks/{task} (`tasks.show`).
 *
 * - a standalone task renders its own detail page for its creator or current assignee (Q4);
 * - a board task is authorized through ProjectPolicy::view and then redirected to the canonical
 *   projects.tasks.show (P6), so there is one board detail;
 * - a ticket-kind task and a malformed dual-linked row are NOT surfaced: 404 for everyone,
 *   decided before any authorization, so no actor can tell a ticket task from a missing id (Q6);
 * - a surfaced task the actor may not open is 403, as every other tasks.* route answers.
 *
 * The detail DTO is explicit: no raw model, no email, no creator id, no ids the page does not use.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->creator = makeUser('user', ['name' => 'Cora Creator']);
    $this->assignee = makeUser('user', ['name' => 'Alan Assignee']);
    $this->task = Task::factory()->standalone()->create([
        'created_by' => $this->creator->id, 'assignee_id' => $this->assignee->id,
        'title' => 'Pack the van', 'description' => "Line one\nLine two", 'priority' => 'high',
        'status' => 'in_progress', 'due_date' => '2030-05-01',
    ]);
});

function showProps($response): array
{
    return $response->viewData('page')['props'];
}

// ── Standalone: who may open it ──────────────────────────────────────────────

it('renders the standalone detail page for the creator and the current assignee', function (string $who) {
    $user = $who === 'creator' ? $this->creator : $this->assignee;

    $this->actingAs($user)->get(route('tasks.show', $this->task))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('tasks/show')
            ->where('task.id', $this->task->id)
            ->where('task.title', 'Pack the van'));
})->with(['creator', 'assignee']);

it('denies a stranger, an operator, a project manager and a bare member, revealing nothing', function (string $who) {
    $user = match ($who) {
        'stranger' => makeUser(),
        'operator' => makeUser('operator'),
        'project_manager' => projectActor('project_manager', makeProject()),
        'view_all_holder' => tap(makeUser(), fn (User $u) => $u->givePermissionTo('tasks.view_all')),
    };

    $this->actingAs($user)->get(route('tasks.show', $this->task))->assertForbidden();
})->with(['stranger', 'operator', 'project_manager', 'view_all_holder']);

it('sends a guest to the login page', function () {
    $this->get(route('tasks.show', $this->task))->assertRedirect('/login');
});

it('a released task is no longer the former assignee\'s to open, but is still its creator\'s', function () {
    $this->task->update(['assignee_id' => null]);

    $this->actingAs($this->assignee)->get(route('tasks.show', $this->task))->assertForbidden();
    $this->actingAs($this->creator)->get(route('tasks.show', $this->task))->assertOk();
});

// ── Standalone: the DTO ──────────────────────────────────────────────────────

it('sends an explicit task DTO and nothing else about the task', function () {
    $props = showProps($this->actingAs($this->creator)->get(route('tasks.show', $this->task))->assertOk());

    expect($props['task'])->toBe([
        'id' => $this->task->id,
        'title' => 'Pack the van',
        'description' => "Line one\nLine two",
        'priority' => 'high',
        'dueDate' => '2030-05-01',
        'overdue' => false,
        'status' => ['label' => 'In Progress', 'done' => false, 'source' => 'status'],
        'statusValue' => 'in_progress',
        'assignee' => ['id' => $this->assignee->id, 'name' => 'Alan Assignee'],
    ]);

    // The standalone page has no project, milestone, column, checklist or comments to pretend about.
    expect($props)->not->toHaveKeys(['project', 'checklist', 'comments'])
        ->and(json_encode($props['task']))->not->toContain('@')
        ->and(array_keys($props['abilities']))->toBe(['update', 'complete', 'reopen', 'delete', 'assign', 'logTime']);
});

it('reports done and overdue the way the list does', function () {
    $this->task->update(['status' => 'done', 'due_date' => today()->subDay()]);
    $done = showProps($this->actingAs($this->creator)->get(route('tasks.show', $this->task))->assertOk())['task'];
    expect($done['status']['done'])->toBeTrue()->and($done['overdue'])->toBeFalse();

    $this->task->update(['status' => 'todo']);
    $open = showProps($this->actingAs($this->creator)->get(route('tasks.show', $this->task))->assertOk())['task'];
    expect($open['overdue'])->toBeTrue();
});

it('gives the creator and the assignee every standalone ability, from TaskPolicy', function (string $who) {
    $user = $who === 'creator' ? $this->creator : $this->assignee;

    $props = showProps($this->actingAs($user)->get(route('tasks.show', $this->task))->assertOk());

    expect(collect($props['abilities'])->except('logTime')->all())->toBe([
        'update' => true, 'complete' => true, 'reopen' => true, 'delete' => true, 'assign' => true,
    ]);
})->with(['creator', 'assignee']);

it('offers server-named priorities and statuses and only Me or nobody as an assignee', function () {
    $options = showProps($this->actingAs($this->creator)->get(route('tasks.show', $this->task))->assertOk())['options'];

    expect(collect($options['priorities'])->pluck('value')->all())->toBe(['low', 'medium', 'high', 'critical'])
        ->and(collect($options['statuses'])->all())->toBe([
            ['value' => 'todo', 'label' => 'To Do'],
            ['value' => 'in_progress', 'label' => 'In Progress'],
            ['value' => 'done', 'label' => 'Done'],
        ])
        // "Me" is the actor and nobody else: no arbitrary user is ever named (§7.3, Q4).
        ->and($options['assignees'])->toBe(['self' => ['id' => $this->creator->id, 'name' => 'Cora Creator']]);
});

it('never names another user in the assignee options, whoever the current assignee is', function () {
    // The creator opens a task someone else holds: that holder is the task's own assignee (shown
    // by task.assignee), and "Me" is still the only person on offer.
    $other = makeUser('user', ['name' => 'Zed Stranger']);
    makeUser('user', ['name' => 'Another Person']);

    $props = showProps($this->actingAs($this->creator)->get(route('tasks.show', $this->task))->assertOk());

    expect(json_encode($props['options']))->not->toContain('Alan Assignee')->not->toContain($other->name)->not->toContain('Another Person');
});

// ── Standalone: contextual time (R9) ─────────────────────────────────────────

it('sends the viewer\'s own time summary and offers the timer only to an eligible assignee', function () {
    TimeEntry::factory()->create(['user_id' => $this->assignee->id, 'task_id' => $this->task->id, 'duration_minutes' => 45, 'timer_started_at' => null]);

    $props = showProps($this->actingAs($this->assignee)->get(route('tasks.show', $this->task))->assertOk());

    expect($props['timeSummary']['totalMinutes'])->toBe(45)
        ->and($props['timeSummary']['scope'])->toBe('own')
        ->and($props['abilities']['logTime'])->toBeTrue();
});

it('does not offer the timer to the creator of an unassigned task (P5), though they see the summary', function () {
    $this->task->update(['assignee_id' => null]);

    $props = showProps($this->actingAs($this->creator)->get(route('tasks.show', $this->task))->assertOk());

    expect($props['abilities']['logTime'])->toBeFalse()
        ->and($props['timeSummary'])->not->toBeNull();
});

it('offers no time panel data to a viewer holding neither time permission', function () {
    $this->assignee->revokePermissionTo('time.log');
    $this->assignee->roles()->detach();

    $props = showProps($this->actingAs($this->assignee->fresh())->get(route('tasks.show', $this->task))->assertOk());

    expect($props['timeSummary'])->toBeNull()->and($props['abilities']['logTime'])->toBeFalse();
});

// ── Board: one canonical detail (P6) ─────────────────────────────────────────

it('redirects a board task to its canonical project task page for anyone who may view it', function (string $actor) {
    $project = makeProject();
    $task = makeTask($project->columns[1]);
    $user = projectActor($actor, $project);

    $this->actingAs($user)->get(route('tasks.show', $task))
        ->assertRedirect(route('projects.tasks.show', [$project, $task]));
})->with(['member', 'manager_role', 'project_manager', 'admin']);

it('denies a board task to anyone ProjectPolicy would deny, before any redirect', function (string $actor) {
    $project = makeProject();
    $task = makeTask($project->columns[1]);
    $user = projectActor($actor, $project, $task);

    $this->actingAs($user)->get(route('tasks.show', $task))->assertForbidden();
})->with(['outsider', 'assignee']);

it('denies a departed board assignee: a stale assignment grants no visibility', function () {
    $project = makeProject();
    $former = makeUser();
    $task = makeTask($project->columns[1], ['assignee_id' => $former->id]);

    $this->actingAs($former)->get(route('tasks.show', $task))->assertForbidden();
});

// ── Ticket-kind and malformed rows: authorize first, then the kind (Q6, INV-13) ──

it('answers 404 for a ticket task only to an actor who may view it, and 403 to everyone else', function () {
    $owner = makeUser();
    $ticket = Ticket::factory()->create(['user_id' => $owner->id]);
    $ticketTask = Task::factory()->create(['project_id' => null, 'column_id' => null, 'ticket_id' => $ticket->id, 'assignee_id' => $this->assignee->id]);

    // TicketPolicy viewers (the ticket's owner, an operator): authorized, then not surfaced (Q6).
    foreach ([$owner, makeUser('operator')] as $viewer) {
        $this->actingAs($viewer)->get(route('tasks.show', $ticketTask))->assertNotFound();
    }

    // Anyone else, the task's own assignee included, is denied before the kind is consulted.
    foreach ([$this->assignee, $this->creator, makeUser()] as $actor) {
        $this->actingAs($actor)->get(route('tasks.show', $ticketTask))->assertForbidden();
    }
});

it('denies a malformed project-and-ticket row to everyone, a manager and an operator included', function () {
    $project = makeProject();
    $ticket = Ticket::factory()->create();
    $bad = makeTask($project->columns[1]);
    $bad->forceFill(['ticket_id' => $ticket->id])->save();

    $this->actingAs(makeUser('operator'))->get(route('tasks.show', $bad))->assertForbidden();
    $this->actingAs(projectActor('project_manager', $project))->get(route('tasks.show', $bad))->assertForbidden();
});

it('gives an unauthorized actor no kind oracle: a ticket task and an unowned standalone task both answer 403', function () {
    $ticket = Ticket::factory()->create();
    $ticketTask = Task::factory()->create(['project_id' => null, 'column_id' => null, 'ticket_id' => $ticket->id]);
    $stranger = makeUser();

    $this->actingAs($stranger)->get(route('tasks.show', $ticketTask))->assertForbidden();
    $this->actingAs($stranger)->get(route('tasks.show', $this->task))->assertForbidden();
});

it('answers 404 for a missing id', function () {
    $this->actingAs($this->creator)->get('/tasks/999999')->assertNotFound();
});

it('does not claim a ticket task is indistinguishable from a missing id: the JSON 404 messages differ', function () {
    // Documents why the invariant is "no pre-authorization oracle", not universal indistinguishability.
    $owner = makeUser();
    $ticket = Ticket::factory()->create(['user_id' => $owner->id]);
    $ticketTask = Task::factory()->create(['project_id' => null, 'column_id' => null, 'ticket_id' => $ticket->id]);

    $this->actingAs($owner);
    $ticketMessage = $this->getJson(route('tasks.show', $ticketTask))->assertNotFound()->json('message');
    $missingMessage = $this->getJson('/tasks/999999')->assertNotFound()->json('message');

    expect($ticketMessage)->not->toBe($missingMessage);
});
