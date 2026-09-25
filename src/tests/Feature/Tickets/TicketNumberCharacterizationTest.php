<?php

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use App\Services\TicketService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

require_once __DIR__.'/TicketTestHelpers.php';

/*
 * EPIC-010D: H8 (Ticket number generation). WP0 proved the old algorithm, 'TKT-' . (MAX(id) + 1)
 * computed before the insert, lets parallel creates compute the same number (about half of 400
 * attempts lost to the UNIQUE index at 8 workers) and re-issues a deleted highest ticket's number.
 * WP2 converted every defect test into the TARGET contract (pre-fix evidence: EPIC-010D
 * Amendment 1).
 *
 * Target: TicketService::create reserves the row identity first, inside one transaction (insert
 * with a unique temporary value, then set ticket_number to 'TKT-' . id zero-padded to at least four
 * digits), so the number derives from the database-reserved auto-increment id and never from a
 * scan. Historical numbers, including non-numeric fixtures such as TKT-E2E1, are never rewritten.
 *
 * Prefixes: BASELINE (already correct, keep), TARGET (the WP2 contract), CHARACTERIZATION
 * (a recorded fact).
 */

function ticketCreatePayload(string $title = 'Numbered'): array
{
    return ['title' => $title, 'description' => 'x', 'category' => 'General', 'priority' => 'low'];
}

