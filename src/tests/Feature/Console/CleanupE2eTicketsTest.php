<?php

/*
 * EPIC-017 WP2 G3-A — `e2e:cleanup-tickets` (§25.3, the locked E2E ticket cleanup).
 *
 * The command deletes rows and private files, so these tests pin what it must NOT touch as hard as what
 * it removes: a non-`E2E-` title, an `E2E-` title owned by anyone but a seeded E2E customer, the seeded
 * fixtures, and any ticket with linked time or tasks (refused, never detached). Every file lives on
 * Storage::fake('local'); the database is the isolated testing database (RefreshDatabase). Nothing here
 * can reach development data. The real two-connection lock tests are in CleanupE2eTicketsConcurrencyTest.
 */

use App\Console\Commands\CleanupE2eTickets;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketStatusHistory;
use App\Models\TimeEntry;
use App\Models\User;
use Database\Seeders\DevSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Storage::fake('local');
});

afterEach(function () {
    e2eRestorePermissions();
    ticketCleanFakeStorage();
});

/** Directories a test made unreadable, restored by afterEach so the fake disk can always be wiped. */
function e2eRestricted(?string $path = null): array
{
    static $paths = [];

    if ($path !== null) {
        $paths[] = $path;
    }

    return $paths;
}

/**
 * Really remove a directory's permissions (not a mock): what the web-server user's 0700 directory looks like to a
 * process that is not the owner. Root ignores permissions, so the test is skipped there.
 */
function e2eRestrict(string $relative, int $mode = 0000): void
{
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        test()->markTestSkipped('root ignores file permissions');
    }

    $path = rtrim(Storage::disk('local')->path($relative), '/');
    e2eRestricted($path);
    chmod($path, $mode);
}

function e2eRestorePermissions(): void
{
    foreach (array_reverse(e2eRestricted()) as $path) {
        if (is_dir($path)) {
            @chmod($path, 0755);
        }
    }
}

function e2eCustomer(string $email = 'e2e-customer@intechral.test'): User
{
    return User::factory()->create(['email' => $email])->assignRole('user');
}

function e2eTicket(User $owner, string $title = 'E2E-run1 a request'): Ticket
{
    return ticketFor($owner, ['title' => $title]);
}

/** Bytes on the faked disk where TicketService puts them, plus the row that names them. */
function e2eAttachment(Ticket $ticket, ?User $uploader = null, string $name = 'a.txt'): string
{
    return ticketAttachment($ticket, null, $uploader ?? $ticket->user, $name, 'private bytes')->path;
}

function e2eCleanup()
{
    return test()->artisan('e2e:cleanup-tickets');
}

