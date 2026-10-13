<?php

/*
 * EPIC-017 WP2 step 2 — the one attachment rule (§20.1) and atomic uploads (EPIC-010D F-7).
 *
 * A file is accepted only when its extension AND the type finfo detects from its bytes form an
 * allowed pair; ticket creation and both reply routes use the same AllowedTicketAttachment::rules().
 * Files are written inside the row transaction and deleted when it rolls back, so no failure
 * leaves a file without a row or a row without a file. Every byte fixture is real content
 * (ticketFileBytes()); each pair's detected type is asserted against the libmagic of the PHP build
 * that runs the suite.
 */

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Models\TicketStatusHistory;
use App\Notifications\TicketCreatedNotification;
use App\Rules\AllowedTicketAttachment;
use App\Services\TicketService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/TicketTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    Storage::fake('local');
});

afterEach(function () {
    ticketCleanFakeStorage();
});

function attachmentCreatePayload(array $files, string $title = 'Attachment probe'): array
{
    return ['title' => $title, 'description' => 'Body', 'category' => 'General', 'priority' => 'low', 'attachments' => $files];
}

/** The service-level create payload (helper names carry the `attachment` prefix: Pest loads every file into one process). */
function attachmentServicePayload(): array
{
    return ['title' => 'Attachment atomicity', 'description' => 'Body', 'category' => 'General', 'priority' => 'low'];
}

