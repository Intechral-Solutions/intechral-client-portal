<?php

/*
 * EPIC-017 WP2 G3-A — `e2e:cleanup-tickets` against a REAL second database connection (MariaDB, InnoDB).
 *
 * `time_entries.ticket_id` and `tasks.ticket_id` are `nullOnDelete`: a ticket deleted while a record is being
 * linked to it would silently detach that record. The command prevents that by deleting each ticket inside one
 * transaction that first locks the ticket row. These tests do not trust query order: they run the actual command
 * and, from a SECOND connection, try to link a record at two exact moments, then assert what was persisted.
 *
 *   A. a link COMMITTED before the command takes its ticket lock  -> the command refuses; the link is intact.
 *   B. a link ATTEMPTED after the command holds its ticket lock   -> it cannot proceed (lock wait), and once the
 *      ticket is gone the foreign key rejects it; nothing is ever attached, nothing is nulled.
 *
 * The second connection acts from inside a DB::listen callback on the command's own queries, so the moment is
 * deterministic (no timing, no sleeps beyond InnoDB's lock wait, which the second connection sets to one second).
 *
 * Like TicketReplyRaceTest it must end RefreshDatabase's transaction so the second connection can see its rows
 * (DB::commit()), and it deletes everything it committed in a finally block. It refuses to run if a user with a
 * seeded E2E owner's email already exists, because the command would then delete that user's E2E tickets.
 */

use App\Console\Commands\CleanupE2eTickets;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CommittedUsers;

require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

beforeEach(function () {
    Storage::fake('local');
    config(['database.connections.e2e_race_b' => config('database.connections.'.config('database.default'))]);
});

afterEach(function () {
    DB::purge('e2e_race_b');
    ticketCleanFakeStorage();
});

/** The error number MariaDB gave, whatever the exception wrapper. */
function e2eDriverCode(QueryException $e): int
{
    return (int) ($e->errorInfo[1] ?? 0);
}

/**
 * A committed seeded-E2E owner with one E2E ticket (and a file for it), plus the second connection's handle.
 *
 * @return array{0: User, 1: Ticket, 2: Connection, 3: string}
 */
function e2eRaceFixture(): array
{
    DB::commit();

    if (User::query()->whereIn('email', CleanupE2eTickets::OWNER_EMAILS)->exists()) {
        throw new RuntimeException('The testing database already has a seeded E2E owner; refusing to run a cleanup against it.');
    }

    $owner = User::factory()->create(['email' => CleanupE2eTickets::OWNER_EMAILS[0]]);
    $ticket = Ticket::factory()->open()->for($owner, 'user')->create(['title' => CleanupE2eTickets::TITLE_PREFIX.'race '.uniqid()]);
    $path = "tickets/{$ticket->id}/race.bin";
    Storage::disk('local')->put($path, 'private bytes');

    $other = DB::connection('e2e_race_b');
    $other->statement('SET SESSION innodb_lock_wait_timeout = 1');

    return [$owner, $ticket, $other, $path];
}

function e2eLinkRow(int $userId, int $ticketId): array
{
    return ['user_id' => $userId, 'ticket_id' => $ticketId, 'date' => '2026-01-01', 'duration_minutes' => 15, 'created_at' => now(), 'updated_at' => now()];
}

