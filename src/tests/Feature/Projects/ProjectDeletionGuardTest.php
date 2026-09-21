<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-011E D4 (§16): a project or task referenced by ANY time entry cannot be hard-deleted,
 * and recorded time is never nulled or otherwise touched. The application guard is backed by
 * a restrictOnDelete foreign key (C3).
 */

const PROJECT_TIME_MESSAGE = 'This project has recorded time and cannot be deleted.';
const TASK_TIME_MESSAGE = 'This task has recorded time and cannot be deleted.';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->project = makeProject();
    $this->todo = $this->project->columns[1];
    $this->manager = projectActor('project_manager', $this->project);
    $this->worker = makeUser();
});

/** The columns of an entry that must survive a blocked delete unchanged. */
function entrySnapshot(TimeEntry $entry): array
{
    return $entry->fresh()->only([
        'user_id', 'project_id', 'task_id', 'ticket_id', 'invoice_id', 'billable', 'billed',
        'duration_minutes', 'timer_started_at', 'stopped_at', 'date', 'description',
    ]);
}

it('deletes an unreferenced task and project', function () {
    $task = makeTask($this->todo);

    $this->actingAs($this->manager)->delete(route('projects.tasks.destroy', [$this->project, $task]))
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(Task::find($task->id))->toBeNull();

    $this->actingAs($this->manager)->delete(route('projects.destroy', $this->project))
        ->assertRedirect(route('projects.index'))->assertSessionHasNoErrors();
    expect(Project::find($this->project->id))->toBeNull();
});

it('blocks deleting a project that has direct time', function () {
    $entry = TimeEntry::factory()->create(['user_id' => $this->worker->id, 'project_id' => $this->project->id]);

    $this->actingAs($this->manager)->delete(route('projects.destroy', $this->project))
        ->assertRedirect()->assertSessionHasErrors(['delete' => PROJECT_TIME_MESSAGE]);

    expect(Project::find($this->project->id))->not->toBeNull()
        ->and($entry->fresh()->project_id)->toBe($this->project->id);
});

it('blocks deleting a task that has time', function () {
    $task = makeTask($this->todo);
    $entry = TimeEntry::factory()->create(['user_id' => $this->worker->id, 'task_id' => $task->id]);

    $this->actingAs($this->manager)->delete(route('projects.tasks.destroy', [$this->project, $task]))
        ->assertRedirect()->assertSessionHasErrors(['delete' => TASK_TIME_MESSAGE]);

    expect(Task::find($task->id))->not->toBeNull()
        ->and($entry->fresh()->task_id)->toBe($task->id);
});

it('blocks deleting a project when one of its tasks has time', function () {
    $task = makeTask($this->todo);
    $entry = TimeEntry::factory()->create(['user_id' => $this->worker->id, 'task_id' => $task->id]);

    $this->actingAs($this->manager)->delete(route('projects.destroy', $this->project))
        ->assertRedirect()->assertSessionHasErrors(['delete' => PROJECT_TIME_MESSAGE]);

    expect(Project::find($this->project->id))->not->toBeNull()
        ->and(Task::find($task->id))->not->toBeNull()
        ->and($entry->fresh()->task_id)->toBe($task->id);
});

it('leaves billed, invoiced, unbilled and running entries untouched by a blocked delete', function (string $kind) {
    $task = makeTask($this->todo);
    $factory = TimeEntry::factory()->state(['user_id' => $this->worker->id, 'task_id' => $task->id]);
    $entry = match ($kind) {
        'billed' => $factory->billed()->create(),
        'invoiced' => $factory->invoiced()->create(),
        'unbilled' => $factory->create(),
        'running' => $factory->running()->create(),
    };
    $before = entrySnapshot($entry);

    $this->actingAs($this->manager)->delete(route('projects.destroy', $this->project))
        ->assertSessionHasErrors('delete');
    $this->actingAs($this->manager)->delete(route('projects.tasks.destroy', [$this->project, $task]))
        ->assertSessionHasErrors('delete');

    expect(entrySnapshot($entry))->toEqual($before)
        ->and(Project::find($this->project->id))->not->toBeNull()
        ->and(Task::find($task->id))->not->toBeNull();
})->with(['billed', 'invoiced', 'unbilled', 'running']);