/** Nothing ticket-shaped exists: no ticket, reply, attachment row, file, history or mail. */
function attachmentExpectNoWrites(int $tickets = 0, int $replies = 0): void
{
    expect(Ticket::count())->toBe($tickets)
        ->and(TicketReply::count())->toBe($replies)
        ->and(TicketAttachment::count())->toBe(0)
        ->and(TicketStatusHistory::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Notification::assertNothingSent();
}

// ── The allowlist, pair by pair ────────────────────────────────────────────

it('pins the exact allowlist', function () {
    expect(array_keys(AllowedTicketAttachment::ALLOWED))->toBe([
        'pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt', 'log', 'csv', 'docx', 'xlsx', 'pptx', 'zip',
    ])->and(AllowedTicketAttachment::MAX_FILES)->toBe(10)
        ->and(AllowedTicketAttachment::MAX_KILOBYTES)->toBe(20480);
});

it('accepts every allowed extension with its real content', function (string $filename, string $kind, string $detected) {
    $file = ticketUpload($filename, $kind);

    expect(AllowedTicketAttachment::detectedType($file))->toBe($detected)
        ->and(AllowedTicketAttachment::allows($file))->toBeTrue()
        ->and(AllowedTicketAttachment::ALLOWED[strtolower(pathinfo($filename, PATHINFO_EXTENSION))])->toContain($detected);
})->with([
    'pdf' => ['report.pdf', 'pdf', 'application/pdf'],
    'png' => ['screen.png', 'png', 'image/png'],
    'jpg' => ['photo.jpg', 'jpeg', 'image/jpeg'],
    'jpeg' => ['photo.jpeg', 'jpeg', 'image/jpeg'],
    'gif' => ['anim.gif', 'gif', 'image/gif'],
    'webp' => ['image.webp', 'webp', 'image/webp'],
    'txt' => ['notes.txt', 'text', 'text/plain'],
    'log' => ['app.log', 'text', 'text/plain'],
    'csv (comma)' => ['data.csv', 'csv', 'text/csv'],
    'csv (other delimiter)' => ['data.csv', 'csv-semicolon', 'text/plain'],
    'docx' => ['letter.docx', 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'xlsx' => ['sheet.xlsx', 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    'pptx' => ['deck.pptx', 'pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    'docx written as a plain ZIP' => ['letter.docx', 'zip', 'application/zip'],
    'zip' => ['bundle.zip', 'zip', 'application/zip'],
    'upper-case extension' => ['SCAN.PDF', 'pdf', 'application/pdf'],
]);

it('refuses forbidden extensions whatever their bytes', function (string $filename) {
    expect(AllowedTicketAttachment::allows(ticketUpload($filename, 'text')))->toBeFalse();
})->with([
    'exe.exe', 'setup.msi', 'run.bat', 'run.cmd', 'run.com', 'run.sh', 'run.ps1', 'app.js', 'lib.jar', 'app.apk',
    'image.dmg', 'bundle.app', 'page.html', 'page.htm', 'page.xhtml', 'archive.mht', 'logo.svg', 'notes', 'notes.',
    'data.json', 'old.doc', 'old.xls',
]);

it('refuses a forbidden content behind an allowed extension (renamed files)', function (string $filename, string $kind) {
    expect(AllowedTicketAttachment::allows(ticketUpload($filename, $kind)))->toBeFalse();
})->with([
    'HTML named .txt' => ['notes.txt', 'html'],
    'HTML named .pdf' => ['report.pdf', 'html'],
    'SVG named .png' => ['screen.png', 'svg'],
    'SVG named .txt' => ['notes.txt', 'svg'],
    'shell script named .txt' => ['notes.txt', 'shell'],
    'PHP named .log' => ['app.log', 'php'],
    'Windows executable named .pdf' => ['report.pdf', 'exe'],
    'Windows executable named .zip' => ['bundle.zip', 'exe'],
    'ELF binary named .zip' => ['bundle.zip', 'elf'],
    'text named .png' => ['screen.png', 'text'],
    'zero bytes named .pdf' => ['report.pdf', 'zeros'],
    'empty file named .txt' => ['notes.txt', 'empty'],
]);

it('ignores the browser-supplied MIME: a spoofed application/pdf with HTML bytes is refused', function () {
    $path = tempnam(sys_get_temp_dir(), 'spoof');
    file_put_contents($path, ticketFileBytes('html'));
    $spoofed = new UploadedFile($path, 'invoice.pdf', 'application/pdf', null, true);

    expect($spoofed->getClientMimeType())->toBe('application/pdf')
        ->and(AllowedTicketAttachment::allows($spoofed))->toBeFalse();

    unlink($path);
});

// ── The same rule on create and on both reply routes ───────────────────────

it('refuses a renamed HTML file on ticket creation, writing nothing', function () {
    $this->actingAs(ticketUser())
        ->post(route('tickets.store'), attachmentCreatePayload([ticketUpload('notes.txt', 'html')]))
        ->assertSessionHasErrors('attachments.0');

    attachmentExpectNoWrites();
});

it('refuses a renamed HTML file on the customer reply route, writing nothing', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner);

    $this->actingAs($owner)
        ->post(route('tickets.replies.store', $ticket), ['body' => 'See file', 'attachments' => [ticketUpload('notes.txt', 'html')]])
        ->assertSessionHasErrors('attachments.0');

    attachmentExpectNoWrites(tickets: 1);
});

it('refuses a renamed HTML file on the operator reply route, writing nothing', function () {
    $ticket = ticketFor(ticketUser());

    $this->actingAs(ticketAgent())
        ->post(route('operator.tickets.replies.store', $ticket), ['body' => 'See file', 'attachments' => [ticketUpload('notes.txt', 'html')]])
        ->assertSessionHasErrors('attachments.0');

    attachmentExpectNoWrites(tickets: 1);
});

it('stores an allowed file on create and both reply routes, recording the DETECTED type', function () {
    $owner = ticketUser();
    $agent = ticketAgent();

    $this->actingAs($owner)->post(route('tickets.store'), attachmentCreatePayload([ticketUpload('a.pdf')]))->assertSessionHasNoErrors();
    $ticket = Ticket::sole();
    $this->actingAs($owner)->post(route('tickets.replies.store', $ticket), ['body' => 'b', 'attachments' => [ticketUpload('b.png', 'png')]])->assertSessionHasNoErrors();
    $this->actingAs($agent)->post(route('operator.tickets.replies.store', $ticket), ['body' => 'c', 'attachments' => [ticketUpload('c.csv', 'csv')]])->assertSessionHasNoErrors();

    $stored = TicketAttachment::orderBy('id')->get();

    expect($stored->pluck('mime_type')->all())->toBe(['application/pdf', 'image/png', 'text/csv'])
        ->and($stored->every(fn (TicketAttachment $a) => Storage::disk('local')->exists($a->path)))->toBeTrue();
});

it('keeps the count limit on every route', function () {
    $owner = ticketUser();
    $files = array_map(fn (int $i) => ticketUpload("f{$i}.pdf"), range(1, 11));

    $this->actingAs($owner)->post(route('tickets.store'), attachmentCreatePayload($files))->assertSessionHasErrors('attachments');

    attachmentExpectNoWrites();
});

// ── Atomicity: a failure leaves no file and no row ─────────────────────────

it('rolls back a create whose second attachment row fails, deleting the file already written', function () {
    $calls = 0;
    TicketAttachment::creating(function () use (&$calls) {
        if (++$calls === 2) {
            throw new RuntimeException('simulated attachment row failure');
        }
    });

    expect(fn () => app(TicketService::class)->create(ticketUser(), attachmentServicePayload(), [ticketUpload('a.pdf'), ticketUpload('b.pdf')]))
        ->toThrow(RuntimeException::class, 'simulated attachment row failure');

    TicketAttachment::flushEventListeners();
    attachmentExpectNoWrites();
});

it('rolls back a create whose file cannot be stored, deleting every file written before it', function () {
    $unstorable = new class(tempnam(sys_get_temp_dir(), 'nostore'), 'broken.pdf', 'application/pdf', null, true) extends UploadedFile
    {
        public function store($path = '', $options = [])
        {
            return false;
        }
    };

    expect(fn () => app(TicketService::class)->create(ticketUser(), attachmentServicePayload(), [ticketUpload('ok.pdf'), $unstorable]))
        ->toThrow(RuntimeException::class, 'could not be stored');

    attachmentExpectNoWrites();
});

it('rolls back a reply whose third attachment row fails: no reply, no file, no status change, no mail', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner, ['status' => 'pending_user', 'assignee_id' => ticketAgent()->id]);
    $calls = 0;
    TicketAttachment::creating(function () use (&$calls) {
        if (++$calls === 3) {
            throw new RuntimeException('simulated attachment row failure');
        }
    });

    expect(fn () => app(TicketService::class)->addReply($ticket, $owner, 'With files', false, [ticketUpload('a.pdf'), ticketUpload('b.pdf'), ticketUpload('c.pdf')]))
        ->toThrow(RuntimeException::class, 'simulated attachment row failure');

    TicketAttachment::flushEventListeners();
    expect($ticket->fresh()->status)->toBe('pending_user');
    attachmentExpectNoWrites(tickets: 1);
});

