<?php

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\TicketRepliedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/TicketTestHelpers.php';

/*
 * EPIC-010D: H1 (reply authorization), H2 (internal-note attachment download), H3
 * (internal-note search oracle). WP0 characterized them; WP1 converted every defect test into
 * the TARGET contract below (the pre-fix evidence is recorded in EPIC-010D Amendment 1).
 *
 * Test names use two prefixes:
 *   BASELINE  behavior that was already correct and must survive unchanged;
 *   TARGET Hn the security contract WP1 established (written red, then fixed).
 *
 * Mail and storage are always faked: Notification::fake() and Storage::fake('local').
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    Storage::fake('local');
});

afterEach(function () {
    ticketCleanFakeStorage();
});

// ── H1 — reply authorization ────────────────────────────────────────────────

it('BASELINE H1: the owner can reply with an attachment, and only the assignee is notified', function () {
    $owner = ticketUser();
    $operator = ticketUser('operator');
    $ticket = ticketFor($owner, ['assignee_id' => $operator->id]);

    $this->actingAs($owner)
        ->post(route('tickets.replies.store', $ticket), [
            'body' => 'More detail from the owner.',
            'attachments' => [UploadedFile::fake()->create('owner.pdf', 12, 'application/pdf')],
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Reply added.');

    $reply = $ticket->replies()->sole();
    expect($reply->user_id)->toBe($owner->id)
        ->and($reply->is_internal)->toBeFalse()
        ->and($reply->attachments()->sole()->filename)->toBe('owner.pdf');
    Storage::disk('local')->assertExists($reply->attachments()->sole()->path);

    Notification::assertNotSentTo($owner, TicketRepliedNotification::class);
    Notification::assertSentToTimes($operator, TicketRepliedNotification::class, 1);
});

it('BASELINE H1: an operator public reply notifies the owner and a different assignee', function () {
    $owner = ticketUser();
    $operator = ticketUser('operator');
    $assignee = ticketUser('operator');
    $ticket = ticketFor($owner, ['assignee_id' => $assignee->id]);

    $this->actingAs($operator)
        ->post(route('operator.tickets.replies.store', $ticket), ['body' => 'Looking into it.'])
        ->assertRedirect();

    expect($ticket->replies()->sole()->is_internal)->toBeFalse();
    Notification::assertSentToTimes($owner, TicketRepliedNotification::class, 1);
    Notification::assertSentToTimes($assignee, TicketRepliedNotification::class, 1);
    Notification::assertNotSentTo($operator, TicketRepliedNotification::class);
});

it('BASELINE H1: an operator internal note is stored internal with its attachment and notifies nobody', function () {
    $owner = ticketUser();
    $operator = ticketUser('operator');
    $assignee = ticketUser('operator');
    $ticket = ticketFor($owner, ['assignee_id' => $assignee->id]);

    $this->actingAs($operator)
        ->post(route('operator.tickets.replies.store', $ticket), [
            'body' => 'Internal only.',
            'is_internal' => '1',
            'attachments' => [UploadedFile::fake()->create('internal.txt', 1, 'text/plain')],
        ])
        ->assertRedirect();

    $reply = $ticket->replies()->sole();
    expect($reply->is_internal)->toBeTrue()
        ->and($reply->attachments()->count())->toBe(1);
    Notification::assertNothingSent();
});

it('BASELINE H1: a non-operator asking for is_internal gets a public reply (coerced, not rejected)', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner);

    $this->actingAs($owner)
        ->post(route('tickets.replies.store', $ticket), ['body' => 'Trying internal.', 'is_internal' => '1'])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($ticket->replies()->sole()->is_internal)->toBeFalse();
});

it('BASELINE H1: the operator reply route already refuses customers and writes nothing', function () {
    $owner = ticketUser();
    $customer = ticketUser();
    $ticket = ticketFor($owner);

    $this->actingAs($customer)
        ->post(route('operator.tickets.replies.store', $ticket), [
            'body' => 'Via the operator route.',
            'attachments' => [UploadedFile::fake()->create('x.pdf', 1, 'application/pdf')],
        ])
        ->assertForbidden();

    expect(TicketReply::count())->toBe(0)
        ->and(TicketAttachment::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Notification::assertNothingSent();
});

it('TARGET H1: an unrelated account is refused before validation, whatever it sends, and nothing is written, stored or sent', function (string $kind, array $payload) {
    $owner = ticketUser();
    $assignee = ticketUser('operator');
    $ticket = ticketFor($owner, ['assignee_id' => $assignee->id]);
    $attacker = $kind === 'unrelated customer' ? ticketUser() : User::factory()->create();
    $payload = array_map(fn ($value) => $value === 'FILE' ? [UploadedFile::fake()->create('payload.pdf', 8, 'application/pdf')] : $value, $payload);

    $this->actingAs($attacker)
        ->post(route('tickets.replies.store', $ticket), $payload)
        ->assertForbidden()
        ->assertSessionHasNoErrors();

    expect(TicketReply::count())->toBe(0)
        ->and(TicketAttachment::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Notification::assertNothingSent();
})->with(['unrelated customer', 'role-less account'])->with([
    'valid body' => [['body' => 'Injected by an unrelated account.']],
    'empty body' => [['body' => '']],
    'missing body' => [[]],
    'valid body with attachment' => [['body' => 'With payload.', 'attachments' => 'FILE']],
    'invalid body with attachment' => [['body' => '', 'attachments' => 'FILE']],
    'internal request' => [['body' => 'Pretend internal.', 'is_internal' => '1']],
]);

it('TARGET H1: a tickets.assign agent without the operator role replies publicly on the user route and internally on the operator route', function () {
    $owner = ticketUser();
    $agent = ticketAgent();
    $ticket = ticketFor($owner);

    $this->actingAs($agent)
        ->post(route('tickets.replies.store', $ticket), ['body' => 'Agent public reply.'])
        ->assertRedirect()
        ->assertSessionHas('status', 'Reply added.');
    $this->actingAs($agent)
        ->post(route('operator.tickets.replies.store', $ticket), ['body' => 'Agent internal note.', 'is_internal' => '1'])
        ->assertRedirect()
        ->assertSessionHas('status', 'Reply added.');

    expect($ticket->replies()->where('body', 'Agent public reply.')->sole()->is_internal)->toBeFalse()
        ->and($ticket->replies()->where('body', 'Agent internal note.')->sole()->is_internal)->toBeTrue();
    Notification::assertSentToTimes($owner, TicketRepliedNotification::class, 1);
});

it('TARGET H1: the operator reply route (internal notes) still refuses the ticket owner at its tickets.assign gate', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner);

    $this->actingAs($owner)
        ->post(route('operator.tickets.replies.store', $ticket), ['body' => 'Owner via operator route', 'is_internal' => '1'])
        ->assertForbidden();

    expect(TicketReply::count())->toBe(0);
    Notification::assertNothingSent();
});

it('BASELINE H1: replying to a missing ticket id is a 404', function () {
    $this->actingAs(ticketUser())
        ->post(route('tickets.replies.store', ['ticket' => 999_999_999]), ['body' => 'x'])
        ->assertNotFound();
});

// ── H2 — attachment download ────────────────────────────────────────────────

/** Ticket with a body attachment, a public reply attachment and an internal-note attachment. */
function ticketWithAttachments(): array
{
    $owner = ticketUser();
    $operator = ticketUser('operator');
    $ticket = ticketFor($owner);

    $public = ticketReply($ticket, $operator, false, 'Public reply');
    $internal = ticketReply($ticket, $operator, true, 'Internal note');

    return [
        'owner' => $owner,
        'operator' => $operator,
        'ticket' => $ticket,
        'body' => ticketAttachment($ticket, null, $owner, 'body.txt', 'BODY-BYTES'),
        'public' => ticketAttachment($ticket, $public, $operator, 'public.txt', 'PUBLIC-BYTES'),
        'internal' => ticketAttachment($ticket, $internal, $operator, 'internal-secret.txt', 'INTERNAL-SECRET-BYTES'),
    ];
}