function ticketExpectedNumber(int $id): string
{
    return 'TKT-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}

it('TARGET H8: a created ticket is numbered from its own reserved id, zero-padded to at least four digits', function () {
    Notification::fake();
    $user = User::factory()->create();
    $service = app(TicketService::class);

    $tickets = collect(range(1, 3))->map(fn ($i) => $service->create($user, ticketCreatePayload("t{$i}")));

    foreach ($tickets as $ticket) {
        expect($ticket->ticket_number)->toBe(ticketExpectedNumber($ticket->id))
            ->and($ticket->fresh()->ticket_number)->toBe($ticket->ticket_number)
            ->and($ticket->ticket_number)->toMatch('/^TKT-\d{4,}$/');
    }
    expect($tickets->pluck('ticket_number')->unique())->toHaveCount(3);
});

it('TARGET H8: the number does not depend on which tickets exist, including non-numeric and low-numbered historical numbers', function () {
    Notification::fake();
    $user = User::factory()->create();
    Ticket::factory()->for($user, 'user')->create(['ticket_number' => 'TKT-E2E1']);
    Ticket::factory()->for($user, 'user')->create(['ticket_number' => 'TKT-0002']);
    // A gap in the ids (a reserved id whose row is gone) must not shift the next number either.
    Ticket::factory()->for($user, 'user')->create()->delete();
    $before = Ticket::orderBy('id')->pluck('ticket_number', 'id')->all();

    $ticket = app(TicketService::class)->create($user, ticketCreatePayload());

    expect($ticket->ticket_number)->toBe(ticketExpectedNumber($ticket->id))
        ->and(Ticket::whereKeyNot($ticket->id)->orderBy('id')->pluck('ticket_number', 'id')->all())->toBe($before);
});

it('TARGET H8: deleting the highest ticket never lets a later create re-issue its number', function () {
    Notification::fake();
    $user = User::factory()->create();
    $service = app(TicketService::class);

    $service->create($user, ticketCreatePayload('first'));
    $highest = $service->create($user, ticketCreatePayload('highest'));

    // No application route deletes tickets (or users); the cascade from a user delete is the only
    // path, so this is a direct test-database operation.
    $highest->delete();
    $next = $service->create($user, ticketCreatePayload('next'));

    expect($next->id)->toBeGreaterThan($highest->id)
        ->and($next->ticket_number)->not->toBe($highest->ticket_number)
        ->and($next->ticket_number)->toBe(ticketExpectedNumber($next->id));
});

it('TARGET H8: a failure while finalizing the number rolls the ticket back; no row, temporary number, file or mail survives', function () {
    Notification::fake();
    Storage::fake('local');
    $user = User::factory()->create();
    $countBefore = Ticket::count();

    Ticket::updating(function () {
        throw new RuntimeException('simulated failure while numbering');
    });

    expect(fn () => app(TicketService::class)->create($user, ticketCreatePayload(), [UploadedFile::fake()->create('a.pdf', 1, 'application/pdf')]))
        ->toThrow(RuntimeException::class, 'simulated failure while numbering');

    Ticket::flushEventListeners();
    expect(Ticket::count())->toBe($countBefore)
        ->and(Ticket::where('ticket_number', 'like', 'TMP-%')->count())->toBe(0)
        ->and(TicketAttachment::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Notification::assertNothingSent();

    ticketCleanFakeStorage();
});

it('TARGET H8: over HTTP a customer submission always redirects to the final numbered ticket', function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    $customer = ticketUser();

    $this->actingAs($customer)->post(route('tickets.store'), ticketCreatePayload('Over HTTP'))->assertRedirect();

    $ticket = Ticket::where('title', 'Over HTTP')->sole();
    expect($ticket->ticket_number)->toBe(ticketExpectedNumber($ticket->id))
        ->and($ticket->ticket_number)->not->toStartWith('TMP-');
    Notification::assertSentTo($customer, TicketCreatedNotification::class,
        fn (TicketCreatedNotification $n) => $n->ticket->ticket_number === $ticket->ticket_number);
});

it('TARGET H8: factory numbers live outside the service namespace, so they can never collide with a created ticket', function () {
    Notification::fake();
    $user = User::factory()->create();

    $numbers = Ticket::factory()->count(40)->for($user, 'user')->create()->pluck('ticket_number');

    expect($numbers->unique())->toHaveCount(40)
        ->and($numbers->contains(fn ($n) => preg_match('/^TKT-\d+$/', $n) === 1))->toBeFalse()
        ->and($numbers->every(fn ($n) => preg_match('/^TKT-F\d{6}$/', $n) === 1))->toBeTrue();

    $ticket = app(TicketService::class)->create($user, ticketCreatePayload());
    expect($ticket->ticket_number)->toBe(ticketExpectedNumber($ticket->id));
});

/*
 * Real concurrency probe. RefreshDatabase wraps each test in a transaction other connections
 * cannot see, so this test ends it, commits one user, races worker processes that each create
 * tickets through TicketService, and deletes the user (tickets cascade) in a finally block.
 * Workers run with APP_ENV=testing (TestDatabaseSafety) and MAIL_MAILER=array.
 *
 * Target: EVERY valid create succeeds and every saved number is unique and equals TKT-{id}.
 * TICKET_RACE_WORKERS / TICKET_RACE_CREATES turn the dial up for a one-off soak;
 * TICKET_RACE_REPORT=1 prints the tally to STDERR.
 */
it('TARGET H8: parallel creates on MariaDB all succeed with unique id-derived numbers', function () {
    DB::commit();
    $user = User::factory()->create();

    $workers = (int) (getenv('TICKET_RACE_WORKERS') ?: 4);
    $perWorker = (int) (getenv('TICKET_RACE_CREATES') ?: 10);

    try {
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
            'MAIL_MAILER' => 'array',
        ];
        $startAt = microtime(true) + 2.5;

        $processes = [];
        for ($w = 0; $w < $workers; $w++) {
            $process = new Process(
                [PHP_BINARY, base_path('tests/Support/ticket_number_worker.php'), (string) $startAt, (string) $user->id, (string) $perWorker],
                base_path(),
                $env,
            );
            $process->setTimeout(120);
            $process->start();
            $processes[] = $process;
        }

        $outcomes = collect($processes)->flatMap(function (Process $process) {
            $process->wait();
            $decoded = json_decode(trim($process->getOutput()), true);

            return is_array($decoded) ? $decoded : ['error: worker failed: '.$process->getErrorOutput().$process->getOutput()];
        });

        $ok = $outcomes->filter(fn ($o) => str_starts_with($o, 'ok:'));
        $duplicates = $outcomes->filter(fn ($o) => $o === 'duplicate');
        $errors = $outcomes->reject(fn ($o) => str_starts_with($o, 'ok:') || $o === 'duplicate');
        $committed = Ticket::where('user_id', $user->id)->pluck('ticket_number');

        if (getenv('TICKET_RACE_REPORT')) {
            fwrite(STDERR, sprintf("\n[H8 probe] workers=%d creates/worker=%d attempts=%d ok=%d duplicate=%d error=%d\n",
                $workers, $perWorker, $outcomes->count(), $ok->count(), $duplicates->count(), $errors->count()));
        }

        $tickets = Ticket::where('user_id', $user->id)->get(['id', 'ticket_number']);

        expect($errors->values()->all())->toBe([])
            ->and($duplicates->all())->toBe([])
            ->and($ok)->toHaveCount($workers * $perWorker)
            ->and($committed)->toHaveCount($workers * $perWorker)
            ->and($committed->unique())->toHaveCount($committed->count())
            ->and($ok->map(fn ($o) => substr($o, 3))->unique())->toHaveCount($ok->count())
            ->and($tickets->every(fn ($t) => $t->ticket_number === ticketExpectedNumber($t->id)))->toBeTrue();
    } finally {
        User::whereKey($user->id)->delete();
    }

    expect(Ticket::where('user_id', $user->id)->exists())->toBeFalse();
});
