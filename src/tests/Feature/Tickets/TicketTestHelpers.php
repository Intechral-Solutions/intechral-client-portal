<?php

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\NotificationFake;
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

/**
 * Real bytes of a given kind (EPIC-017 §20.1). The attachment rule reads the type from the file's
 * content, so a zero-filled `UploadedFile::fake()->create('a.pdf')` is, correctly, not a PDF.
 * Detection depends on the libmagic of the PHP build running the suite, so TicketAttachmentPolicyTest
 * asserts the detected type of every kind rather than assuming it.
 */
function ticketFileBytes(string $kind): string
{
    $zip = function (array $entries): string {
        $path = tempnam(sys_get_temp_dir(), 'tkt-zip');
        $archive = new ZipArchive;
        $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $archive->addFromString($name, $content);
        }
        $archive->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    };
    $image = function (callable $encode): string {
        $canvas = imagecreatetruecolor(2, 2);
        ob_start();
        $encode($canvas);

        return (string) ob_get_clean();
    };
    $ooxml = fn (string $part) => $zip([
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
        '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>',
        $part => '<?xml version="1.0"?><root/>',
    ]);

    return match ($kind) {
        'pdf' => "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n",
        'png' => $image(fn ($canvas) => imagepng($canvas)),
        'jpeg' => $image(fn ($canvas) => imagejpeg($canvas)),
        'gif' => $image(fn ($canvas) => imagegif($canvas)),
        // A minimal lossless WebP container (the image GD build here cannot encode WebP).
        'webp' => 'RIFF'.pack('V', 26).'WEBPVP8L'.pack('V', 14)."\x2f\x00\x00\x00\x00\x07\x10\x11\x11\x88\x88\xfe\x07\x00",
        'text' => "Plain text notes\nline two\n",
        'csv' => "name,amount,date\nAlice,10,2026-01-01\nBob,20,2026-01-02\n",
        'csv-semicolon' => "name;amount\nAlice;10\n",
        'docx' => $ooxml('word/document.xml'),
        'xlsx' => $ooxml('xl/workbook.xml'),
        'pptx' => $ooxml('ppt/presentation.xml'),
        'zip' => $zip(['readme.txt' => 'hello']),
        'html' => "<!DOCTYPE html><html><body><script>alert(1)</script></body></html>\n",
        'svg' => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        'shell' => "#!/bin/sh\necho owned\n",
        'php' => "<?php echo 'owned';\n",
        'exe' => 'MZ'.str_repeat("\0", 58).pack('V', 64)."PE\0\0".str_repeat("\0", 200),
        'elf' => "\x7fELF\x02\x01\x01".str_repeat("\0", 200),
        'zeros' => str_repeat("\0", 1024),
        'empty' => '',
    };
}

/** An uploaded file named $filename whose bytes are $kind (see ticketFileBytes()). */
function ticketUpload(string $filename, string $kind = 'pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($filename, ticketFileBytes($kind));
}

/** The transaction depth of the connection the Ticket models write through. */
function ticketTransactionLevel(): int
{
    return (new Ticket)->getConnection()->transactionLevel();
}

/**
 * Notification::fake() that also records, for every notification handed to the dispatcher, the
 * transaction level of the ticket connection at that instant (EPIC-017 O3 groundwork, "after commit
 * only"). The test framework's own transaction is part of the baseline, so compare each recorded
 * level with ticketTransactionLevel() read just before the call under test: equal means the service
 * had already committed (or never opened) its own transaction, greater means it sent mail inside one.
 *
 * @return NotificationFake&object{levels: list<int>}
 */
function ticketRecordNotificationLevels(): NotificationFake
{
    $fake = new class extends NotificationFake
    {
        /** @var list<int> */
        public array $levels = [];

        public function sendNow($notifiables, $notification, ?array $channels = null)
        {
            $this->levels[] = ticketTransactionLevel();

            parent::sendNow($notifiables, $notification, $channels);
        }
    };

    Notification::swap($fake);

    return $fake;
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
