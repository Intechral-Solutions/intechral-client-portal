<?php

/*
 * Child process for TicketReferenceIssuingTest (C6-R; formerly EPIC-010D H8). Bootstraps the application
 * against the testing database (TestDatabaseSafety refuses anything else), waits for a shared
 * start time so the workers really overlap, then creates tickets through TicketService::create
 * and prints one outcome per attempt as JSON: "ok:<ticket_number>", "duplicate" for a unique-key
 * violation, or "error: ..." for anything else.
 *
 * With TICKET_RACE_SHARED_FIRST set to a canonical reference, the worker's FIRST draw of every create is that one value, so concurrent
 * workers genuinely race for it; later draws are random.
 *
 * Usage: php ticket_number_worker.php <startAtMicrotime> <userId> <count>
 */

use App\Models\User;
use App\Services\TicketService;
use App\Support\Helpdesk\TicketReferenceGenerator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\UniqueConstraintViolationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$startAt = (float) ($argv[1] ?? 0);
$user = User::findOrFail((int) ($argv[2] ?? 0));
$count = (int) ($argv[3] ?? 1);

while (microtime(true) < $startAt) {
    usleep(100);
}

$shared = getenv('TICKET_RACE_SHARED_FIRST') ?: null;

if ($shared !== null) {
    $app->instance(TicketReferenceGenerator::class, new class($shared) extends TicketReferenceGenerator
    {
        private bool $first = true;

        public function __construct(private string $shared) {}

        public function next(): string
        {
            // The first draw of each create(): after a collision the next draw is random.
            if ($this->first) {
                $this->first = false;

                return $this->shared;
            }

            return parent::next();
        }

        public function startCreate(): void
        {
            $this->first = true;
        }
    });
}

$service = $app->make(TicketService::class);
$outcomes = [];

for ($i = 0; $i < $count; $i++) {
    if ($shared !== null) {
        $app->make(TicketReferenceGenerator::class)->startCreate();
    }

    try {
        $ticket = $service->create($user, [
            'title' => 'H8 race probe',
            'description' => 'Concurrency probe fixture',
            'category' => 'General',
            'priority' => 'low',
        ]);
        $outcomes[] = 'ok:'.$ticket->ticket_number;
    } catch (UniqueConstraintViolationException) {
        $outcomes[] = 'duplicate';
    } catch (Throwable $e) {
        $outcomes[] = 'error: '.get_class($e).': '.$e->getMessage();
    }
}

echo json_encode($outcomes);