it('BASELINE H2: the owner downloads ticket-body and public-reply attachments by filename, without the storage path', function () {
    $f = ticketWithAttachments();

    foreach (['body' => 'BODY-BYTES', 'public' => 'PUBLIC-BYTES'] as $key => $bytes) {
        $response = $this->actingAs($f['owner'])->get(route('tickets.attachment.download', $f[$key]));

        $response->assertOk()->assertDownload($f[$key]->filename);
        expect($response->streamedContent())->toBe($bytes)
            ->and((string) $response->headers->get('Content-Disposition'))->not->toContain('tickets/');
    }
});

it('BASELINE H2: an operator downloads an internal-note attachment', function () {
    $f = ticketWithAttachments();

    $response = $this->actingAs($f['operator'])->get(route('tickets.attachment.download', $f['internal']));

    $response->assertOk()->assertDownload('internal-secret.txt');
    expect($response->streamedContent())->toBe('INTERNAL-SECRET-BYTES');
});

it('TARGET H2: attachment download follows Ticket, then parent reply, then attachment visibility for every actor', function () {
    $f = ticketWithAttachments();
    $actors = [
        'owner' => $f['owner'],
        'operator' => $f['operator'],
        'agent' => ticketAgent(),
        'unrelated customer' => ticketUser(),
    ];
    $expected = [
        'owner' => ['body' => 200, 'public' => 200, 'internal' => 403],
        'operator' => ['body' => 200, 'public' => 200, 'internal' => 200],
        'agent' => ['body' => 200, 'public' => 200, 'internal' => 200],
        'unrelated customer' => ['body' => 403, 'public' => 403, 'internal' => 403],
    ];

    $actual = [];
    foreach ($actors as $name => $actor) {
        foreach (['body', 'public', 'internal'] as $key) {
            $actual[$name][$key] = $this->actingAs($actor)->get(route('tickets.attachment.download', $f[$key]))->getStatusCode();
        }
    }

    expect($actual)->toBe($expected);
});

