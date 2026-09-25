<?php

/*
 * Child process for ProjectMoveConcurrencyTest. Bootstraps the application against the testing
 * database, waits for a shared start time so the workers really overlap, then runs a list of
 * task operations through ProjectService and prints one outcome per operation as JSON.
 *
 * Usage: php project_task_worker.php <startAtMicrotime> <operations-json>
 */

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectService;
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

$service = $app->make(ProjectService::class);
$outcomes = [];

foreach ($operations as $operation) {
    try {
        match ($operation['op']) {
            'move' => $service->moveTask(Task::findOrFail($operation['task']), $operation['column'], $operation['position']),
            'create' => $service->createTask(
                Project::findOrFail($operation['project']),
                User::findOrFail($operation['user']),
                ['column_id' => $operation['column'], 'title' => $operation['title'], 'priority' => 'low'],
            ),
            'delete' => $service->deleteTask(Task::findOrFail($operation['task'])),
        };
        $outcomes[] = 'ok';
    } catch (ModelNotFoundException) {
        $outcomes[] = 'not_found';
    } catch (HttpException $e) {
        $outcomes[] = $e->getStatusCode() === 404 ? 'not_found' : 'http_'.$e->getStatusCode();
    } catch (ValidationException) {
        $outcomes[] = 'invalid';
    } catch (Throwable $e) {
        $outcomes[] = 'error: '.get_class($e).': '.$e->getMessage();
    }
}

echo json_encode($outcomes);
