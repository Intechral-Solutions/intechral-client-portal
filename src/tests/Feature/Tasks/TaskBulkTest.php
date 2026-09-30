<?php

use App\Models\Task;
use App\Models\Ticket;
use App\Services\ProjectService;
use App\Services\RecordedTimeGuard;
use App\Services\TaskService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-014 §15.2 (WP2): POST /tasks/bulk {action: complete|reopen, ids[]}.
 *
 * Each id is authorized and executed on its own, through TaskPolicy and TaskService, in its own
 * transaction, so INV-4's lock ordering is never widened across columns or projects. There is no
 * all-or-nothing: the response is a redirect-back whose `bulk` flash reports every id in exactly
 * one bucket (succeeded / notPermitted / configurationError / failed). An id the actor cannot act
 * on, including one that does not exist, a ticket-kind or a malformed row, is `notPermitted`, so
 * the endpoint enumerates nothing. Ids are deduplicated and capped at one page (30).
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->manager = makeUser();
    $this->manager->givePermissionTo('projects.manage');
    $this->alpha = makeProject($this->manager, 'Alpha');
    $this->beta = makeProject($this->manager, 'Beta');
});

function doneColumnOf($project)
{
    return $project->columns()->where('is_done_column', true)->first();
}

function bulk($test, $user, string $action, array $ids)
{
    return $test->actingAs($user)->from('/tasks')->post(route('tasks.bulk'), ['action' => $action, 'ids' => $ids]);
}

it('completes a mixed board and standalone selection across projects, each independently', function () {
    $a = makeTask($this->alpha->columns[1]);
    $b = makeTask($this->beta->columns[2]);
    $mine = Task::factory()->standalone()->create(['created_by' => $this->manager->id, 'status' => 'todo']);

    bulk($this, $this->manager, 'complete', [$a->id, $b->id, $mine->id])
        ->assertRedirect('/tasks')
        ->assertSessionHas('bulk', [
            'action' => 'complete',
            'succeeded' => [$a->id, $b->id, $mine->id],
            'notPermitted' => [], 'configurationError' => [], 'failed' => [],
        ])
        ->assertSessionHas('success', '3 tasks completed.');

    expect($a->fresh()->column_id)->toBe(doneColumnOf($this->alpha)->id)
        ->and($b->fresh()->column_id)->toBe(doneColumnOf($this->beta)->id)
        ->and($mine->fresh()->status)->toBe('done')
        ->and(columnIsDense(doneColumnOf($this->alpha)))->toBeTrue();
});

it('reopens a selection and treats rows already in the target state as succeeded no-ops', function () {
    $doneTask = makeTask(doneColumnOf($this->alpha));
    $openTask = makeTask($this->alpha->columns[2]);

    bulk($this, $this->manager, 'reopen', [$doneTask->id, $openTask->id])
        ->assertSessionHas('bulk.succeeded', [$doneTask->id, $openTask->id]);

    expect($doneTask->fresh()->column_id)->toBe($this->alpha->columns[0]->id)
        ->and($openTask->fresh()->column_id)->toBe($this->alpha->columns[2]->id);
});

it('reports what the actor may not act on without touching it, and still does the rest', function () {
    $mine = makeTask($this->alpha->columns[1]);
    $foreignProject = makeProject(null, 'Foreign');
    $foreign = makeTask($foreignProject->columns[1]);
    $othersStandalone = Task::factory()->standalone()->create(['status' => 'todo']);
    $ticketTask = Task::factory()->standalone()->create(['ticket_id' => Ticket::factory()->create()->id, 'created_by' => $this->manager->id]);
    $dual = makeTask($this->alpha->columns[1], ['ticket_id' => Ticket::factory()->create()->id]);
    $missing = 999999;
    $snapshot = fn () => DB::table('tasks')->whereIn('id', [$foreign->id, $othersStandalone->id, $ticketTask->id, $dual->id])->orderBy('id')->get()->toJson();
    $before = $snapshot();

    bulk($this, $this->manager, 'complete', [$mine->id, $foreign->id, $othersStandalone->id, $ticketTask->id, $dual->id, $missing])
        ->assertSessionHas('bulk', [
            'action' => 'complete',
            'succeeded' => [$mine->id],
            'notPermitted' => [$foreign->id, $othersStandalone->id, $ticketTask->id, $dual->id, $missing],
            'configurationError' => [], 'failed' => [],
        ])
        ->assertSessionHas('success', '1 task completed.')
        ->assertSessionHas('error', '5 tasks could not be completed.');

    expect($snapshot())->toBe($before)
        ->and($mine->fresh()->column_id)->toBe(doneColumnOf($this->alpha)->id);
});

it('reports a misconfigured project per task and completes the tasks of healthy projects', function () {
    $this->beta->columns()->update(['is_done_column' => false]);
    $healthy = makeTask($this->alpha->columns[1]);
    $broken = makeTask($this->beta->columns[1]);

    bulk($this, $this->manager, 'complete', [$broken->id, $healthy->id])
        ->assertSessionHas('bulk.succeeded', [$healthy->id])
        ->assertSessionHas('bulk.configurationError', [$broken->id]);

    expect($healthy->fresh()->column_id)->toBe(doneColumnOf($this->alpha)->id)
        ->and($broken->fresh()->column_id)->toBe($this->beta->columns[1]->id);
});

