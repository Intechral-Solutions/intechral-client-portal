<?php

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use App\Services\TicketService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

require_once __DIR__.'/TicketTestHelpers.php';

/*
 * EPIC-010D WP0 characterization: H8 (Ticket number generation).
 *
 * TicketService::create computes 'TKT-' . str_pad(MAX(id) + 1, 4, '0') and then inserts, with no
 * transaction or lock; tickets.ticket_number is UNIQUE. Two creates that read the same MAX(id)
 * therefore compute the same number and the second insert fails. The deterministic tests below
 * reproduce that interleaving exactly; the probe at the end races real processes on the testing
 * MariaDB database.
 *
 * Prefixes: BASELINE (keep), DEFECT H8 (CURRENT BROKEN behavior, WP2 flips it),
 * CHARACTERIZATION (current fact recorded for WP2's design).
 */

function ticketCreatePayload(string $title = 'Numbered'): array
{
    return ['title' => $title, 'description' => 'x', 'category' => 'General', 'priority' => 'low'];
}

function ticketNextNumber(): string
{
    $method = new ReflectionMethod(TicketService::class, 'nextTicketNumber');

    return $method->invoke(app(TicketService::class));
}

it('BASELINE H8: a service-created number is TKT- plus MAX(id)+1, zero-padded to at least four digits', function () {
    Notification::fake();
    $user = User::factory()->create();
    $maxBefore = (int) Ticket::max('id');

    $ticket = app(TicketService::class)->create($user, ticketCreatePayload());

    expect($ticket->ticket_number)->toBe('TKT-'.str_pad((string) ($maxBefore + 1), 4, '0', STR_PAD_LEFT))
        ->and($ticket->ticket_number)->toMatch('/^TKT-\d{4,}$/');
});

it('CHARACTERIZATION H8: the number is derived from the highest surviving id, not the new row id, so it drifts from the id and can re-issue a deleted ticket\'s number', function () {
    Notification::fake();
    $user = User::factory()->create();
    $service = app(TicketService::class);

    $service->create($user, ticketCreatePayload('first'));
    $highest = $service->create($user, ticketCreatePayload('highest'));
    $reissuedNumber = $highest->ticket_number;

    // No application route deletes tickets (or users); the cascade from a user delete is the only
    // path, so this is reachable only outside the app today.
    $highest->delete();
    $next = $service->create($user, ticketCreatePayload('next'));

    expect($next->ticket_number)->toBe($reissuedNumber)
        ->and($next->id)->toBeGreaterThan($highest->id);
});

it('DEFECT H8 (WP2 flips to distinct numbers): two creates that read MAX(id) before either inserts compute the same number', function () {
    // Nothing reserves the number between computing it and inserting the row.
    expect(ticketNextNumber())->toBe(ticketNextNumber());
});

it('DEFECT H8 (WP2 flips to success): when the computed number is already taken, create throws a unique violation and persists nothing, stores no file and sends no mail', function () {
    Notification::fake();
    Storage::fake('local');
    $user = User::factory()->create();

    // Stand-in for the losing side of the race: the winner already holds MAX(id)+1.
    $winner = Ticket::factory()->for($user, 'user')->create();
    $winner->update(['ticket_number' => ticketNextNumber()]);
    $countBefore = Ticket::count();

    expect(fn () => app(TicketService::class)->create($user, ticketCreatePayload(), [UploadedFile::fake()->create('a.pdf', 1, 'application/pdf')]))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(Ticket::count())->toBe($countBefore)
        ->and(TicketAttachment::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Notification::assertNothingSent();

    ticketCleanFakeStorage();
});

it('DEFECT H8 (WP2 flips to a redirect): over HTTP the losing create is a 500 after the customer submitted the form', function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    $customer = ticketUser();
    $winner = Ticket::factory()->for(ticketUser(), 'user')->create();
    $winner->update(['ticket_number' => ticketNextNumber()]);

    $this->actingAs($customer)->post(route('tickets.store'), ticketCreatePayload('Lost the race'))
        ->assertServerError();

    expect(Ticket::where('title', 'Lost the race')->exists())->toBeFalse();
    Notification::assertNotSentTo($customer, TicketCreatedNotification::class);
});

it('CHARACTERIZATION H8: factory numbers share the service namespace (TKT-0001..TKT-9999), so a factory row can pre-empt a service number in tests', function () {
    $numbers = Ticket::factory()->count(25)->make()->pluck('ticket_number');

    expect($numbers->every(fn ($n) => preg_match('/^TKT-\d{4}$/', $n) === 1))->toBeTrue();
});

/*
 * Real concurrency probe. RefreshDatabase wraps each test in a transaction other connections
 * cannot see, so this test ends it, commits one user, races worker processes that each create
 * tickets through TicketService, and deletes the user (tickets cascade) in a finally block.
 * Workers run with APP_ENV=testing (TestDatabaseSafety) and MAIL_MAILER=array.
 *
 * The assertions hold today and after WP2: every attempt either succeeds or loses with a unique
 * violation (never another error), and committed numbers are distinct. WP2 tightens this to
 * "every attempt succeeds". TICKET_RACE_WORKERS / TICKET_RACE_CREATES turn the dial up for a
 * one-off soak; TICKET_RACE_REPORT=1 prints the tally to STDERR.
 */
it('CHARACTERIZATION H8: parallel creates on MariaDB either succeed with distinct numbers or lose with a unique violation', function () {
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

        expect($errors->values()->all())->toBe([])
            ->and($outcomes)->toHaveCount($workers * $perWorker)
            ->and($committed)->toHaveCount($ok->count())
            ->and($committed->unique())->toHaveCount($committed->count());
    } finally {
        User::whereKey($user->id)->delete();
    }

    expect(Ticket::where('user_id', $user->id)->exists())->toBeFalse();
});
