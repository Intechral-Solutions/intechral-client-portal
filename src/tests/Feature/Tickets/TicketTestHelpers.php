<?php

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

/*
 * Shared fixtures for the EPIC-010D Ticket characterization suites. Loaded with require_once
 * from each test file; the file name deliberately does not end in "Test" so Pest does not treat
 * it as a test. Function names carry a "ticket" prefix so they never collide with the global
 * helpers of ProjectTestHelpers.php when both are loaded in one process.
 *
 * Actors (EPIC-010D §7, §9):
 *   owner     built-in `user` role, owns the Ticket
 *   customer  built-in `user` role, unrelated to the Ticket
 *   operator  built-in `operator` role (every permission)
 *   agent     custom role holding tickets.view + tickets.assign + time.log but NOT the literal
 *             `operator` role: the capability-only operator that H9 is about
 */

function ticketUser(string $role = 'user', array $attributes = []): User
{
    return User::factory()->create($attributes)->assignRole($role);
}

/** A user holding a custom role with exactly the given permissions (never the literal operator role). */
function ticketCustomRoleUser(string $roleName, array $permissions, array $attributes = []): User
{
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    return User::factory()->create($attributes)->assignRole($role);
}

/** The H9 actor: operator capabilities without the literal `operator` role. */
function ticketAgent(array $attributes = []): User
{
    return ticketCustomRoleUser('support_agent', ['tickets.view', 'tickets.assign', 'time.log'], $attributes);
}

function ticketFor(User $owner, array $attributes = []): Ticket
{
    return Ticket::factory()->open()->for($owner, 'user')->create($attributes);
}

function ticketReply(Ticket $ticket, User $author, bool $internal, string $body = 'Reply body'): TicketReply
{
    return $ticket->replies()->create([
        'user_id' => $author->id,
        'body' => $body,
        'is_internal' => $internal,
    ]);
}

/**
 * Refuse to write attachment bytes anywhere but a Storage::fake('local') disk, so a
 * characterization of an attachment leak can never touch real storage.
 */
function ticketAssertStorageFaked(): void
{
    $root = str_replace('\\', '/', Storage::disk('local')->path(''));

    if (! str_contains($root, '/framework/testing/disks/')) {
        throw new RuntimeException("Ticket attachment fixtures require Storage::fake('local'); local disk root is {$root}");
    }
}

/** An attachment row plus its bytes on the faked local disk, laid out as TicketService stores them. */
function ticketAttachment(Ticket $ticket, ?TicketReply $reply, User $uploader, string $filename, string $contents): TicketAttachment
{
    ticketAssertStorageFaked();

    $path = "tickets/{$ticket->id}/".uniqid('att_', true).'.bin';
    Storage::disk('local')->put($path, $contents);

    return TicketAttachment::create([
        'ticket_id' => $ticket->id,
        'reply_id' => $reply?->id,
        'user_id' => $uploader->id,
        'filename' => $filename,
        'path' => $path,
        'mime_type' => 'application/octet-stream',
        'size' => strlen($contents),
    ]);
}

/** Remove everything the faked local disk holds, so no fixture bytes outlive the test. */
function ticketCleanFakeStorage(): void
{
    ticketAssertStorageFaked();

    foreach (Storage::disk('local')->directories() as $directory) {
        Storage::disk('local')->deleteDirectory($directory);
    }
    Storage::disk('local')->delete(Storage::disk('local')->files());
}

/**
 * Parse CSV text the way an RFC-4180 consumer does (double-quote escaping only, no backslash
 * escape), row by row, so embedded newlines stay inside their cell.
 *
 * @return array<int, array<int, string|null>>
 */
function ticketParseCsv(string $csv): array
{
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, $csv);
    rewind($stream);

    $rows = [];
    while (($row = fgetcsv($stream, escape: '')) !== false) {
        $rows[] = $row;
    }
    fclose($stream);

    return $rows;
}