it('TARGET H2: the ticket owner cannot fetch an internal-note attachment by guessing its id, and the refusal carries no file bytes', function () {
    $f = ticketWithAttachments();

    $this->actingAs($f['owner'])->get(route('tickets.show', $f['ticket']))
        ->assertOk()
        ->assertDontSee('internal-secret.txt');

    $response = $this->actingAs($f['owner'])->get(route('tickets.attachment.download', $f['internal']));

    $response->assertForbidden();
    expect($response->headers->get('Content-Disposition'))->toBeNull()
        ->and($response->getContent())->not->toContain('INTERNAL-SECRET-BYTES');
});

it('BASELINE H2: an unrelated customer is refused every attachment of a foreign ticket (Ticket view is checked)', function () {
    $f = ticketWithAttachments();
    $customer = ticketUser();

    foreach (['body', 'public', 'internal'] as $key) {
        $this->actingAs($customer)->get(route('tickets.attachment.download', $f[$key]))->assertForbidden();
    }
});

it('BASELINE H2: existence answers are 403 for an existing foreign id, 404 for an unknown id, and 404 for an authorized row whose file is gone', function () {
    $f = ticketWithAttachments();

    $this->actingAs(ticketUser())->get(route('tickets.attachment.download', $f['public']))->assertForbidden();
    $this->actingAs(ticketUser())->get(route('tickets.attachment.download', ['attachment' => 999_999_999]))->assertNotFound();

    Storage::disk('local')->delete($f['public']->path);
    $this->actingAs($f['owner'])->get(route('tickets.attachment.download', $f['public']))->assertNotFound();
});