it('grants a member-assignee exactly their Q1 rows in a bulk request', function () {
    $member = projectActor('member', $this->alpha);
    $assigned = makeTask($this->alpha->columns[1], ['assignee_id' => $member->id]);
    $notAssigned = makeTask($this->alpha->columns[1]);

    bulk($this, $member, 'complete', [$assigned->id, $notAssigned->id])
        ->assertSessionHas('bulk.succeeded', [$assigned->id])
        ->assertSessionHas('bulk.notPermitted', [$notAssigned->id]);
});

it('deduplicates ids and keeps the submitted order', function () {
    $a = makeTask($this->alpha->columns[1]);
    $b = makeTask($this->alpha->columns[1]);

    bulk($this, $this->manager, 'complete', [$b->id, $a->id, $b->id, (string) $a->id])
        ->assertSessionHas('bulk.succeeded', [$b->id, $a->id]);

    expect(columnOrder(doneColumnOf($this->alpha)))->toBe([$b->id, $a->id]);
});

it('caps a request at one page of 30 distinct ids and validates its shape', function () {
    $ids = range(1, 31);

    bulk($this, $this->manager, 'complete', $ids)->assertSessionHasErrors('ids');
    bulk($this, $this->manager, 'complete', [...range(1, 30), 1, 2])->assertSessionHasNoErrors();
    bulk($this, $this->manager, 'archive', [1])->assertSessionHasErrors('action');
    bulk($this, $this->manager, 'complete', [])->assertSessionHasErrors('ids');
    bulk($this, $this->manager, 'complete', ['x'])->assertSessionHasErrors('ids.0');
});

it('runs each task in its own transaction: a later refusal never rolls back an earlier success', function () {
    $first = makeTask($this->alpha->columns[1]);
    $this->beta->columns()->update(['is_done_column' => false]);
    $second = makeTask($this->beta->columns[1]);

    bulk($this, $this->manager, 'complete', [$first->id, $second->id]);

    expect($first->fresh()->column_id)->toBe(doneColumnOf($this->alpha)->id);
});

it('needs authentication and nothing more to call, since each row is authorized on its own', function () {
    $this->post(route('tasks.bulk'), ['action' => 'complete', 'ids' => [1]])->assertRedirect('/login');

    bulk($this, makeUser(), 'complete', [makeTask($this->alpha->columns[1])->id])
        ->assertSessionHas('bulk.notPermitted');
});

it('reports a database failure that outlives the retries as `failed`, keeps the earlier commit and carries on', function () {
    // SQLSTATE 40001 / 1213 is what MariaDB raises for a deadlock victim once TaskService's own
    // DB::transaction attempts are spent. Simulated after the service boundary, never a real race.
    $first = makeTask($this->alpha->columns[1]);
    $doomed = makeTask($this->alpha->columns[1]);
    $last = makeTask($this->alpha->columns[2]);
    $reported = [];

    $this->app->bind(TaskService::class, fn ($app) => new class($app->make(ProjectService::class), $app->make(RecordedTimeGuard::class), $doomed->id) extends TaskService
    {
        public function __construct(ProjectService $projects, RecordedTimeGuard $guard, private int $failOn)
        {
            parent::__construct($projects, $guard);
        }

        public function complete(Task $task): void
        {
            if ($task->id === $this->failOn) {
                throw new QueryException(config('database.default'), 'update `tasks` set ...', [], new PDOException('Deadlock found when trying to get lock', 40001));
            }

            parent::complete($task);
        }
    });
    Log::shouldReceive('error')->zeroOrMoreTimes()->andReturnUsing(function ($message) use (&$reported) {
        $reported[] = $message;
    });

    $response = bulk($this, $this->manager, 'complete', [$first->id, $doomed->id, $last->id]);

    $response->assertRedirect('/tasks')
        ->assertSessionHas('bulk', [
            'action' => 'complete',
            'succeeded' => [$first->id, $last->id],
            'notPermitted' => [], 'configurationError' => [],
            'failed' => [$doomed->id],
        ])
        ->assertSessionHas('success', '2 tasks completed.')
        ->assertSessionHas('error', '1 task could not be completed.');

    expect($reported)->toHaveCount(1)   // reported for operators, once
        ->and($first->fresh()->column_id)->toBe(doneColumnOf($this->alpha)->id)
        ->and($last->fresh()->column_id)->toBe(doneColumnOf($this->alpha)->id)
        ->and($doomed->fresh()->column_id)->toBe($this->alpha->columns[1]->id)
        ->and(columnOrder(doneColumnOf($this->alpha)))->toBe([$first->id, $last->id])
        // The user sees ids and counts, never the exception text.
        ->and(json_encode(session('bulk')).session('error'))->not->toContain('Deadlock');
});

it('does not swallow programmer errors: only a QueryException becomes `failed`', function () {
    $task = makeTask($this->alpha->columns[1]);
    $this->app->bind(TaskService::class, fn ($app) => new class($app->make(ProjectService::class), $app->make(RecordedTimeGuard::class)) extends TaskService
    {
        public function complete(Task $task): void
        {
            throw new LogicException('bug');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => bulk($this, $this->manager, 'complete', [$task->id]))->toThrow(LogicException::class);
});
