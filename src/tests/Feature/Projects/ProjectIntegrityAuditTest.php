<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-011E §16 "Existing data": WP1 delivers a read-only audit and does not rewrite anything.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function auditCounts(): array
{
    expect(Artisan::call('projects:audit-integrity', ['--json' => true]))->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

/** Row-level fingerprint of every table the audit reads, to prove it writes nothing. */
function auditedTablesFingerprint(): array
{
    return collect(['tasks', 'projects', 'project_columns', 'project_milestones', 'project_members', 'time_entries'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->get()->map(fn ($r) => json_encode($r))->sort()->values()->all()])
        ->all();
}

it('reports zero on a clean database', function () {
    $project = makeProject();
    makeTask($project->columns[0]);

    expect(auditCounts())->toBe([
        'tasks_with_column_from_another_project' => 0,
        'tasks_with_milestone_from_another_project' => 0,
        'tasks_assigned_to_non_members' => 0,
        'time_entries_with_missing_project_or_task' => 0,
        'projects_blocked_from_delete_by_time' => 0,
        'tasks_blocked_from_delete_by_time' => 0,
        'projects_with_no_done_column' => 0,
        'projects_with_multiple_done_columns' => 0,
        'tasks_linked_to_project_and_ticket' => 0,
        'time_entries_with_task_and_project' => 0,
        'time_entries_with_ticket_and_other_context' => 0,
    ]);
});

it('counts every legacy integrity problem without modifying any data', function () {
    $project = makeProject();
    $other = makeProject(null, 'Other');
    $member = projectActor('member', $project);
    $outsider = makeUser();

    // 1: column belongs to another project (the A1 shape)
    makeTask($project->columns[0])->update(['column_id' => $other->columns[0]->id]);
    // 2: milestone from another project
    $foreignMilestone = $other->milestones()->create(['name' => 'Other MS', 'due_date' => '2030-01-01']);
    makeTask($project->columns[0], ['milestone_id' => $foreignMilestone->id]);
    makeTask($project->columns[0], ['milestone_id' => $foreignMilestone->id]);
    // 3: assignee is not a member (two tasks), plus a legitimate member assignee that must not count
    makeTask($project->columns[1], ['assignee_id' => $outsider->id]);
    makeTask($project->columns[1], ['assignee_id' => $outsider->id]);
    makeTask($project->columns[1], ['assignee_id' => $member->id]);
    // standalone and ticket tasks have no project to be a member of
    Task::factory()->standalone()->create(['assignee_id' => $outsider->id]);
    // 5: time that would now block deletion
    $timed = makeTask($project->columns[2]);
    TimeEntry::factory()->create(['user_id' => $member->id, 'task_id' => $timed->id]);
    TimeEntry::factory()->create(['user_id' => $member->id, 'project_id' => $other->id]);
    // 4: an entry pointing at rows that no longer exist (foreign keys normally forbid this)
    Schema::disableForeignKeyConstraints();
    DB::table('time_entries')->insert([
        'user_id' => $member->id, 'project_id' => 987654, 'task_id' => null, 'date' => today()->toDateString(),
        'duration_minutes' => 30, 'billable' => true, 'billed' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('time_entries')->insert([
        'user_id' => $member->id, 'project_id' => null, 'task_id' => 987655, 'date' => today()->toDateString(),
        'duration_minutes' => 30, 'billable' => true, 'billed' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Schema::enableForeignKeyConstraints();
    // EPIC-014 INV-8: a board without exactly one Done column (one with none, one with two)
    $noDone = makeProject(null, 'No done column');
    $noDone->columns()->update(['is_done_column' => false]);
    $twoDone = makeProject(null, 'Two done columns');
    $twoDone->columns()->where('position', 3)->update(['is_done_column' => true]);
    // EPIC-014 INV-13: a task linked to both a project and a ticket (two), and a valid ticket task
    // that must not count
    $ticket = Ticket::factory()->create();
    makeTask($project->columns[0], ['ticket_id' => $ticket->id]);
    makeTask($project->columns[0], ['ticket_id' => $ticket->id]);
    Task::factory()->standalone()->create(['ticket_id' => $ticket->id]);

    // EPIC-015 §10.4: malformed time entries. Two carry a task AND a project (one on a board task,
    // one on a standalone task), two carry a ticket plus another context key. Valid single-context
    // entries (direct project, task only, ticket only) must not count.
    $standalone = Task::factory()->standalone()->create();
    TimeEntry::factory()->create(['user_id' => $member->id, 'task_id' => $timed->id, 'project_id' => $other->id]);
    TimeEntry::factory()->create(['user_id' => $member->id, 'task_id' => $standalone->id, 'project_id' => $project->id]);
    TimeEntry::factory()->create(['user_id' => $member->id, 'ticket_id' => $ticket->id, 'project_id' => $project->id]);
    TimeEntry::factory()->create(['user_id' => $member->id, 'ticket_id' => $ticket->id, 'task_id' => $standalone->id]);
    TimeEntry::factory()->create(['user_id' => $member->id, 'ticket_id' => $ticket->id]);

    $before = auditedTablesFingerprint();
    $counts = auditCounts();

    expect(auditedTablesFingerprint())->toBe($before)
        ->and($counts)->toBe([
            'tasks_with_column_from_another_project' => 1,
            'tasks_with_milestone_from_another_project' => 2,
            'tasks_assigned_to_non_members' => 2,
            'time_entries_with_missing_project_or_task' => 2,
            // $project (via its timed task) and $other (direct time)
            'projects_blocked_from_delete_by_time' => 2,
            // the timed board task, and the standalone task the malformed time entries reference
            'tasks_blocked_from_delete_by_time' => 2,
            'projects_with_no_done_column' => 1,
            'projects_with_multiple_done_columns' => 1,
            'tasks_linked_to_project_and_ticket' => 2,
            'time_entries_with_task_and_project' => 2,
            'time_entries_with_ticket_and_other_context' => 2,
        ]);
});

it('counts a bare project row with no columns at all as having no Done column', function () {
    // Only the application's own create path seeds the board; a row inserted any other way (a
    // factory, a partial create) has none, and the audit says so rather than assuming a board.
    makeProject();
    Project::factory()->create();

    expect(auditCounts())->toMatchArray([
        'projects_with_no_done_column' => 1,
        'projects_with_multiple_done_columns' => 0,
    ]);
});

it('prints a readable table without the json flag', function () {
    makeProject();

    $this->artisan('projects:audit-integrity')
        ->expectsOutputToContain('tasks_assigned_to_non_members')
        ->assertExitCode(0);
});

it('EPIC-015 INV-P3: projects_blocked_from_delete_by_time stays conservative — a malformed time entry blocks BOTH projects it references, though project time counts it under one', function () {
    $c = makeProject(null, 'C');
    $d = makeProject(null, 'D');
    $taskD = makeTask($d->columns[0]);
    TimeEntry::factory()->create(['user_id' => makeUser()->id, 'task_id' => $taskD->id, 'project_id' => $c->id]);

    expect(auditCounts())->toMatchArray([
        'projects_blocked_from_delete_by_time' => 2,
        'time_entries_with_task_and_project' => 1,
    ]);
});

it('EPIC-015 §10.4: the two malformed time-entry keys may OVERLAP on one row, because they report different violations', function () {
    $project = makeProject();
    $task = makeTask($project->columns[0]);
    $ticket = Ticket::factory()->create();

    // One row naming a ticket, a task AND a project: task+project, and ticket+another context.
    TimeEntry::factory()->create(['user_id' => makeUser()->id, 'ticket_id' => $ticket->id, 'task_id' => $task->id, 'project_id' => $project->id]);

    expect(auditCounts())->toMatchArray([
        'time_entries_with_task_and_project' => 1,
        'time_entries_with_ticket_and_other_context' => 1,
    ]);
});