it('TARGET H2: an attachment whose parent reply belongs to another ticket is refused to everyone, operators included', function () {
    $f = ticketWithAttachments();
    $elsewhere = ticketFor(ticketUser());
    $foreignNote = ticketReply($elsewhere, $f['operator'], true, 'Note on another ticket');
    $foreignPublic = ticketReply($elsewhere, $f['operator'], false, 'Public reply on another ticket');

    // Rows whose ticket_id says "owner's ticket" but whose parent reply lives elsewhere. Live data
    // has none (preflight P4b); the guard makes the parent relationship authoritative anyway.
    $crossedInternal = ticketAttachment($f['ticket'], $foreignNote, $f['operator'], 'crossed.txt', 'CROSSED');
    $crossedPublic = ticketAttachment($f['ticket'], $foreignPublic, $f['operator'], 'crossed-public.txt', 'CROSSED');

    foreach ([$f['owner'], $f['operator'], ticketAgent()] as $actor) {
        $this->actingAs($actor)->get(route('tickets.attachment.download', $crossedInternal))->assertForbidden();
        $this->actingAs($actor)->get(route('tickets.attachment.download', $crossedPublic))->assertForbidden();
    }
});

it('BASELINE H2: the served local disk does not bypass the download route (unsigned /storage URLs are refused)', function () {
    $f = ticketWithAttachments();

    $this->actingAs($f['owner'])->get('/storage/'.$f['internal']->path)->assertForbidden();
    $this->actingAs($f['operator'])->get('/storage/'.$f['internal']->path)->assertForbidden();
});

// ── H3 — internal-note search oracle ────────────────────────────────────────

/** Owner with two tickets: one carries public marker A and internal marker B, the other neither. */
function ticketSearchFixture(): array
{
    $owner = ticketUser();
    $operator = ticketUser('operator');
    $marked = ticketFor($owner, ['title' => 'Printer offline', 'description' => 'Nothing special']);
    $control = ticketFor($owner, ['title' => 'Mailbox quota', 'description' => 'Nothing special']);

    ticketReply($marked, $operator, false, 'Public answer PUBMARKERA7 applied.');
    ticketReply($marked, $operator, true, 'INTMARKERB9 customer is on credit hold, do not escalate.');

    return compact('owner', 'operator', 'marked', 'control');
}

it('BASELINE H3: customer search matches public reply text on their own tickets only', function () {
    $f = ticketSearchFixture();
    ticketReply(ticketFor(ticketUser(), ['title' => 'Stranger ticket']), $f['operator'], false, 'PUBMARKERA7 elsewhere');

    $response = $this->actingAs($f['owner'])->get(route('tickets.index', ['search' => 'PUBMARKERA7']));

    $response->assertOk()
        ->assertSee('Printer offline')
        ->assertDontSee('Mailbox quota')
        ->assertDontSee('Stranger ticket');
    expect($response->viewData('tickets')->total())->toBe(1);
});

it('TARGET H3: a term that exists only in a hidden internal note matches nothing for the customer, and the count shows it', function () {
    $f = ticketSearchFixture();

    $response = $this->actingAs($f['owner'])->get(route('tickets.index', ['search' => 'INTMARKERB9']));

    $response->assertOk()
        ->assertDontSee('Printer offline')
        ->assertDontSee('Mailbox quota')
        ->assertSee('No tickets found.')
        ->assertDontSee('credit hold');
    expect($response->viewData('tickets')->total())->toBe(0);
});

it('TARGET H3: the search scope excludes internal notes unless the caller opts in', function () {
    $f = ticketSearchFixture();

    expect(Ticket::search('INTMARKERB9')->pluck('id')->all())->toBe([])
        ->and(Ticket::search('INTMARKERB9', false)->pluck('id')->all())->toBe([])
        ->and(Ticket::search('INTMARKERB9', true)->pluck('id')->all())->toBe([$f['marked']->id])
        ->and(Ticket::search('PUBMARKERA7')->pluck('id')->all())->toBe([$f['marked']->id]);
});

it('BASELINE H3: operator queue search includes internal-note text (explicit opt-in)', function () {
    $f = ticketSearchFixture();

    $response = $this->actingAs($f['operator'])->get(route('operator.tickets.index', ['search' => 'INTMARKERB9']));

    $response->assertOk()->assertSee('Printer offline')->assertDontSee('Mailbox quota');
});