it('A. refuses a ticket whose time entry was committed before the command took its ticket lock, and detaches nothing', function () {
    [$owner, $ticket, $other, $path] = e2eRaceFixture();
    $entryId = null;

    try {
        // After the up-front scan has finished (it ends with `tasks`), and before the per-ticket transaction:
        // a second connection commits a time entry linked to the ticket.
        DB::listen(function ($query) use ($ticket, $owner, $other, &$entryId) {
            if ($entryId === null && $query->connectionName !== 'e2e_race_b' && str_contains($query->sql, 'from `tasks`')) {
                $entryId = $other->table('time_entries')->insertGetId(e2eLinkRow($owner->id, $ticket->id));
            }
        });

        test()->artisan('e2e:cleanup-tickets')
            ->assertFailed()
            ->expectsOutputToContain('a time entry or task is linked to it; left in place');

        expect($entryId)->not->toBeNull()
            ->and(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue()
            ->and(DB::table('time_entries')->where('id', $entryId)->value('ticket_id'))->toBe($ticket->id)
            ->and(Storage::disk('local')->exists($path))->toBeTrue();
    } finally {
        CommittedUsers::delete($owner->id);
    }

    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeFalse()
        ->and(DB::table('time_entries')->where('user_id', $owner->id)->exists())->toBeFalse();
});

it('B. cannot be raced after the lock: a time entry or task linked meanwhile waits, then is rejected by the foreign key, never detached', function () {
    [$owner, $ticket, $other, $path] = e2eRaceFixture();
    // Records that exist but are NOT linked yet; the second connection will try to link them.
    $entry = TimeEntry::factory()->create(['user_id' => $owner->id, 'ticket_id' => null]);
    $task = Task::factory()->create(['project_id' => null, 'column_id' => null, 'ticket_id' => null, 'created_by' => $owner->id]);
    $during = [];

    try {
        DB::listen(function ($query) use ($ticket, $owner, $other, $entry, $task, &$during) {
            // The command has just executed `select … from tickets … for update`: the ticket row is locked.
            $isLock = $query->connectionName !== 'e2e_race_b'
                && str_contains($query->sql, 'from `tickets`') && str_contains($query->sql, 'for update');

            if (! $isLock || $during !== []) {
                return;
            }

            $attempts = [
                'insert' => fn () => $other->table('time_entries')->insert(e2eLinkRow($owner->id, $ticket->id)),
                'relink time' => fn () => $other->table('time_entries')->where('id', $entry->id)->update(['ticket_id' => $ticket->id]),
                'relink task' => fn () => $other->table('tasks')->where('id', $task->id)->update(['ticket_id' => $ticket->id]),
            ];

            foreach ($attempts as $name => $attempt) {
                try {
                    $attempt();
                    $during[$name] = 'succeeded';
                } catch (QueryException $e) {
                    $during[$name] = e2eDriverCode($e);
                }
            }
        });

        test()->artisan('e2e:cleanup-tickets')->assertSuccessful()->expectsOutputToContain('Tickets deleted: 1');

        // While the lock was held, every attempt was stopped by InnoDB's lock wait (1205), not let through.
        expect($during)->toBe(['insert' => 1205, 'relink time' => 1205, 'relink task' => 1205])
            ->and(Ticket::query()->whereKey($ticket->id)->exists())->toBeFalse()
            ->and(Storage::disk('local')->exists($path))->toBeFalse();

        // The ticket is gone, so now the foreign key itself refuses each attempt (1452).
        $after = [];

        foreach ([
            'insert' => fn () => $other->table('time_entries')->insert(e2eLinkRow($owner->id, $ticket->id)),
            'relink time' => fn () => $other->table('time_entries')->where('id', $entry->id)->update(['ticket_id' => $ticket->id]),
            'relink task' => fn () => $other->table('tasks')->where('id', $task->id)->update(['ticket_id' => $ticket->id]),
        ] as $name => $attempt) {
            try {
                $attempt();
                $after[$name] = 'succeeded';
            } catch (QueryException $e) {
                $after[$name] = e2eDriverCode($e);
            }
        }

        expect($after)->toBe(['insert' => 1452, 'relink time' => 1452, 'relink task' => 1452]);

        // The final persisted state: nothing was attached, nothing was nulled out from under anyone.
        expect(DB::table('time_entries')->where('ticket_id', $ticket->id)->count())->toBe(0)
            ->and(DB::table('time_entries')->where('user_id', $owner->id)->count())->toBe(1)
            ->and(DB::table('time_entries')->where('id', $entry->id)->value('ticket_id'))->toBeNull()
            ->and(DB::table('tasks')->where('id', $task->id)->value('ticket_id'))->toBeNull();
    } finally {
        Task::query()->whereKey($task->id)->delete();
        CommittedUsers::delete($owner->id);
    }

    expect(DB::table('time_entries')->where('user_id', $owner->id)->exists())->toBeFalse();
});
