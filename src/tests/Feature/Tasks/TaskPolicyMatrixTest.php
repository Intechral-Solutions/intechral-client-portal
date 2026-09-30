<?php

use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-014 §7.2 TaskPolicy matrix (WP1). Real roles, real permissions and real project
 * membership; every ability is asserted allow or deny for every actor, through the Gate, so the
 * registration is exercised too. No route consumes TaskPolicy yet (WP1 is foundation only).
 *
 * Board abilities delegate to ProjectPolicy (view / manage) and add only Q1's member-assignee arm
 * for complete/reopen. Standalone abilities are creator or current assignee (Q4). Ticket-kind
 * tasks may be viewed per TicketPolicy but every mutation is denied (Q6). A malformed row linked
 * to both a project and a ticket (INV-13) is refused everything.
 */

const TASK_ABILITIES = ['view', 'update', 'complete', 'reopen', 'delete', 'assign', 'move'];

/** Allowed abilities, as a set; everything else in TASK_ABILITIES must be denied. */
const ALL_BOARD = ['view', 'update', 'complete', 'reopen', 'delete', 'assign', 'move'];
const OWN_STANDALONE = ['view', 'update', 'complete', 'reopen', 'delete', 'assign'];

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->project = makeProject();
    $this->column = $this->project->columns()->where('is_done_column', false)->orderBy('position')->first();
});

function expectAbilities(User $user, Task $task, array $allowed): void
{
    foreach (TASK_ABILITIES as $ability) {
        expect(Gate::forUser($user)->allows($ability, $task))
            ->toBe(in_array($ability, $allowed, true), "ability [{$ability}]");
    }
}

// ── Board tasks ──────────────────────────────────────────────────────────────

it('authorizes a board task exactly as the matrix says for every actor', function (string $actor, array $allowed) {
    $task = makeTask($this->column);

    $user = match ($actor) {
        'operator' => makeUser('operator'),
        'ordinary_user' => makeUser(),
        'project_manager' => projectActor('project_manager', $this->project),
        'manager_role_only' => projectActor('manager_role', $this->project),
        'admin_only' => projectActor('admin_only', $this->project),
        'member' => projectActor('member', $this->project),
        'member_assignee' => tap(projectActor('member', $this->project), fn (User $u) => $task->update(['assignee_id' => $u->id])),
        'non_member_assignee' => projectActor('assignee', $this->project, $task),
        'departed_assignee' => tap(projectActor('member', $this->project), function (User $u) use ($task) {
            $task->update(['assignee_id' => $u->id]);
            $this->project->members()->detach($u->id);
        }),
    };

    expectAbilities($user, $task->fresh(), $allowed);
})->with([
    'operator (projects.admin)' => ['operator', ALL_BOARD],
    'ordinary user, not a member' => ['ordinary_user', []],
    'project manager (projects.manage + manager pivot)' => ['project_manager', ALL_BOARD],
    'manager pivot without projects.manage' => ['manager_role_only', ['view']],
    'projects.admin alone' => ['admin_only', ALL_BOARD],
    'plain member' => ['member', ['view']],
    // Q1: the current assignee who is still a member gains Complete/Reopen and nothing else.
    'member who is the current assignee' => ['member_assignee', ['view', 'complete', 'reopen']],
    // Assignment alone never bypasses ProjectPolicy visibility.
    'assignee who was never a member' => ['non_member_assignee', []],
    'stored assignee who has left the project' => ['departed_assignee', []],
]);

it('keeps a departed assignee stored while denying them everything (INV-7, §7.2)', function () {
    $member = projectActor('member', $this->project);
    $task = makeTask($this->column, ['assignee_id' => $member->id]);
    expect(Gate::forUser($member)->allows('complete', $task))->toBeTrue();

    $this->project->members()->detach($member->id);
    $task = $task->fresh();

    expect($task->assignee_id)->toBe($member->id);
    expectAbilities($member, $task, []);
});

it('decides the member-assignee arm at check time, so rejoining restores exactly Complete/Reopen', function () {
    $user = makeUser();
    $task = makeTask($this->column, ['assignee_id' => $user->id]);
    expectAbilities($user, $task, []);

    $this->project->members()->attach($user->id, ['role' => 'member']);
    expectAbilities($user, $task->fresh(), ['view', 'complete', 'reopen']);
});

it('never lets a member-assignee move the task: move is ProjectPolicy::manage alone', function () {
    $member = projectActor('member', $this->project);
    $task = makeTask($this->column, ['assignee_id' => $member->id]);
    $manager = projectActor('project_manager', $this->project);

    expect(Gate::forUser($member)->allows('move', $task))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('move', $task))->toBeTrue()
        ->and(Gate::forUser($member)->allows('manage', $this->project))->toBeFalse();
});

