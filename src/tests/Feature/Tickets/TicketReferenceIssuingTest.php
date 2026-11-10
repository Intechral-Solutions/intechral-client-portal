<?php

use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use App\Services\TicketService;
use App\Support\Helpdesk\TicketReference;
use App\Support\Helpdesk\TicketReferenceExhausted;
use App\Support\Helpdesk\TicketReferenceGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedUsers;

require_once __DIR__.'/TicketTestHelpers.php';

/*
 * Release 0.9 C6-R (ADR-008): how a ticket's public reference is issued, and that it never changes. This replaces the EPIC-010D H8
 * id-derived numbering contract (`TKT-` + padded id, finalised by a second update), which C6-R retired.
 *
 * Covered: the random canonical reference; a forced duplicate redrawn within the bound; exhaustion that fails closed (no row, file, mail
 * or job); a non-duplicate error that is NOT retried; the transactional attachment guarantee kept; immutability and the one controlled
 * legacy conversion window; real parallel creates, including a forced same-reference race.
 */

/** R1-2: a user who may raise a request: tickets.create and current membership of one organization (seeding the roles first, idempotently). */
function issuingUser(): User
{
    test()->seedRolesAndPermissions();

    return ticketRequester();
}

function issuingPayload(string $title = 'Numbered'): array
{
    return ['title' => $title, 'description' => 'x', 'category' => 'General', 'priority' => 'low'];
}

/** A generator that hands out the given references first, then random ones, and counts how often it was asked. */
function issuingGenerator(array $sequence): TicketReferenceGenerator
{
    $generator = new class($sequence) extends TicketReferenceGenerator
    {
        public int $calls = 0;

        public function __construct(private array $sequence) {}

        public function next(): string
        {
            $this->calls++;

            return array_shift($this->sequence) ?? parent::next();
        }
    };

    app()->instance(TicketReferenceGenerator::class, $generator);

    return $generator;
}

