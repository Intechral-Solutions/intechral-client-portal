<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-014 §8 / INV-3 / INV-4 (WP2): Complete and Reopen inherit moveTask's locking and restart
 * protocol, so racing them against each other and against plain moves, in separate PHP processes
 * on real MariaDB, keeps every column dense and loses or duplicates no card. The idempotence
 * decision ("already done" / "already open") is taken under those same locks.
 *
 * As in ProjectMoveConcurrencyTest, the RefreshDatabase transaction is ended so the workers see
 * committed rows, and everything created here is deleted in a finally block.
 */

/** @return array<int, array<int, string>> outcomes per worker */
function runCompletionWorkers(array $workloads): array
{
    $connection = config('database.connections.'.config('database.default'));
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => $connection['host'],
        'DB_PORT' => $connection['port'],
        'DB_DATABASE' => $connection['database'],
        'DB_USERNAME' => $connection['username'],
        'DB_PASSWORD' => $connection['password'],
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
    ];
    $startAt = microtime(true) + 2.5;

    $processes = array_map(function (array $operations) use ($env, $startAt) {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/task_completion_worker.php'), (string) $startAt, json_encode($operations)],
            base_path(),
            $env,
        );
        $process->setTimeout(120);
        $process->start();

        return $process;
    }, $workloads);

    return array_map(function (Process $process) {
        $process->wait();
        $decoded = json_decode(trim($process->getOutput()), true);

        return is_array($decoded) ? $decoded : ['worker failed: '.$process->getErrorOutput().$process->getOutput()];
    }, $processes);
}

it('keeps every column dense and lossless while Complete, Reopen and moves race', function () {
    DB::commit(); // end the RefreshDatabase transaction so the workers see our rows

    $creator = User::factory()->create();
    $project = app(ProjectService::class)->create($creator, ['name' => 'Completion race '.uniqid()]);
    $columns = $project->columns()->orderBy('position')->get();
    $done = $columns->firstWhere('is_done_column', true);
    $tasks = [];
    foreach ([$columns[1], $columns[2], $done] as $column) {
        for ($i = 0; $i < 6; $i++) {
            $tasks[] = makeTask($column, ['position' => $i]);
        }
    }

    try {
        $ids = array_map(fn (Task $t) => $t->id, $tasks);
        $workloads = [];
        for ($worker = 0; $worker < 6; $worker++) {
            $ops = [];
            foreach ($ids as $index => $id) {
                $ops[] = match (($index + $worker) % 3) {
                    0 => ['op' => 'complete', 'task' => $id],
                    1 => ['op' => 'reopen', 'task' => $id],
                    2 => ['op' => 'move', 'task' => $id, 'column' => $columns[($index + $worker) % 4]->id, 'position' => $worker],
                };
            }
            $workloads[] = $worker % 2 === 0 ? $ops : array_reverse($ops);
        }

        $outcomes = runCompletionWorkers($workloads);

        foreach ($outcomes as $worker => $results) {
            expect(array_values(array_unique($results)))->toBe(['ok'], "worker {$worker}: ".json_encode(array_unique($results)));
        }

        $total = 0;
        foreach ($project->columns()->get() as $column) {
            $positions = array_values(columnPositions($column));
            expect($positions)->toBe($positions === [] ? [] : range(0, count($positions) - 1), "column {$column->name} must be dense");
            $total += count($positions);
        }
        expect($total)->toBe(count($tasks), 'no card lost or duplicated')
            ->and(Task::whereKey($ids)->pluck('status')->unique()->values()->all())->toBe(['todo']);   // INV-2
    } finally {
        Project::whereKey($project->id)->delete();
        User::whereKey($creator->id)->delete();
    }
});

it('leaves a task that is completed by many racing requests exactly once at the Done tail', function () {
    DB::commit();

    $creator = User::factory()->create();
    $project = app(ProjectService::class)->create($creator, ['name' => 'Complete race '.uniqid()]);
    $columns = $project->columns()->orderBy('position')->get();
    $done = $columns->firstWhere('is_done_column', true);
    $already = [makeTask($done, ['position' => 0]), makeTask($done, ['position' => 1])];
    $task = makeTask($columns[1], ['position' => 0]);

    try {
        $outcomes = runCompletionWorkers(array_fill(0, 6, [['op' => 'complete', 'task' => $task->id]]));

        foreach ($outcomes as $worker => $results) {
            expect($results)->toBe(['ok'], "worker {$worker}");
        }
        // The first Complete appended it; every later one saw it already done, under the lock,
        // and did not move it again.
        expect(columnOrder($done))->toBe([$already[0]->id, $already[1]->id, $task->id]);
    } finally {
        Project::whereKey($project->id)->delete();
        User::whereKey($creator->id)->delete();
    }
});

it('never reorders a task that is already Done while other tasks are being appended around it', function () {
    DB::commit();

    $creator = User::factory()->create();
    $project = app(ProjectService::class)->create($creator, ['name' => 'Done head race '.uniqid()]);
    $columns = $project->columns()->orderBy('position')->get();
    $done = $columns->firstWhere('is_done_column', true);
    // The target sits at the HEAD of Done with two tasks behind it. Re-appending it, even once,
    // would put it behind both of them and behind everything appended meanwhile.
    $head = makeTask($done, ['position' => 0]);
    $behind = [makeTask($done, ['position' => 1]), makeTask($done, ['position' => 2])];
    $incoming = [];
    for ($i = 0; $i < 6; $i++) {
        $incoming[] = makeTask($columns[1], ['position' => $i]);
    }

    try {
        // Every worker Completes the already-Done head task three times, between Completes of
        // the tasks that really do move into Done, so its decision is taken while Done's tail
        // is changing under it.
        $workloads = [];
        foreach ($incoming as $worker => $task) {
            $workloads[] = [
                ['op' => 'complete', 'task' => $head->id],
                ['op' => 'complete', 'task' => $task->id],
                ['op' => 'complete', 'task' => $head->id],
                ['op' => 'complete', 'task' => $head->id],
            ];
        }

        foreach (runCompletionWorkers($workloads) as $worker => $results) {
            expect(array_values(array_unique($results)))->toBe(['ok'], "worker {$worker}");
        }

        $order = columnOrder($done);
        $incomingIds = array_map(fn (Task $t) => $t->id, $incoming);

        expect(array_slice($order, 0, 3))->toBe([$head->id, $behind[0]->id, $behind[1]->id])   // never reordered
            ->and(array_values(array_diff($order, [$head->id, $behind[0]->id, $behind[1]->id])))->toEqualCanonicalizing($incomingIds)
            ->and(columnIsDense($done))->toBeTrue()
            ->and(columnOrder($columns[1]))->toBe([]);
    } finally {
        Project::whereKey($project->id)->delete();
        User::whereKey($creator->id)->delete();
    }
});