it('answers a blocked delete with a validation error on delete, not a server error', function () {
    $task = makeTask($this->todo);
    TimeEntry::factory()->create(['user_id' => $this->worker->id, 'task_id' => $task->id]);

    $this->actingAs($this->manager)->deleteJson(route('projects.tasks.destroy', [$this->project, $task]))
        ->assertUnprocessable()->assertJsonValidationErrors('delete');
    $this->actingAs($this->manager)->deleteJson(route('projects.destroy', $this->project))
        ->assertUnprocessable()->assertJsonValidationErrors('delete');
});

it('maps a foreign-key violation from a race to the same validation message', function () {
    // Simulate a timer started between the guard and the DELETE: the model event fires after
    // the application check, inside the delete transaction, and records time on the row.
    $task = makeTask($this->todo);
    Task::deleting(function (Task $deleting) {
        TimeEntry::factory()->create(['user_id' => $this->worker->id, 'task_id' => $deleting->id]);
    });

    $this->actingAs($this->manager)->delete(route('projects.tasks.destroy', [$this->project, $task]))
        ->assertRedirect()->assertSessionHasErrors(['delete' => TASK_TIME_MESSAGE]);
    expect(Task::find($task->id))->not->toBeNull();

    Project::deleting(function (Project $deleting) {
        TimeEntry::factory()->create(['user_id' => $this->worker->id, 'project_id' => $deleting->id]);
    });
    $this->actingAs($this->manager)->delete(route('projects.destroy', $this->project))
        ->assertRedirect()->assertSessionHasErrors(['delete' => PROJECT_TIME_MESSAGE]);
    expect(Project::find($this->project->id))->not->toBeNull();
});

it('uses RESTRICT on both time entry foreign keys and refuses a raw delete', function () {
    $rules = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
        ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_NAME', 'time_entries')
        ->whereIn('CONSTRAINT_NAME', ['time_entries_project_id_foreign', 'time_entries_task_id_foreign'])
        ->pluck('DELETE_RULE', 'CONSTRAINT_NAME')
        ->all();

    expect($rules)->toBe([
        'time_entries_project_id_foreign' => 'RESTRICT',
        'time_entries_task_id_foreign' => 'RESTRICT',
    ]);

    $task = makeTask($this->todo);
    TimeEntry::factory()->create(['user_id' => $this->worker->id, 'task_id' => $task->id]);
    TimeEntry::factory()->create(['user_id' => $this->worker->id, 'project_id' => $this->project->id]);

    // Bypass the application: only the database stands between these deletes and the history.
    expect(fn () => DB::table('tasks')->where('id', $task->id)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('projects')->where('id', $this->project->id)->delete())->toThrow(QueryException::class);
    expect(TimeEntry::where('task_id', $task->id)->count())->toBe(1)
        ->and(TimeEntry::where('project_id', $this->project->id)->count())->toBe(1);
});

it('still lets users cascade columns and non-time children when the project is deletable', function () {
    $task = makeTask($this->todo);
    $task->checklistItems()->create(['title' => 'x', 'position' => 0]);
    $task->comments()->create(['user_id' => $this->worker->id, 'body' => 'hi']);

    $this->actingAs($this->manager)->delete(route('projects.destroy', $this->project))->assertRedirect();

    expect(DB::table('project_columns')->where('project_id', $this->project->id)->count())->toBe(0)
        ->and(DB::table('task_checklist_items')->where('task_id', $task->id)->count())->toBe(0)
        ->and(DB::table('task_comments')->where('task_id', $task->id)->count())->toBe(0);
});