/** A pre-C6-R row, inserted around the model (which refuses to create one). */
function issuingLegacyRow(User $owner, string $number = 'TKT-0001'): int
{
    return DB::table('tickets')->insertGetId([
        'ticket_number' => $number, 'user_id' => $owner->id, 'organization_id' => Organization::factory()->create()->id, 'submitted_by_id' => $owner->id, 'title' => 'Legacy', 'description' => 'x', 'category' => 'General',
        'priority' => 'low', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('issues a random canonical reference that does not depend on the id', function () {
    Notification::fake();
    $user = issuingUser();
    $service = app(TicketService::class);

    $tickets = collect(range(1, 3))->map(fn ($i) => $service->create($user, issuingPayload("t{$i}")));

    foreach ($tickets as $ticket) {
        expect($ticket->ticket_number)->toMatch(TicketReference::PATTERN)
            ->and($ticket->fresh()->ticket_number)->toBe($ticket->ticket_number)
            ->and($ticket->ticket_number)->not->toStartWith('TKT-')
            // Not derived from the id. (This used to assert `not->toContain($ticket->id.'-')`, which fails about one run in thirty by chance: a
            // random 5-character group that merely ENDS in the digit "1" contains "1-". Test-only fix, unrelated to R1-2: what the old id-derived
            // format looked like is a zero-padded id, and a random reference does not equal that.)
            ->and($ticket->ticket_number)->not->toContain(str_pad((string) $ticket->id, 5, '0', STR_PAD_LEFT));
    }
    expect($tickets->pluck('ticket_number')->unique())->toHaveCount(3)
        ->and(Ticket::where('ticket_number', 'like', 'TMP-%')->count())->toBe(0);
});

it('redraws on a duplicate of the ticket_number index and keeps private attachments under the numeric id', function () {
    Notification::fake();
    Storage::fake('local');
    $user = issuingUser();
    $taken = Ticket::factory()->for($user, 'user')->create(['ticket_number' => 'TK-TAKEN-00001'])->ticket_number;
    $generator = issuingGenerator([$taken, $taken, 'TK-FRESH-00002']);

    $ticket = app(TicketService::class)->create($user, issuingPayload(), [UploadedFile::fake()->create('a.pdf', 1, 'application/pdf')]);

    expect($ticket->ticket_number)->toBe('TK-FRESH-00002')
        ->and($generator->calls)->toBe(3)
        ->and(Ticket::where('ticket_number', $taken)->count())->toBe(1);

    $attachment = TicketAttachment::where('ticket_id', $ticket->id)->sole();
    // C1: internal storage identity stays numeric; the public reference is not a directory name.
    expect($attachment->path)->toStartWith("tickets/{$ticket->id}/")
        ->and($attachment->path)->not->toContain('TK-');

    ticketCleanFakeStorage();
});

it('survives a redraw inside a caller transaction without disturbing it', function () {
    Notification::fake();
    $user = issuingUser();
    Ticket::factory()->for($user, 'user')->create(['ticket_number' => 'TK-TAKEN-00001']);
    $before = ticketFor($user);
    issuingGenerator(['TK-TAKEN-00001']);

    $ticket = DB::transaction(function () use ($user, $before) {
        $created = app(TicketService::class)->create($user, issuingPayload());

        return [$before, $created];
    });

    expect(Ticket::whereKey($ticket[0]->id)->exists())->toBeTrue()
        ->and(Ticket::whereKey($ticket[1]->id)->exists())->toBeTrue();
});

it('fails closed when every bounded attempt collides: no row, attachment, file, mail or job', function () {
    Notification::fake();
    Storage::fake('local');
    $user = issuingUser();
    $taken = Ticket::factory()->for($user, 'user')->create(['ticket_number' => 'TK-TAKEN-00001'])->ticket_number;
    $generator = issuingGenerator(array_fill(0, 50, $taken));
    $tickets = Ticket::count();
    $jobs = DB::table('jobs')->count();

    expect(fn () => app(TicketService::class)->create($user, issuingPayload(), [UploadedFile::fake()->create('a.pdf', 1, 'application/pdf')]))
        ->toThrow(TicketReferenceExhausted::class);

    expect($generator->calls)->toBe(5)
        ->and(Ticket::count())->toBe($tickets)
        ->and(TicketAttachment::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(DB::table('jobs')->count())->toBe($jobs);
    Notification::assertNothingSent();

    ticketCleanFakeStorage();
});

it('does not retry any integrity error other than the reference duplicate', function () {
    Notification::fake();
    $generator = issuingGenerator([]);
    $user = issuingUser();

    // Not a reference collision, but a database error on the same insert (a title longer than its column, which strict mode refuses). A retry
    // would only repeat it, so it propagates after ONE draw. (The ghost-requester foreign-key case is now refused earlier, by ServiceRequestAccess.)
    expect(fn () => app(TicketService::class)->create($user, issuingPayload(str_repeat('x', 300))))->toThrow(QueryException::class);

    expect($generator->calls)->toBe(1)->and(Ticket::count())->toBe(0);
});

it('rolls the ticket back whole when an attachment fails after the reference was issued', function () {
    Notification::fake();
    Storage::fake('local');
    $user = issuingUser();
    $countBefore = Ticket::count();

    TicketAttachment::creating(function () {
        throw new RuntimeException('simulated attachment failure');
    });

    try {
        expect(fn () => app(TicketService::class)->create($user, issuingPayload(), [UploadedFile::fake()->create('a.pdf', 1, 'application/pdf')]))
            ->toThrow(RuntimeException::class, 'simulated attachment failure');
    } finally {
        TicketAttachment::flushEventListeners();
    }

    expect(Ticket::count())->toBe($countBefore)
        ->and(TicketAttachment::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Notification::assertNothingSent();

    ticketCleanFakeStorage();
});

it('redirects a customer submission to the reference URL and mails the reference link', function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    $customer = ticketRequester();

    $this->actingAs($customer)->post(route('tickets.store'), issuingPayload('Over HTTP'))->assertRedirect();

    $ticket = Ticket::where('title', 'Over HTTP')->sole();
    expect($ticket->ticket_number)->toMatch(TicketReference::PATTERN);

    Notification::assertSentTo($customer, TicketCreatedNotification::class, function (TicketCreatedNotification $notification) use ($ticket, $customer) {
        $mail = $notification->toMail($customer);

        return $notification->ticket->ticket_number === $ticket->ticket_number
            && $mail->actionUrl === url('/tickets/'.$ticket->ticket_number)
            && str_contains($mail->subject, $ticket->ticket_number);
    });

    $this->actingAs($customer)->get('/tickets/'.$ticket->ticket_number)->assertOk();
    $this->actingAs($customer)->get('/tickets/'.$ticket->id)->assertNotFound();
});

it('hands every factory ticket its own canonical reference', function () {
    $user = issuingUser();

    $numbers = Ticket::factory()->count(40)->for($user, 'user')->create()->pluck('ticket_number');

    expect($numbers->unique())->toHaveCount(40)
        ->and($numbers->every(fn ($n) => TicketReference::isCanonical($n)))->toBeTrue();
});

// ── Immutability ─────────────────────────────────────────────────────────────

it('refuses to create a ticket under a non-canonical reference', function (string $number) {
    $user = issuingUser();

    expect(fn () => Ticket::factory()->for($user, 'user')->create(['ticket_number' => $number]))->toThrow(InvalidArgumentException::class);
    expect(Ticket::count())->toBe(0);
})->with(['legacy' => ['TKT-0001'], 'temporary' => ['TMP-abcdef012345'], 'lower case' => ['tk-aaaaa-bbbbb'], 'blank' => ['']]);

it('never lets an issued reference change', function () {
    $user = issuingUser();
    $ticket = Ticket::factory()->for($user, 'user')->create();
    $original = $ticket->ticket_number;

    foreach (['TK-NEWAA-00001', 'TKT-0001', ''] as $attempt) {
        expect(fn () => $ticket->update(['ticket_number' => $attempt]))->toThrow(LogicException::class);
        $ticket->refresh();
    }

    expect(fn () => $ticket->forceFill(['ticket_number' => 'TK-NEWAA-00001'])->save())->toThrow(LogicException::class)
        ->and(fn () => Ticket::convertingLegacyReferences(fn () => $ticket->update(['ticket_number' => 'TK-NEWAA-00001'])))->toThrow(LogicException::class)
        ->and(Ticket::find($ticket->id)->ticket_number)->toBe($original);

    // Ordinary operations leave it alone.
    $ticket->refresh();
    $ticket->update(['status' => 'in_progress', 'title' => 'Renamed']);
    expect($ticket->fresh()->ticket_number)->toBe($original);
});

it('permits only a legacy value becoming a canonical one, and only inside the conversion window', function () {
    $user = issuingUser();
    $id = issuingLegacyRow($user);
    $ticket = Ticket::findOrFail($id);

    // Outside the window: refused.
    expect(fn () => $ticket->update(['ticket_number' => 'TK-NEWAA-00001']))->toThrow(LogicException::class);
    $ticket->refresh();

    // Inside: legacy -> canonical only.
    expect(fn () => Ticket::convertingLegacyReferences(fn () => $ticket->update(['ticket_number' => 'TKT-0002'])))->toThrow(LogicException::class);
    $ticket->refresh();
    Ticket::convertingLegacyReferences(fn () => $ticket->update(['ticket_number' => 'TK-NEWAA-00001']));
    expect($ticket->fresh()->ticket_number)->toBe('TK-NEWAA-00001');

    // The window closed again, also after an exception inside it.
    try {
        Ticket::convertingLegacyReferences(fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }
    $other = Ticket::findOrFail(issuingLegacyRow($user, 'TKT-0009'));
    expect(fn () => $other->update(['ticket_number' => 'TK-NEWAA-00002']))->toThrow(LogicException::class);
});

// ── Real concurrency ─────────────────────────────────────────────────────────

/*
 * RefreshDatabase wraps each test in a transaction other connections cannot see, so this probe ends it, commits one user, races worker
 * processes that each create tickets through TicketService, and deletes the user (tickets cascade) in a finally block. Workers run with
 * APP_ENV=testing (TestDatabaseSafety) and MAIL_MAILER=array. TICKET_RACE_WORKERS / TICKET_RACE_CREATES turn the dial up for a soak.
 * With $sharedFirst, every worker's FIRST draw is the same reference, so the workers genuinely race on one value: one wins it, the others
 * must redraw and still succeed.
 */
function issuingRace(bool $sharedFirst): void
{
    DB::commit();
    $user = User::factory()->create();
    // The workers are separate processes reading COMMITTED rows: the creation permission and a membership must be committed too.
    $permission = Permission::firstOrCreate(['name' => 'tickets.create', 'guard_name' => 'web']);
    $user->givePermissionTo($permission);
    $organization = Organization::factory()->create(['owner_id' => $user->id]);
    ticketJoin($user, $organization);

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
            'TICKET_RACE_SHARED_FIRST' => $sharedFirst ? 'TK-RACE0-00001' : '',
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
        $errors = $outcomes->reject(fn ($o) => str_starts_with($o, 'ok:'));
        $committed = Ticket::where('user_id', $user->id)->pluck('ticket_number');

        if (getenv('TICKET_RACE_REPORT')) {
            fwrite(STDERR, sprintf("\n[C6-R probe] shared=%d workers=%d creates/worker=%d attempts=%d ok=%d error=%d\n",
                $sharedFirst, $workers, $perWorker, $outcomes->count(), $ok->count(), $errors->count()));
        }

        expect($errors->values()->all())->toBe([])
            ->and($ok)->toHaveCount($workers * $perWorker)
            ->and($committed)->toHaveCount($workers * $perWorker)
            ->and($committed->unique())->toHaveCount($committed->count())
            ->and($committed->every(fn ($n) => TicketReference::isCanonical($n)))->toBeTrue()
            ->and($ok->map(fn ($o) => substr($o, 3))->unique())->toHaveCount($ok->count());

        if ($sharedFirst) {
            expect($committed->contains('TK-RACE0-00001'))->toBeTrue();
        }
    } finally {
        DB::table('model_has_permissions')->where('model_id', $user->id)->where('model_type', User::class)->delete();
        CommittedUsers::delete($user->id);

        if ($permission->wasRecentlyCreated) {
            $permission->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    expect(Ticket::where('user_id', $user->id)->exists())->toBeFalse();
}

it('parallel creates on MariaDB all succeed with unique canonical references', function () {
    issuingRace(false);
});

it('parallel creates racing for one and the same reference all succeed: one wins it, the rest redraw', function () {
    issuingRace(true);
});