it('agrees with ProjectPolicy for every board actor on view and on the manage-only abilities', function () {
    $task = makeTask($this->column);
    foreach (['outsider', 'member', 'manager_role', 'project_manager', 'admin', 'admin_only'] as $kind) {
        $user = projectActor($kind, $this->project);
        $view = Gate::forUser($user)->allows('view', $this->project);
        $manage = Gate::forUser($user)->allows('manage', $this->project);

        expect(Gate::forUser($user)->allows('view', $task))->toBe($view, "{$kind} view");
        foreach (['update', 'delete', 'assign', 'move', 'complete', 'reopen'] as $ability) {
            // Not the assignee, so complete/reopen reduce to manage as well.
            expect(Gate::forUser($user)->allows($ability, $task))->toBe($manage, "{$kind} {$ability}");
        }
    }
});

// ── Standalone tasks ─────────────────────────────────────────────────────────

it('authorizes a standalone task to its creator and its current assignee only', function (string $actor, array $allowed) {
    $creator = makeUser();
    $assignee = makeUser();
    $task = Task::factory()->standalone()->create(['created_by' => $creator->id, 'assignee_id' => $assignee->id]);

    $user = match ($actor) {
        'creator' => $creator,
        'current_assignee' => $assignee,
        'unrelated_user' => makeUser(),
        'operator' => makeUser('operator'),
        'project_manager' => projectActor('project_manager', $this->project),
    };

    expectAbilities($user, $task, $allowed);
})->with([
    'creator' => ['creator', OWN_STANDALONE],
    'current assignee' => ['current_assignee', OWN_STANDALONE],
    'unrelated user' => ['unrelated_user', []],
    // Q3: standalone tasks are personal; holding every permission grants nothing here.
    'operator (every permission)' => ['operator', []],
    'a project manager elsewhere' => ['project_manager', []],
]);

it('gives an unassigned standalone task to its creator alone, and a released assignee loses it', function () {
    $creator = makeUser();
    $assignee = makeUser();
    $task = Task::factory()->standalone()->create(['created_by' => $creator->id, 'assignee_id' => $assignee->id]);
    expectAbilities($assignee, $task, OWN_STANDALONE);

    $task->update(['assignee_id' => null]);
    $task = $task->fresh();

    expectAbilities($creator, $task, OWN_STANDALONE);
    expectAbilities($assignee, $task, []);
});

// ── Ticket tasks (internal only, Q6) ─────────────────────────────────────────

it('lets a ticket task be viewed per TicketPolicy and denies every mutation', function (string $actor, array $allowed) {
    $owner = makeUser();
    $ticket = Ticket::factory()->create(['user_id' => $owner->id]);
    $taskAssignee = makeUser();
    $task = Task::factory()->standalone()->create([
        'ticket_id' => $ticket->id, 'created_by' => $owner->id, 'assignee_id' => $taskAssignee->id,
    ]);

    $user = match ($actor) {
        'ticket_owner' => $owner,
        'operator' => makeUser('operator'),
        'non_viewer' => makeUser(),
        'task_assignee_non_viewer' => $taskAssignee,
    };

    expect(Gate::forUser($user)->allows('view', $ticket))->toBe($allowed === ['view']);
    expectAbilities($user, $task, $allowed);
})->with([
    'ticket owner (a TicketPolicy viewer, and the task creator)' => ['ticket_owner', ['view']],
    'operator (tickets.assign)' => ['operator', ['view']],
    'user who cannot view the ticket' => ['non_viewer', []],
    // Assignment alone never bypasses the ticket boundary either.
    'task assignee who cannot view the ticket' => ['task_assignee_non_viewer', []],
]);

// ── Malformed rows (INV-13) ──────────────────────────────────────────────────

it('refuses every ability on a row linked to both a project and a ticket, even to an operator', function () {
    $ticket = Ticket::factory()->create();
    $manager = projectActor('project_manager', $this->project);
    $task = makeTask($this->column, ['ticket_id' => $ticket->id, 'assignee_id' => $manager->id]);

    foreach ([makeUser('operator'), $manager] as $user) {
        expectAbilities($user, $task, []);
    }
});

it('denies a guest every ability', function () {
    $task = makeTask($this->column);

    foreach (TASK_ABILITIES as $ability) {
        expect(Gate::allows($ability, $task))->toBeFalse();
    }
});