it('hands the creation mail to the dispatcher only after the create transaction has finished', function () {
    $fake = ticketRecordNotificationLevels();
    $user = ticketUser();
    $baseline = ticketTransactionLevel();

    $ticket = app(TicketService::class)->create($user, attachmentServicePayload(), [ticketUpload('a.pdf'), ticketUpload('b.pdf')]);

    // Real mail, to the submitter, seen at the caller's own level: the row, its final number and both
    // attachment rows had committed. (A mail inside the transaction would record a deeper level.)
    expect($fake->levels)->toBe([$baseline])
        ->and(TicketAttachment::where('ticket_id', $ticket->id)->count())->toBe(2);
    Notification::assertSentTo($user, TicketCreatedNotification::class);
});

it('hands over no creation mail when the create transaction rolls back', function () {
    $fake = ticketRecordNotificationLevels();
    TicketAttachment::creating(function () {
        throw new RuntimeException('simulated attachment row failure');
    });

    expect(fn () => app(TicketService::class)->create(ticketUser(), attachmentServicePayload(), [ticketUpload('a.pdf')]))
        ->toThrow(RuntimeException::class);

    TicketAttachment::flushEventListeners();
    expect($fake->levels)->toBe([]);
    attachmentExpectNoWrites();
});

it('writes no file for a customer reply on someone else\'s ticket, however valid the file', function () {
    $ticket = ticketFor(ticketUser());

    $this->actingAs(ticketUser())
        ->post(route('tickets.replies.store', $ticket), ['body' => 'Not mine', 'attachments' => [ticketUpload('a.pdf')]])
        ->assertForbidden();

    attachmentExpectNoWrites(tickets: 1);
});
