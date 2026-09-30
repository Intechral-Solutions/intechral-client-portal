<?php

/*
 * Child process for TaskCompletionConcurrencyTest (EPIC-014 WP2). The sibling of
 * project_task_worker.php, which stays unchanged (§16.1): it runs Complete/Reopen through
 * TaskService beside plain board moves through ProjectService, at one shared start instant, and
 * prints one outcome per operation as JSON.
 *
 * Usage: php task_completion_worker.php <startAtMicrotime> <operations-json>
 */

use App\Models\Task;
use App\Services\ProjectService;
use App\Services\TaskService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$startAt = (float) ($argv[1] ?? 0);
$operations = json_decode($argv[2] ?? '[]', true, flags: JSON_THROW_ON_ERROR);

while (microtime(true) < $startAt) {
    usleep(100);
}

$tasks = $app->make(TaskService::class);
$projects = $app->make(ProjectService::class);
$outcomes = [];

foreach ($operations as $operation) {
    try {
        match ($operation['op']) {
            'complete' => $tasks->complete(Task::findOrFail($operation['task'])),
            'reopen' => $tasks->reopen(Task::findOrFail($operation['task'])),
            'move' => $projects->moveTask(Task::findOrFail($operation['task']), $operation['column'], $operation['position']),
        };
        $outcomes[] = 'ok';
    } catch (ModelNotFoundException) {
        $outcomes[] = 'not_found';
    } catch (HttpException $e) {
        $outcomes[] = 'http_'.$e->getStatusCode();
    } catch (ValidationException) {
        $outcomes[] = 'invalid';
    } catch (Throwable $e) {
        $outcomes[] = 'error: '.get_class($e).': '.$e->getMessage();
    }
}

echo json_encode($outcomes);
