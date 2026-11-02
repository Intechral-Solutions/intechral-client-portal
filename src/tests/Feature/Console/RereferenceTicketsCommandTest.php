<?php

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\TicketStatusHistory;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Helpdesk\TicketReference;
use App\Support\Helpdesk\TicketReferenceGenerator;
use Database\Seeders\DevSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

/*
 * Release 0.9 C6-R: `tickets:rereference`, the guarded conversion of pre-C6-R ticket numbers. Read-only unless --apply; --apply needs the
 * config gate, --expect equal to the legacy count and a small cap; it changes only ticket_number, through the immutability guard's one
 * conversion window; it is repeatable.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Storage::fake('local');
    config(['helpdesk.rereference_allowed' => true]);
});

afterEach(function () {
    ticketCleanFakeStorage();
});

function rereferenceLegacy(User $owner, string $number, array $extra = []): Ticket
{
    $id = DB::table('tickets')->insertGetId([
        'ticket_number' => $number, 'user_id' => $owner->id, 'title' => "Legacy {$number}", 'description' => 'x', 'category' => 'General',
        'priority' => 'low', 'status' => 'open', 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-02 10:00:00', ...$extra,
    ]);

    return Ticket::findOrFail($id);
}

function rereferenceNumbers(): array
{
    return DB::table('tickets')->orderBy('id')->pluck('ticket_number', 'id')->all();
}

it('is read-only by default: it lists the legacy numbers and changes nothing', function () {
    $owner = User::factory()->create();
    rereferenceLegacy($owner, 'TKT-0001');
    ticketFor($owner);
    $before = rereferenceNumbers();

    $this->artisan('tickets:rereference')->expectsOutputToContain('Legacy ticket numbers: 1')->expectsOutputToContain('TKT-0001')->assertSuccessful();

    expect(rereferenceNumbers())->toBe($before);
});

it('--check exits 1 while any legacy number exists and 0 once none does', function () {
    $owner = User::factory()->create();
    ticketFor($owner);
    $this->artisan('tickets:rereference', ['--check' => true])->assertSuccessful();

    rereferenceLegacy($owner, 'TKT-E2E1');
    $this->artisan('tickets:rereference', ['--check' => true])->assertFailed();
    $this->artisan('tickets:rereference', ['--check' => true, '--apply' => true])->assertFailed();
});

it('converts the legacy tickets in place and preserves rows, relationships, files and timestamps', function () {
    $owner = ticketUser();
    $operator = ticketUser('operator');
    $one = rereferenceLegacy($owner, 'TKT-0001');
    $two = rereferenceLegacy($owner, 'TKT-0002');
    $modern = ticketFor($owner);
    $reply = ticketReply($one, $operator, false);
    $attachment = ticketAttachment($one, $reply, $operator, 'kept.txt', 'kept-bytes');
    $history = TicketStatusHistory::forceCreate(['ticket_id' => $one->id, 'user_id' => $operator->id, 'old_status' => 'open', 'new_status' => 'open', 'created_at' => now()]);
    $entry = TimeEntry::factory()->create(['user_id' => $operator->id, 'ticket_id' => $two->id]);
    $untouched = $modern->ticket_number;
    $createdAt = $one->created_at;

    $this->artisan('tickets:rereference', ['--apply' => true, '--expect' => 2])->expectsOutputToContain('Converted 2 ticket(s); 0 not converted.')->assertSuccessful();

    $one->refresh();
    $two->refresh();
    expect($one->ticket_number)->toMatch(TicketReference::PATTERN)
        ->and($two->ticket_number)->toMatch(TicketReference::PATTERN)
        ->and($one->ticket_number)->not->toBe($two->ticket_number)
        ->and($modern->fresh()->ticket_number)->toBe($untouched)
        ->and($one->title)->toBe('Legacy TKT-0001')
        ->and($one->created_at->equalTo($createdAt))->toBeTrue()
        ->and($one->updated_at->toDateTimeString())->toBe('2026-01-02 10:00:00')
        ->and(TicketReply::whereKey($reply->id)->value('ticket_id'))->toBe($one->id)
        ->and(TicketStatusHistory::whereKey($history->id)->value('ticket_id'))->toBe($one->id)
        ->and(TimeEntry::whereKey($entry->id)->value('ticket_id'))->toBe($two->id)
        ->and($attachment->fresh()->path)->toBe($attachment->path)
        ->and($attachment->path)->toStartWith("tickets/{$one->id}/");

    // The converted ticket is reachable by its new reference, its old number is not, and the file still downloads.
    $this->actingAs($owner)->get('/tickets/'.$one->ticket_number)->assertOk();
    $this->actingAs($owner)->get('/tickets/TKT-0001')->assertNotFound();
    $this->actingAs($owner)->get("/tickets/{$one->ticket_number}/attachments/{$attachment->id}")->assertOk();
});

it('is repeatable: a second run finds nothing to do and changes nothing', function () {
    $owner = User::factory()->create();
    rereferenceLegacy($owner, 'TKT-0001');
    $this->artisan('tickets:rereference', ['--apply' => true, '--expect' => 1])->assertSuccessful();
    $after = rereferenceNumbers();

    config(['helpdesk.rereference_allowed' => false]);
    $this->artisan('tickets:rereference', ['--apply' => true, '--expect' => 0])->expectsOutputToContain('Nothing to do')->assertSuccessful();
    $this->artisan('tickets:rereference', ['--check' => true])->assertSuccessful();

    expect(rereferenceNumbers())->toBe($after);
});

it('refuses without --expect, with a wrong --expect, over the cap, or with the gate closed, and changes nothing', function () {
    $owner = User::factory()->create();
    rereferenceLegacy($owner, 'TKT-0001');
    rereferenceLegacy($owner, 'TKT-0002');
    $before = rereferenceNumbers();

    $this->artisan('tickets:rereference', ['--apply' => true])->expectsOutputToContain('needs --expect')->assertFailed();
    $this->artisan('tickets:rereference', ['--apply' => true, '--expect' => 1])->expectsOutputToContain('Expected 1')->assertFailed();
    $this->artisan('tickets:rereference', ['--apply' => true, '--expect' => 'two'])->assertFailed();

    config(['helpdesk.rereference_allowed' => false]);
    $this->artisan('tickets:rereference', ['--apply' => true, '--expect' => 2])->expectsOutputToContain('gate is closed')->assertFailed();
    config(['helpdesk.rereference_allowed' => 'true']);
    $this->artisan('tickets:rereference', ['--apply' => true, '--expect' => 2])->expectsOutputToContain('gate is closed')->assertFailed();

    expect(rereferenceNumbers())->toBe($before);

    config(['helpdesk.rereference_allowed' => true]);
    foreach (range(3, 27) as $n) {
        rereferenceLegacy($owner, sprintf('TKT-%04d', $n));
    }
    $before = rereferenceNumbers();
    $this->artisan('tickets:rereference', ['--apply' => true, '--expect' => 27])->expectsOutputToContain('More than 25')->assertFailed();
    expect(rereferenceNumbers())->toBe($before);
});

it('draws again when a new reference collides, within the same bound as creation', function () {
    $owner = User::factory()->create();
    $legacy = rereferenceLegacy($owner, 'TKT-0001');
    ticketFor($owner, ['ticket_number' => 'TK-TAKEN-00001']);

    $generator = new class(['TK-TAKEN-00001', 'TK-TAKEN-00001', 'TK-FRESH-00002']) extends TicketReferenceGenerator
    {
        public function __construct(private array $sequence) {}

        public function next(): string
        {
            return array_shift($this->sequence) ?? parent::next();
        }
    };
    app()->instance(TicketReferenceGenerator::class, $generator);

    $this->artisan('tickets:rereference', ['--apply' => true, '--expect' => 1])->assertSuccessful();

    expect($legacy->fresh()->ticket_number)->toBe('TK-FRESH-00002');
});

it('leaves a ticket alone, and says so, when it no longer holds the number it was listed with', function () {
    $owner = User::factory()->create();
    $legacy = rereferenceLegacy($owner, 'TKT-0001');
    $fired = false;

    // Someone else changes the row between the listing and the locked re-read.
    DB::beforeExecuting(function (string $query) use (&$fired) {
        if (! $fired && str_contains($query, 'for update')) {
            $fired = true;
            DB::table('tickets')->where('ticket_number', 'TKT-0001')->update(['ticket_number' => 'TKT-9999']);
        }
    });

    $this->artisan('tickets:rereference', ['--apply' => true, '--expect' => 1])->expectsOutputToContain('no longer holds TKT-0001')->assertFailed();

    expect($fired)->toBeTrue()
        ->and(Ticket::find($legacy->id)->ticket_number)->toBe('TKT-9999');
});

it('writes the id/old/new map to a new private file and refuses to overwrite one', function () {
    $owner = User::factory()->create();
    $legacy = rereferenceLegacy($owner, 'TKT-0001');
    $file = sys_get_temp_dir().'/c6r-map-'.bin2hex(random_bytes(4)).'.tsv';

    try {
        $this->artisan('tickets:rereference', ['--apply' => true, '--expect' => 1, '--map-file' => $file])->assertSuccessful();

        $lines = explode("\n", trim((string) file_get_contents($file)));
        [$id, $old, $new] = explode("\t", $lines[0]);
        expect($lines)->toHaveCount(1)
            ->and((int) $id)->toBe($legacy->id)->and($old)->toBe('TKT-0001')->and($new)->toBe($legacy->fresh()->ticket_number)
            ->and(substr(sprintf('%o', fileperms($file)), -4))->toBe('0600');

        $this->artisan('tickets:rereference', ['--apply' => true, '--expect' => 0, '--map-file' => $file])->assertSuccessful();
        rereferenceLegacy($owner, 'TKT-0002');
        $this->artisan('tickets:rereference', ['--apply' => true, '--expect' => 1, '--map-file' => $file])->expectsOutputToContain('new file')->assertFailed();
        expect(Ticket::where('ticket_number', 'TKT-0002')->exists())->toBeTrue();
    } finally {
        @unlink($file);
    }
});

it('--check warns while the conversion gate is open', function () {
    $this->artisan('tickets:rereference', ['--check' => true])->expectsOutputToContain('gate (TICKET_REREFERENCE_ALLOWED) is OPEN')->assertSuccessful();

    config(['helpdesk.rereference_allowed' => false]);
    $this->artisan('tickets:rereference', ['--check' => true])->doesntExpectOutputToContain('is OPEN')->assertSuccessful();
});

it('keeps a map of exactly the rows converted when one row cannot be converted', function () {
    $owner = User::factory()->create();
    $first = rereferenceLegacy($owner, 'TKT-0001');
    $second = rereferenceLegacy($owner, 'TKT-0002');
    $file = sys_get_temp_dir().'/c6r-map-'.bin2hex(random_bytes(4)).'.tsv';
    $fired = false;

    // The second row changes under the command's feet: only the first is converted, and the map says so.
    DB::beforeExecuting(function (string $query, array $bindings) use (&$fired, $second) {
        if (! $fired && str_contains($query, 'for update') && in_array($second->id, $bindings, true)) {
            $fired = true;
            DB::table('tickets')->where('id', $second->id)->update(['ticket_number' => 'TKT-9999']);
        }
    });

    try {
        $this->artisan('tickets:rereference', ['--apply' => true, '--expect' => 2, '--map-file' => $file])->assertFailed();

        $lines = explode("\n", trim((string) file_get_contents($file)));
        expect($lines)->toHaveCount(1)
            ->and(explode("\t", $lines[0])[0])->toBe((string) $first->id)
            ->and(Ticket::find($second->id)->ticket_number)->toBe('TKT-9999');
    } finally {
        @unlink($file);
    }
});

it('lets DevSeeder take over a pre-C6-R fixture instead of duplicating it', function () {
    $this->seed(DevSeeder::class);
    $titles = ['E2E fixture: embedded time tracker', 'E2E fixture: waiting on the customer'];
    $fixtures = Ticket::whereIn('title', $titles)->orderBy('id')->get();
    expect($fixtures->pluck('ticket_number')->all())->toBe(['TK-E2E00-00001', 'TK-E2E00-00002']);

    // A development database seeded before C6-R: the same fixtures under their legacy numbers.
    DB::table('tickets')->where('id', $fixtures[0]->id)->update(['ticket_number' => 'TKT-E2E1']);
    DB::table('tickets')->where('id', $fixtures[1]->id)->update(['ticket_number' => 'TKT-E2E2']);

    $this->seed(DevSeeder::class);
    $after = Ticket::whereIn('title', $titles)->orderBy('id')->get();

    expect($after)->toHaveCount(2)
        ->and($after->pluck('id')->all())->toBe($fixtures->pluck('id')->all())
        ->and($after->pluck('ticket_number')->all())->toBe(['TK-E2E00-00001', 'TK-E2E00-00002']);
    $this->artisan('tickets:rereference', ['--check' => true])->assertSuccessful();
});