it('refuses production before reading or changing anything', function () {
    $ticket = e2eTicket(e2eCustomer());
    $path = e2eAttachment($ticket);
    app()->detectEnvironment(fn () => 'production');
    DB::flushQueryLog();
    DB::enableQueryLog();

    e2eCleanup()->assertFailed()->expectsOutputToContain('Refusing to run in the "production" environment');

    expect(DB::getQueryLog())->toBe([])
        ->and(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('refuses an environment outside local and testing', function (string $environment) {
    $ticket = e2eTicket(e2eCustomer());
    app()->detectEnvironment(fn () => $environment);

    e2eCleanup()->assertFailed()->expectsOutputToContain("Refusing to run in the \"{$environment}\" environment");

    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue();
})->with(['staging', 'prod', 'development', 'ci']);

it('runs in both permitted environments', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);

    e2eCleanup()->assertSuccessful();
})->with(['local', 'testing']);

it('declares no argument and no option, so nothing can select tickets, users or patterns', function () {
    $definition = Artisan::all()['e2e:cleanup-tickets']->getDefinition();

    expect($definition->getArguments())->toBe([])
        ->and($definition->getOptions())->toBe([]);

    // And a caller who passes one anyway is rejected before the command runs.
    $ticket = e2eTicket(e2eCustomer());
    $rejected = 0;

    foreach ([['ticket' => $ticket->id], ['--id' => $ticket->id], ['--user' => 1], ['--title' => 'E2E-']] as $parameters) {
        try {
            Artisan::call('e2e:cleanup-tickets', $parameters);
        } catch (ExceptionInterface) {
            $rejected++;
        }
    }

    expect($rejected)->toBe(4)
        ->and(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue();
});

it('deletes an eligible ticket, its attachment files and, by cascade, its replies, attachments and history', function () {
    $owner = e2eCustomer();
    $operator = ticketUser('operator');
    $ticket = e2eTicket($owner);
    $reply = ticketReply($ticket, $operator, false);
    $note = ticketReply($ticket, $operator, true, 'internal');
    $path = e2eAttachment($ticket);
    $replyPath = ticketAttachment($ticket, $reply, $operator, 'r.txt', 'reply bytes')->path;
    $history = TicketStatusHistory::query()->create([
        'ticket_id' => $ticket->id, 'user_id' => $operator->id, 'old_status' => 'open', 'new_status' => 'pending_user', 'created_at' => now(),
    ]);

    e2eCleanup()
        ->assertSuccessful()
        ->expectsOutputToContain('Eligible E2E tickets: 1')
        ->expectsOutputToContain('Tickets deleted: 1')
        ->expectsOutputToContain('Attachment files removed: 2 (in 1 directory)');

    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeFalse()
        ->and(DB::table('ticket_replies')->where('ticket_id', $ticket->id)->count())->toBe(0)
        ->and(TicketAttachment::query()->where('ticket_id', $ticket->id)->count())->toBe(0)
        ->and(TicketStatusHistory::query()->whereKey($history->id)->exists())->toBeFalse()
        ->and($note->exists)->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(Storage::disk('local')->exists($replyPath))->toBeFalse()
        ->and(Storage::disk('local')->directoryExists("tickets/{$ticket->id}"))->toBeFalse();
});

it('removes only the eligible ticket\'s own directory, never a neighbour\'s files', function () {
    $owner = e2eCustomer();
    $doomed = e2eTicket($owner);
    $kept = ticketFor($owner, ['title' => 'A real request']);
    e2eAttachment($doomed);
    $keptPath = e2eAttachment($kept);
    Storage::disk('local')->put('tickets/not-a-ticket-dir/x.txt', 'x');
    Storage::disk('local')->put('elsewhere/y.txt', 'y');

    e2eCleanup()->assertSuccessful();

    expect(Storage::disk('local')->exists($keptPath))->toBeTrue()
        ->and(Storage::disk('local')->exists('tickets/not-a-ticket-dir/x.txt'))->toBeTrue()
        ->and(Storage::disk('local')->exists('elsewhere/y.txt'))->toBeTrue();
});

it('preserves a ticket whose title does not begin with the literal E2E- prefix', function (string $title) {
    $ticket = e2eTicket(e2eCustomer(), $title);
    $path = e2eAttachment($ticket);

    e2eCleanup()->assertSuccessful()->expectsOutputToContain('Nothing to remove.');

    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
})->with([
    'an ordinary title' => 'Printer is on fire',
    'the prefix lower-cased' => 'e2e-run1 a request',
    'the prefix mid-title' => 'See E2E-run1 for details',
    'a space instead of the hyphen' => 'E2E run1 a request',
    'the seeded fixture wording' => 'E2E fixture: waiting on the customer',
    'a leading space' => ' E2E-run1 a request',
]);

it('preserves an E2E- ticket that belongs to anyone but a seeded E2E customer', function () {
    $stranger = User::factory()->create(['email' => 'someone@example.com'])->assignRole('user');
    $operator = ticketUser('operator', ['email' => 'operator@intechral.test']);
    $lookalike = User::factory()->create(['email' => 'e2e-customer@intechral.test.evil.example'])->assignRole('user');
    $tickets = [e2eTicket($stranger), e2eTicket($operator), e2eTicket($lookalike)];
    $paths = array_map(fn (Ticket $ticket) => e2eAttachment($ticket), $tickets);

    e2eCleanup()->assertSuccessful()->expectsOutputToContain('Nothing to remove.');

    foreach ($tickets as $index => $ticket) {
        expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue()
            ->and(Storage::disk('local')->exists($paths[$index]))->toBeTrue();
    }
});

it('treats both seeded E2E customers as owners, and nobody else', function () {
    $first = e2eTicket(e2eCustomer('e2e-customer@intechral.test'), 'E2E-a first');
    $second = e2eTicket(e2eCustomer('e2e-customer-other@intechral.test'), 'E2E-b second');
    $third = e2eTicket(e2eCustomer('e2e-operational-member@intechral.test'), 'E2E-c third');

    e2eCleanup()->assertSuccessful()->expectsOutputToContain('Tickets deleted: 2');

    expect(Ticket::query()->whereKey($first->id)->exists())->toBeFalse()
        ->and(Ticket::query()->whereKey($second->id)->exists())->toBeFalse()
        ->and(Ticket::query()->whereKey($third->id)->exists())->toBeTrue();
});

it('keeps the owners the command trusts equal to the identities DevSeeder seeds', function () {
    $this->seed(DevSeeder::class);

    foreach (CleanupE2eTickets::OWNER_EMAILS as $email) {
        expect(User::query()->where('email', $email)->exists(), $email)->toBeTrue();
    }
});

it('never deletes the seeded fixture tickets TKT-E2E1 and TKT-E2E2, even beside eligible ones', function () {
    $this->seed(DevSeeder::class);
    $owner = User::query()->where('email', 'e2e-customer@intechral.test')->firstOrFail();
    $fixtures = Ticket::query()->whereIn('ticket_number', ['TKT-E2E1', 'TKT-E2E2'])->get();
    $eligible = e2eTicket($owner);

    expect($fixtures)->toHaveCount(2)
        // The distinction the command relies on: neither fixture title starts with the literal prefix.
        ->and($fixtures->every(fn (Ticket $ticket) => ! str_starts_with($ticket->title, 'E2E-')))->toBeTrue()
        // TKT-E2E2 is owned by a seeded E2E customer, so only its title protects it.
        ->and($fixtures->firstWhere('ticket_number', 'TKT-E2E2')->user_id)->toBe($owner->id);

    e2eCleanup()->assertSuccessful()->expectsOutputToContain('Tickets deleted: 1');

    expect(Ticket::query()->whereIn('ticket_number', ['TKT-E2E1', 'TKT-E2E2'])->count())->toBe(2)
        ->and(Ticket::query()->whereKey($eligible->id)->exists())->toBeFalse();
});

it('is idempotent: a second run removes nothing and succeeds', function () {
    $ticket = e2eTicket(e2eCustomer());
    e2eAttachment($ticket);

    e2eCleanup()->assertSuccessful()->expectsOutputToContain('Tickets deleted: 1');
    e2eCleanup()->assertSuccessful()->expectsOutputToContain('Eligible E2E tickets: 0')->expectsOutputToContain('Nothing to remove.');
});

it('treats a missing attachment directory as already removed', function () {
    $ticket = e2eTicket(e2eCustomer());
    expect(Storage::disk('local')->directoryExists("tickets/{$ticket->id}"))->toBeFalse();

    e2eCleanup()
        ->assertSuccessful()
        ->expectsOutputToContain('Tickets deleted: 1')
        ->expectsOutputToContain('Attachment files removed: 0 (in 0 directories)');

    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeFalse();
});

it('leaves the row in place and fails when a ticket\'s files cannot be removed, then recovers on retry', function () {
    $owner = e2eCustomer();
    $stuck = e2eTicket($owner, 'E2E-stuck');
    $fine = e2eTicket($owner, 'E2E-fine');
    e2eAttachment($stuck);
    e2eAttachment($fine);

    $real = Storage::disk('local');
    $failing = Mockery::mock($real);
    $failing->shouldReceive('deleteDirectory')->with("tickets/{$stuck->id}")->andReturn(false);
    $failing->shouldReceive('deleteDirectory')->with("tickets/{$fine->id}")->andReturnUsing(
        fn () => $real->deleteDirectory("tickets/{$fine->id}"),
    );
    Storage::set('local', $failing);

    e2eCleanup()
        ->assertFailed()
        ->expectsOutputToContain('1 ticket(s) were NOT deleted')
        ->expectsOutputToContain($stuck->ticket_number)
        ->expectsOutputToContain('Tickets deleted: 1');

    // The ticket whose files could not go keeps its row (and so stays eligible); the other was removed.
    expect(Ticket::query()->whereKey($stuck->id)->exists())->toBeTrue()
        ->and(Ticket::query()->whereKey($fine->id)->exists())->toBeFalse();

    // The disk recovers; the retry finishes the job.
    Storage::set('local', $real);
    e2eCleanup()->assertSuccessful()->expectsOutputToContain('Tickets deleted: 1');
    expect(Ticket::query()->whereKey($stuck->id)->exists())->toBeFalse();
});

it('fails, and keeps the ticket, when the directory is still there after the delete reports success', function () {
    $ticket = e2eTicket(e2eCustomer());
    $path = e2eAttachment($ticket);
    $real = Storage::disk('local');
    $liar = Mockery::mock($real);
    $liar->shouldReceive('deleteDirectory')->andReturn(true);
    Storage::set('local', $liar);

    e2eCleanup()->assertFailed()->expectsOutputToContain('its attachment directory is still there after the delete');

    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue()
        ->and($real->exists($path))->toBeTrue();
});

it('reports a row that cannot be deleted after its files went, and a retry deletes it', function () {
    $ticket = e2eTicket(e2eCustomer());
    $path = e2eAttachment($ticket);
    $blocker = Ticket::deleting(function () {
        throw new RuntimeException('database refused the delete');
    });

    e2eCleanup()->assertFailed()->expectsOutputToContain('database refused the delete');

    // The files are gone, the row is not: exactly the state a later run must recover from.
    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();

    Ticket::flushEventListeners();
    Ticket::clearBootedModels();

    e2eCleanup()->assertSuccessful()->expectsOutputToContain('Tickets deleted: 1');
    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeFalse();
});

it('recovers a partial prior cleanup: files already gone, a mix of stale and untouched tickets', function () {
    $owner = e2eCustomer();
    $half = e2eTicket($owner, 'E2E-half-cleaned');
    $whole = e2eTicket($owner, 'E2E-untouched');
    e2eAttachment($whole);
    // $half's directory was removed by an earlier, interrupted run; its attachment row remains.
    $orphanRow = ticketAttachment($half, null, $owner, 'gone.txt', 'x');
    Storage::disk('local')->deleteDirectory("tickets/{$half->id}");

    e2eCleanup()->assertSuccessful()->expectsOutputToContain('Tickets deleted: 2');

    expect(Ticket::query()->whereIn('id', [$half->id, $whole->id])->count())->toBe(0)
        ->and(TicketAttachment::query()->whereKey($orphanRow->id)->exists())->toBeFalse();
});

it('fails, and keeps the ticket, when it has attachment records but the storage holds no tickets directory at all', function () {
    $owner = e2eCustomer();
    $ticket = e2eTicket($owner);
    // The records say files exist, yet this storage root has no `tickets/`: not a state a used application is
    // in, so a different root than the database's. That must not read as "already gone".
    TicketAttachment::create([
        'ticket_id' => $ticket->id, 'user_id' => $owner->id, 'filename' => 'x.txt',
        'path' => "tickets/{$ticket->id}/x.bin", 'mime_type' => 'text/plain', 'size' => 1,
    ]);
    expect(Storage::disk('local')->directoryExists('tickets'))->toBeFalse();

    e2eCleanup()->assertFailed()->expectsOutputToContain('attachment root holds no tickets directory');

    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue()
        ->and(TicketAttachment::query()->where('ticket_id', $ticket->id)->count())->toBe(1);
});

it('refuses a ticket with a linked time entry, deletes nothing at all, and does not detach it', function () {
    $owner = e2eCustomer();
    $linked = e2eTicket($owner, 'E2E-timed');
    $other = e2eTicket($owner, 'E2E-plain');
    $path = e2eAttachment($other);
    $entry = TimeEntry::factory()->create(['ticket_id' => $linked->id]);

    e2eCleanup()
        ->assertFailed()
        ->expectsOutputToContain('Refusing to delete any ticket')
        ->expectsOutputToContain("{$linked->ticket_number}: 1 time entry, 0 tasks")
        ->expectsOutputToContain('Nothing was deleted');

    expect($entry->fresh()->ticket_id)->toBe($linked->id)
        ->and(Ticket::query()->whereKey($linked->id)->exists())->toBeTrue()
        // The refusal is for the whole run, before any destructive work: the unlinked ticket stays too.
        ->and(Ticket::query()->whereKey($other->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('re-checks each ticket under its lock, so a link made after the up-front check is still refused', function () {
    $ticket = e2eTicket(e2eCustomer());
    $path = e2eAttachment($ticket);
    $linked = null;

    // The up-front check reads time_entries, then tasks. A time entry linked right after it has read
    // time_entries (here: when it starts on tasks) is the race the per-ticket re-check exists for.
    DB::listen(function ($query) use ($ticket, &$linked) {
        if ($linked === null && str_contains($query->sql, 'from `tasks`')) {
            $linked = false;
            $linked = TimeEntry::factory()->create(['ticket_id' => $ticket->id]);
        }
    });

    e2eCleanup()->assertFailed()->expectsOutputToContain('a time entry or task is linked to it; left in place');

    expect($linked)->toBeInstanceOf(TimeEntry::class)
        ->and($linked->fresh()->ticket_id)->toBe($ticket->id)
        ->and(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('refuses a ticket with a linked task, deletes nothing at all, and does not detach it', function () {
    $owner = e2eCustomer();
    $linked = e2eTicket($owner, 'E2E-tasked');
    $other = e2eTicket($owner, 'E2E-plain');
    $task = Task::factory()->create(['project_id' => null, 'column_id' => null, 'ticket_id' => $linked->id]);

    e2eCleanup()
        ->assertFailed()
        ->expectsOutputToContain("{$linked->ticket_number}: 0 time entries, 1 task");

    expect($task->fresh()->ticket_id)->toBe($linked->id)
        ->and(Ticket::query()->whereKey($linked->id)->exists())->toBeTrue()
        ->and(Ticket::query()->whereKey($other->id)->exists())->toBeTrue();
});

it('reports every blocked ticket with its counts and exits nonzero', function () {
    $owner = e2eCustomer();
    $a = e2eTicket($owner, 'E2E-a');
    $b = e2eTicket($owner, 'E2E-b');
    TimeEntry::factory()->count(2)->create(['ticket_id' => $a->id]);
    Task::factory()->create(['project_id' => null, 'column_id' => null, 'ticket_id' => $b->id]);

    e2eCleanup()
        ->assertExitCode(1)
        ->expectsOutputToContain("{$a->ticket_number}: 2 time entries, 0 tasks")
        ->expectsOutputToContain("{$b->ticket_number}: 0 time entries, 1 task");
});

it('does not touch a time entry or task linked to a ticket it is not allowed to delete', function () {
    $stranger = User::factory()->create()->assignRole('user');
    $protected = ticketFor($stranger, ['title' => 'E2E-not-ours']);
    $entry = TimeEntry::factory()->create(['ticket_id' => $protected->id]);
    e2eTicket(e2eCustomer(), 'E2E-ours');

    // A link on a ticket the command could never delete is not its business and does not block the run.
    e2eCleanup()->assertSuccessful()->expectsOutputToContain('Tickets deleted: 1');

    expect($entry->fresh()->ticket_id)->toBe($protected->id);
});

it('does not print ticket titles or attachment names', function () {
    $ticket = e2eTicket(e2eCustomer(), 'E2E-secret-title-9f3a');
    ticketAttachment($ticket, null, $ticket->user, 'confidential-name.pdf', 'x');

    $output = new BufferedOutput;
    Artisan::call('e2e:cleanup-tickets', [], $output);

    expect($output->fetch())->not->toContain('secret-title-9f3a')->not->toContain('confidential-name');
});

// ── Environment: the runtime value alone never authorises a run (G3-A review LOW-1) ───────────────────────

it('refuses when the configured environment is not local or testing, whatever the runtime environment says', function (string $configured) {
    $ticket = e2eTicket(e2eCustomer());
    config(['app.env' => $configured]);
    app()->detectEnvironment(fn () => 'local');
    DB::flushQueryLog();
    DB::enableQueryLog();

    e2eCleanup()->assertFailed()->expectsOutputToContain("Refusing to run in the \"{$configured}\" environment");

    expect(DB::getQueryLog())->toBe([])
        ->and(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue();
})->with(['production', 'staging']);

/**
 * `php artisan e2e:cleanup-tickets` in a real child process. The database host is unreachable (a closed port on
 * localhost), so a run that got as far as its first query would die with a connection error instead of printing
 * the refusal: "refused" therefore also proves "before any query".
 *
 * @return array{0: int, 1: string}
 */
function e2eCleanupProcess(string $appEnv, array $arguments = []): array
{
    $process = new Process([PHP_BINARY, 'artisan', 'e2e:cleanup-tickets', '--no-interaction', ...$arguments], base_path(), [
        'APP_ENV' => $appEnv,
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '1',
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
    ]);
    $process->setTimeout(60);
    $process->run();

    return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
}

it('is not authorised by the global --env option: APP_ENV=production with --env=local still refuses, before any query', function () {
    [$code, $output] = e2eCleanupProcess('production', ['--env=local']);

    expect($code)->toBe(1)
        ->and($output)->toContain('Refusing to run in the "production" environment')
        ->and($output)->not->toContain('SQLSTATE')
        ->and($output)->not->toContain('Eligible E2E tickets');
});

it('does reach the database when the configured environment is local (the control for the --env test)', function () {
    [$code, $output] = e2eCleanupProcess('local');

    // Proves the unreachable database would have surfaced had the refusal not come first.
    expect($code)->not->toBe(0)
        ->and($output)->not->toContain('Refusing to run')
        ->and($output)->toContain('SQLSTATE');
});

// ── Attachment directories: state is proven by listing, never guessed from a failed lookup (HIGH-1) ──────

it('deletes a ticket whose attachment directory exists but is empty', function () {
    $ticket = e2eTicket(e2eCustomer());
    Storage::disk('local')->makeDirectory("tickets/{$ticket->id}");

    e2eCleanup()
        ->assertSuccessful()
        ->expectsOutputToContain('Tickets deleted: 1')
        ->expectsOutputToContain('Attachment files removed: 0 (in 1 directory)');

    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->directoryExists("tickets/{$ticket->id}"))->toBeFalse();
});

it('refuses, and keeps the row, the attachment rows and the file, when tickets/{id} cannot be listed', function () {
    $ticket = e2eTicket(e2eCustomer());
    $path = e2eAttachment($ticket);
    // The wrong-identity scenario: an existing, populated directory this process may not enter.
    e2eRestrict("tickets/{$ticket->id}");

    e2eCleanup()
        ->assertFailed()
        ->expectsOutputToContain('cannot be established by this process')
        ->expectsOutputToContain('Nothing was deleted');

    e2eRestorePermissions();

    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue()
        ->and(TicketAttachment::query()->where('ticket_id', $ticket->id)->count())->toBe(1)
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('refuses, and keeps everything, when the tickets directory itself cannot be listed', function () {
    $ticket = e2eTicket(e2eCustomer());
    $path = e2eAttachment($ticket);
    e2eRestrict('tickets');

    // `is_dir` on the child would say "no" here; only a failed listing of the parent tells it apart from absent.
    e2eCleanup()->assertFailed()->expectsOutputToContain('cannot be established by this process');

    e2eRestorePermissions();

    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue()
        ->and(TicketAttachment::query()->where('ticket_id', $ticket->id)->count())->toBe(1)
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('refuses when the storage root cannot be listed', function () {
    $ticket = e2eTicket(e2eCustomer());
    $path = e2eAttachment($ticket);
    e2eRestrict('');

    e2eCleanup()->assertFailed()->expectsOutputToContain('cannot be established by this process');

    e2eRestorePermissions();

    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('refuses the whole run, deleting nothing, when one ticket\'s directory is unreadable and another\'s is fine', function () {
    $owner = e2eCustomer();
    $locked = e2eTicket($owner, 'E2E-locked');
    $fine = e2eTicket($owner, 'E2E-fine');
    $lockedPath = e2eAttachment($locked);
    $finePath = e2eAttachment($fine);
    e2eRestrict("tickets/{$locked->id}");

    e2eCleanup()->assertFailed()->expectsOutputToContain($locked->ticket_number);

    e2eRestorePermissions();

    expect(Ticket::query()->whereIn('id', [$locked->id, $fine->id])->count())->toBe(2)
        ->and(Storage::disk('local')->exists($lockedPath))->toBeTrue()
        ->and(Storage::disk('local')->exists($finePath))->toBeTrue();
});

it('keeps the row when the directory is listable but cannot be emptied (no write permission)', function () {
    $ticket = e2eTicket(e2eCustomer());
    $path = e2eAttachment($ticket);
    e2eRestrict("tickets/{$ticket->id}", 0500);

    e2eCleanup()->assertFailed()->expectsOutputToContain('1 ticket(s) were NOT deleted');

    e2eRestorePermissions();

    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeTrue();

    // The permission is fixed; the retry finishes the job.
    e2eCleanup()->assertSuccessful()->expectsOutputToContain('Tickets deleted: 1');
    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeFalse();
});

it('keeps the row after a partial deletion, and a retry removes what is left', function () {
    $ticket = e2eTicket(e2eCustomer());
    $first = e2eAttachment($ticket, null, 'one.txt');
    $second = e2eAttachment($ticket, null, 'two.txt');
    $real = Storage::disk('local');
    $partial = Mockery::mock($real);
    // Removes one file, then reports failure: the directory is only half gone.
    $partial->shouldReceive('deleteDirectory')->andReturnUsing(function () use ($real, $first) {
        $real->delete($first);

        return false;
    });
    Storage::set('local', $partial);

    e2eCleanup()->assertFailed()->expectsOutputToContain('1 ticket(s) were NOT deleted');

    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue()
        ->and($real->exists($first))->toBeFalse()
        ->and($real->exists($second))->toBeTrue();

    Storage::set('local', $real);
    e2eCleanup()->assertSuccessful()->expectsOutputToContain('Tickets deleted: 1');

    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeFalse()
        ->and($real->directoryExists("tickets/{$ticket->id}"))->toBeFalse();
});

it('does not print the private storage path, even from a storage error', function () {
    $ticket = e2eTicket(e2eCustomer());
    e2eAttachment($ticket);
    // The same disk with `throw` on, so the filesystem's own error (which names the full path) is raised.
    Storage::set('local', Storage::build(['driver' => 'local', 'root' => Storage::disk('local')->path(''), 'throw' => true]));
    e2eRestrict("tickets/{$ticket->id}", 0500);
    $output = new BufferedOutput;

    Artisan::call('e2e:cleanup-tickets', [], $output);
    $text = $output->fetch();
    $root = rtrim(Storage::disk('local')->path(''), '/');

    expect($text)->toContain('were NOT deleted')
        ->and($text)->not->toContain($root)
        ->and($text)->not->toContain(base_path());
});

// ── The protected delete: lock, re-check, then delete (MEDIUM-1, LOW-3) ──────────────────────────────────

it('reads under the ticket lock: a row lock on the ticket, then locking reads of the linked rows, then the delete', function () {
    $ticket = e2eTicket(e2eCustomer());
    DB::flushQueryLog();
    DB::enableQueryLog();

    e2eCleanup()->assertSuccessful();

    $sql = array_map(fn (array $query) => strtolower(preg_replace('/\s+/', ' ', $query['query'])), DB::getQueryLog());
    $position = function (string $pattern) use ($sql): int {
        foreach ($sql as $index => $query) {
            if (preg_match($pattern, $query) === 1) {
                return $index;
            }
        }

        return -1;
    };

    $lock = $position('/^select \* from `tickets` where `tickets`\.`id` = \? limit 1 for update$/');
    $timeLinks = $position('/^select `id` from `time_entries` where `ticket_id` = \? lock in share mode$/');
    $taskLinks = $position('/^select `id` from `tasks` where `ticket_id` = \? lock in share mode$/');
    $delete = $position('/^delete from `tickets` where `id` = \?$/');

    // Rows, not an aggregate, and a current read (not a snapshot): that is what makes the check trustworthy.
    expect($lock)->toBeGreaterThanOrEqual(0)
        ->and($timeLinks)->toBeGreaterThan($lock)
        ->and($taskLinks)->toBeGreaterThan($lock)
        ->and($delete)->toBeGreaterThan(max($timeLinks, $taskLinks));
});

it('re-checks the title under the lock, so a ticket renamed after the run began is left alone', function () {
    $ticket = e2eTicket(e2eCustomer());
    $path = e2eAttachment($ticket);
    $renamed = false;

    // After the up-front scan has read the links, the ticket stops being an E2E ticket.
    DB::listen(function ($query) use ($ticket, &$renamed) {
        if (! $renamed && str_contains($query->sql, 'from `tasks`')) {
            $renamed = true;
            DB::table('tickets')->where('id', $ticket->id)->update(['title' => 'A real request now']);
        }
    });

    e2eCleanup()->assertFailed()->expectsOutputToContain('no longer an E2E ticket');

    expect($renamed)->toBeTrue()
        ->and(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('re-checks the owner under the lock, so a ticket handed to someone else after the run began is left alone', function () {
    $ticket = e2eTicket(e2eCustomer());
    $stranger = User::factory()->create(['email' => 'someone@example.com'])->assignRole('user');
    $path = e2eAttachment($ticket);
    $moved = false;

    DB::listen(function ($query) use ($ticket, $stranger, &$moved) {
        if (! $moved && str_contains($query->sql, 'from `tasks`')) {
            $moved = true;
            DB::table('tickets')->where('id', $ticket->id)->update(['user_id' => $stranger->id]);
        }
    });

    e2eCleanup()->assertFailed()->expectsOutputToContain('no longer an E2E ticket');

    expect($moved)->toBeTrue()
        ->and(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('does not count a ticket another process already removed as one it deleted', function () {
    $ticket = e2eTicket(e2eCustomer());
    $gone = false;

    DB::listen(function ($query) use ($ticket, &$gone) {
        if (! $gone && str_contains($query->sql, 'from `tasks`')) {
            $gone = true;
            DB::table('tickets')->where('id', $ticket->id)->delete();
        }
    });

    e2eCleanup()->assertSuccessful()->expectsOutputToContain('Tickets deleted: 0');
});
