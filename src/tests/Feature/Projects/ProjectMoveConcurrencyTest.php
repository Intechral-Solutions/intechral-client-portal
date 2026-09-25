<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-011E I1/I2 (§16, §23): concurrent moves, creates and deletes on real MariaDB.
 *
 * RefreshDatabase wraps every test in a transaction that other connections cannot see, so this
 * file ends that transaction, writes committed rows, runs the operations in separate PHP
 * processes and deletes what it created in a finally block (the testing database is safe to
 * write to, see TestDatabaseSafety, but later tests must still start from a clean slate).
 */

/**
 * Run each operation list in its own PHP process at one shared start instant.
 *
 * @param  array<int, array<int, array<string, mixed>>>  $workloads
 * @return array<int, array<int, string>> outcomes per worker
 */
function runWorkers(array $workloads): array
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
            [PHP_BINARY, base_path('tests/Support/project_task_worker.php'), (string) $startAt, json_encode($operations)],
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

/** A committed project with two columns of tasks; returns the pieces and a cleanup closure. */
function committedProject(int $perColumn): array
{
    DB::commit(); // end the RefreshDatabase transaction so other processes see our rows

    $creator = User::factory()->create();
    $project = app(ProjectService::class)->create($creator, ['name' => 'Concurrency '.uniqid()]);
    [$a, $b] = [$project->columns[1], $project->columns[2]];
    $tasks = [];
    foreach ([$a, $b] as $column) {
        for ($i = 0; $i < $perColumn; $i++) {
            $tasks[] = makeTask($column, ['position' => $i, 'title' => "{$column->name} {$i}"]);
        }
    }

    return [$creator, $project, $a, $b, $tasks, function () use ($project, $creator) {
        Project::whereKey($project->id)->delete();
        User::whereKey($creator->id)->delete();
    }];
}

function assertProjectConsistent(Project $project, int $expectedTasks): void
{
    $columns = $project->columns()->get();
    $total = 0;
    foreach ($columns as $column) {
        $positions = array_values(columnPositions($column));
        expect($positions)->toBe($positions === [] ? [] : range(0, count($positions) - 1), "column {$column->name} must be dense and duplicate-free");
        $total += count($positions);
    }
    expect($total)->toBe($expectedTasks, 'no card lost or duplicated');
}

it('keeps two columns dense and lossless under parallel cross-column and within-column moves', function () {
    [$creator, $project, $a, $b, $tasks, $cleanup] = committedProject(6);

    // PROJECT_STRESS_* let a developer turn the dial up for a one-off soak; the defaults keep the
    // suite fast and deterministic.
    $workers = (int) (getenv('PROJECT_STRESS_WORKERS') ?: 6);
    $movesPerWorker = (int) (getenv('PROJECT_STRESS_MOVES') ?: 25);

    try {
        mt_srand((int) (getenv('PROJECT_STRESS_SEED') ?: 20260921));
        $ids = array_map(fn (Task $t) => $t->id, $tasks);
        $workloads = [];
        for ($worker = 0; $worker < $workers; $worker++) {
            $moves = [];
            for ($i = 0; $i < $movesPerWorker; $i++) {
                $moves[] = [
                    'op' => 'move',
                    'task' => $ids[mt_rand(0, count($ids) - 1)],
                    'column' => mt_rand(0, 1) ? $a->id : $b->id,
                    'position' => mt_rand(0, 8),
                ];
            }
            $workloads[] = $moves;
        }

        $outcomes = runWorkers($workloads);

        foreach ($outcomes as $worker => $results) {
            expect(array_values(array_unique($results)))->toBe(['ok'], "worker {$worker}: ".json_encode(array_unique($results)));
        }
        assertProjectConsistent($project, count($ids));
    } finally {
        $cleanup();
    }
});

it('survives creates racing with moves whose index went stale', function () {
    [$creator, $project, $a, $b, $tasks, $cleanup] = committedProject(4);

    try {
        $creates = [];
        for ($i = 0; $i < 12; $i++) {
            $creates[] = ['op' => 'create', 'project' => $project->id, 'user' => $creator->id, 'column' => $a->id, 'title' => "Created {$i}"];
        }
        $moves = [];
        foreach ($tasks as $i => $task) {
            // Indexes computed against the original board: stale as soon as a create lands.
            $moves[] = ['op' => 'move', 'task' => $task->id, 'column' => $a->id, 'position' => 4 + ($i % 3)];
            $moves[] = ['op' => 'move', 'task' => $task->id, 'column' => $b->id, 'position' => 0];
        }

        $outcomes = runWorkers([$creates, array_slice($creates, 0, 6), $moves, array_reverse($moves)]);

        foreach ($outcomes as $worker => $results) {
            expect(array_values(array_unique($results)))->toBe(['ok'], "worker {$worker}: ".json_encode(array_unique($results)));
        }
        assertProjectConsistent($project, count($tasks) + 12 + 6);
    } finally {
        $cleanup();
    }
});

it('answers a move of a task deleted mid-flight with a clean not-found and keeps the board dense', function () {
    [$creator, $project, $a, $b, $tasks, $cleanup] = committedProject(5);

    try {
        $doomed = array_slice($tasks, 0, 5);
        $moves = [];
        foreach ($doomed as $task) {
            $moves[] = ['op' => 'move', 'task' => $task->id, 'column' => $b->id, 'position' => 0];
        }
        $deletes = array_map(fn (Task $task) => ['op' => 'delete', 'task' => $task->id], $doomed);

        $outcomes = runWorkers([$moves, $deletes, array_reverse($moves)]);

        foreach ($outcomes as $worker => $results) {
            foreach ($results as $result) {
                expect(in_array($result, ['ok', 'not_found'], true))->toBeTrue("worker {$worker}: unexpected outcome {$result}");
            }
        }
        expect(Task::whereIn('id', array_map(fn ($t) => $t->id, $doomed))->count())->toBe(0);
        assertProjectConsistent($project, count($tasks) - 5);
    } finally {
        $cleanup();
    }
});
