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
 * EPIC-010D WP0 characterization: H1 (reply authorization), H2 (internal-note attachment
 * download), H3 (internal-note search oracle).
 *
 * Test names use three prefixes:
 *   BASELINE  behavior that is correct today and must survive WP1/WP2 unchanged;
 *   DEFECT Hn CURRENT BROKEN behavior, pinned only as evidence. The expectation is the
 *             vulnerability, NOT a contract; the named WP flips or replaces the test
 *             (EPIC-010D §16, "WP0 findings");
 *   ORDER     the current order of operations WP1 must re-sequence.
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

it('DEFECT H1 (WP1 flips to 403 with no side effects): an unrelated customer can reply into another user\'s ticket, store a file, and email the owner and assignee', function () {
    $owner = ticketUser();
    $assignee = ticketUser('operator');
    $attacker = ticketUser();
    $ticket = ticketFor($owner, ['assignee_id' => $assignee->id]);

    $this->actingAs($attacker)
        ->post(route('tickets.replies.store', $ticket), [
            'body' => 'Injected by an unrelated account.',
            'attachments' => [UploadedFile::fake()->create('payload.pdf', 8, 'application/pdf')],
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Reply added.');

    $reply = $ticket->replies()->sole();
    $attachment = TicketAttachment::sole();
    expect($reply->user_id)->toBe($attacker->id)
        ->and($reply->is_internal)->toBeFalse()
        ->and($attachment->reply_id)->toBe($reply->id)
        ->and($attachment->ticket_id)->toBe($ticket->id)
        ->and($attachment->user_id)->toBe($attacker->id)
        ->and($attachment->path)->toStartWith("tickets/{$ticket->id}/");
    Storage::disk('local')->assertExists($attachment->path);

    Notification::assertSentToTimes($owner, TicketRepliedNotification::class, 1);
    Notification::assertSentToTimes($assignee, TicketRepliedNotification::class, 1);
    Notification::assertSentTo($owner, TicketRepliedNotification::class,
        fn (TicketRepliedNotification $n) => $n->reply->is($reply) && $n->ticket->is($ticket));
});

it('DEFECT H1 (WP1 flips to 403): an account with no role or permission at all can reply to any ticket', function () {
    $owner = ticketUser();
    $bare = User::factory()->create();
    $ticket = ticketFor($owner);

    expect($bare->getAllPermissions())->toBeEmpty();

    $this->actingAs($bare)
        ->post(route('tickets.replies.store', $ticket), ['body' => 'No permissions needed.'])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($ticket->replies()->sole()->user_id)->toBe($bare->id);
    Notification::assertSentTo($owner, TicketRepliedNotification::class);
});

it('DEFECT H1 (WP1 flips to 403): the is_internal flag is not the gate; an unrelated customer\'s internal request is coerced public and still emails the owner', function () {
    $owner = ticketUser();
    $attacker = ticketUser();
    $ticket = ticketFor($owner);

    $this->actingAs($attacker)
        ->post(route('tickets.replies.store', $ticket), ['body' => 'Pretend internal.', 'is_internal' => '1'])
        ->assertRedirect();

    expect($ticket->replies()->sole()->is_internal)->toBeFalse();
    Notification::assertSentTo($owner, TicketRepliedNotification::class);
});

it('ORDER H1 (WP1 moves authorization first): an unauthorized request with an invalid body gets validation errors, not 403, and writes nothing', function () {
    $owner = ticketUser();
    $attacker = ticketUser();
    $ticket = ticketFor($owner);

    $this->actingAs($attacker)
        ->post(route('tickets.replies.store', $ticket), [
            'body' => '',
            'attachments' => [UploadedFile::fake()->create('x.pdf', 1, 'application/pdf')],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('body');

    expect(TicketReply::count())->toBe(0)
        ->and(TicketAttachment::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
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

it('DEFECT H2 (WP1 flips to 403): the ticket owner downloads an internal-note attachment by guessing its id', function () {
    $f = ticketWithAttachments();

    // The owner's show page hides the note and its link...
    $this->actingAs($f['owner'])->get(route('tickets.show', $f['ticket']))
        ->assertOk()
        ->assertDontSee('internal-secret.txt');

    // ...but the download route only checks Ticket view, never reply.is_internal.
    $response = $this->actingAs($f['owner'])->get(route('tickets.attachment.download', $f['internal']));

    $response->assertOk()->assertDownload('internal-secret.txt');
    expect($response->streamedContent())->toBe('INTERNAL-SECRET-BYTES');
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

it('DEFECT H2 (WP1 adds the reply→ticket integrity guard): authorization never consults the parent reply, even one on another ticket', function () {
    $f = ticketWithAttachments();
    $elsewhere = ticketFor(ticketUser());
    $foreignNote = ticketReply($elsewhere, $f['operator'], true, 'Note on another ticket');

    // A row whose ticket_id says "owner's ticket" but whose parent is an internal note elsewhere.
    // Live data has none (preflight P4b), so this pins the decision path, not a reachable state.
    $crossed = ticketAttachment($f['ticket'], $foreignNote, $f['operator'], 'crossed.txt', 'CROSSED');

    $this->actingAs($f['owner'])->get(route('tickets.attachment.download', $crossed))->assertOk();
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

it('DEFECT H3 (WP1 flips to zero results): a term that exists only in a hidden internal note makes the customer\'s ticket match', function () {
    $f = ticketSearchFixture();

    $response = $this->actingAs($f['owner'])->get(route('tickets.index', ['search' => 'INTMARKERB9']));

    // The oracle: result membership and the paginator total depend on hidden text...
    $response->assertOk()
        ->assertSee('Printer offline')
        ->assertDontSee('Mailbox quota');
    expect($response->viewData('tickets')->total())->toBe(1);

    // ...while no snippet, note body or note metadata is rendered (the list shows title, number,
    // category, priority, status, age).
    $response->assertDontSee('credit hold')->assertDontSee('do not escalate');
});

it('BASELINE H3: operator queue search includes internal-note text (intended; must survive WP1)', function () {
    $f = ticketSearchFixture();

    $response = $this->actingAs($f['operator'])->get(route('operator.tickets.index', ['search' => 'INTMARKERB9']));

    $response->assertOk()->assertSee('Printer offline')->assertDontSee('Mailbox quota');
});
