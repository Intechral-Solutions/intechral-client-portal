<?php

use App\Models\Ticket;
use App\Shared\Pilot\CustomerPilotGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/TicketTestHelpers.php';

/*
 * Release 0.9 C6-R (ADR-008): a ticket is addressed ONLY by its public reference. The numeric key opens nothing, a legacy number opens
 * nothing, and the nested attachment URL is resolved against the ticket in the URL, so a stale numeric identity after a recovery cannot
 * reach a different ticket or file. Authorization is unchanged and still decided by TicketPolicy.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Storage::fake('local');
});

afterEach(function () {
    ticketCleanFakeStorage();
});

function routingFixture(): array
{
    $owner = ticketUser();
    $stranger = ticketUser();
    $operator = ticketUser('operator');
    $ticket = ticketFor($owner);
    $other = ticketFor($stranger);
    $publicReply = ticketReply($ticket, $operator, false);
    $internalReply = ticketReply($ticket, $operator, true);

    return [
        'owner' => $owner, 'stranger' => $stranger, 'operator' => $operator, 'ticket' => $ticket, 'other' => $other,
        'first' => ticketAttachment($ticket, null, $owner, 'first.txt', 'first-bytes'),
        'reply' => ticketAttachment($ticket, $publicReply, $operator, 'reply.txt', 'reply-bytes'),
        'internal' => ticketAttachment($ticket, $internalReply, $operator, 'internal.txt', 'internal-bytes'),
        'foreign' => ticketAttachment($other, null, $stranger, 'foreign.txt', 'foreign-bytes'),
    ];
}

// ── Ticket routes ────────────────────────────────────────────────────────────

it('resolves a ticket only by its canonical reference', function () {
    $f = routingFixture();
    $reference = $f['ticket']->ticket_number;

    $this->actingAs($f['owner'])->get("/tickets/{$reference}")->assertOk();
    $this->actingAs($f['owner'])->get('/tickets/'.strtolower($reference))->assertOk();

    foreach ([(string) $f['ticket']->id, 'TKT-'.str_pad((string) $f['ticket']->id, 4, '0', STR_PAD_LEFT), substr($reference, 3), str_replace('-', '', $reference), 'TK-ZZZZZ-ZZZZZ'] as $wrong) {
        $this->actingAs($f['owner'])->get("/tickets/{$wrong}")->assertNotFound();
    }
});

it('addresses every ticket-scoped action by reference and refuses the numeric key on all of them', function () {
    $f = routingFixture();
    $id = $f['ticket']->id;
    $reference = $f['ticket']->ticket_number;

    $this->actingAs($f['operator'])->get("/operator/tickets/{$reference}")->assertOk();
    $this->actingAs($f['operator'])->get("/operator/tickets/{$id}")->assertNotFound();
    $this->actingAs($f['operator'])->put("/operator/tickets/{$id}/status", ['status' => 'closed'])->assertNotFound();
    $this->actingAs($f['operator'])->put("/operator/tickets/{$id}/assign", ['assignee_id' => $f['operator']->id])->assertNotFound();
    $this->actingAs($f['operator'])->post("/operator/tickets/{$id}/replies", ['body' => 'x'])->assertNotFound();
    $this->actingAs($f['owner'])->post("/tickets/{$id}/replies", ['body' => 'x'])->assertNotFound();

    expect($f['ticket']->fresh()->status)->toBe('open');
    $this->actingAs($f['owner'])->post("/tickets/{$reference}/replies", ['body' => 'a reply'])->assertRedirect();
    expect($f['ticket']->replies()->where('body', 'a reply')->exists())->toBeTrue();
});

it('refuses a spelling that is not a reference before it touches the tickets table', function () {
    $f = routingFixture();

    DB::enableQueryLog();
    $this->actingAs($f['owner'])->get('/tickets/'.$f['ticket']->id)->assertNotFound();
    $touched = collect(DB::getQueryLog())->contains(fn ($q) => str_contains($q['query'], 'from `tickets`'));
    DB::disableQueryLog();

    expect($touched)->toBeFalse();
});

it('keeps authorization unchanged: another customer holding a valid reference is refused', function () {
    $f = routingFixture();

    $this->actingAs($f['stranger'])->get('/tickets/'.$f['ticket']->ticket_number)->assertForbidden();
});

it('does not carry the numeric key in any requester prop', function () {
    $f = routingFixture();

    $this->actingAs($f['owner'])->get(route('tickets.index'))->assertInertia(fn (Assert $page) => $page
        ->has('tickets.data', 1, fn (Assert $row) => $row->missing('id')->where('number', $f['ticket']->ticket_number)->where('href', route('tickets.show', $f['ticket']))->etc()));

    $this->actingAs($f['owner'])->get(route('tickets.show', $f['ticket']))->assertInertia(fn (Assert $page) => $page
        ->has('ticket', fn (Assert $ticket) => $ticket->missing('id')->where('number', $f['ticket']->ticket_number)->etc())
        ->has('conversation.0.attachments.0', fn (Assert $a) => $a->where('href', route('tickets.attachment.download', [$f['ticket'], $f['first']]))->etc()));
});

it('finds a ticket by its reference typed any plausible way', function () {
    $f = routingFixture();
    $owner = $f['owner'];
    $reference = $f['ticket']->ticket_number;
    $body = substr($reference, 3);

    foreach ([$reference, strtolower($reference), str_replace('-', ' ', $reference), $body, strtolower(str_replace('-', '', $body)), substr($reference, 0, 8)] as $term) {
        expect(Ticket::search($term)->pluck('id')->all())->toContain($f['ticket']->id);
    }
    expect(Ticket::search('TK-ZZZZZ-ZZZZZ')->pluck('id')->all())->not->toContain($f['ticket']->id);
});

// ── Attachment downloads ─────────────────────────────────────────────────────

it('downloads the first message\'s files and a public reply\'s files through the ticket reference', function () {
    $f = routingFixture();
    $reference = $f['ticket']->ticket_number;

    foreach (['first' => 'first-bytes', 'reply' => 'reply-bytes'] as $key => $bytes) {
        $url = "/tickets/{$reference}/attachments/{$f[$key]->id}";
        $response = $this->actingAs($f['owner'])->get($url)->assertOk();
        expect($response->streamedContent())->toBe($bytes);
    }
});

it('keeps the internal-note attachment policy: refused to the requester, open to the operator', function () {
    $f = routingFixture();
    $url = "/tickets/{$f['ticket']->ticket_number}/attachments/{$f['internal']->id}";

    $this->actingAs($f['owner'])->get($url)->assertForbidden();
    $this->actingAs($f['stranger'])->get($url)->assertForbidden();
    $this->actingAs($f['operator'])->get($url)->assertOk();
});

it('refuses an attachment of another ticket under this ticket\'s reference (404), whoever asks', function () {
    $f = routingFixture();

    // The operator may see both tickets; the attachment still is not THIS ticket's.
    $this->actingAs($f['operator'])->get("/tickets/{$f['ticket']->ticket_number}/attachments/{$f['foreign']->id}")->assertNotFound();
    $this->actingAs($f['owner'])->get("/tickets/{$f['ticket']->ticket_number}/attachments/{$f['foreign']->id}")->assertNotFound();
    $this->actingAs($f['stranger'])->get("/tickets/{$f['other']->ticket_number}/attachments/{$f['first']->id}")->assertNotFound();
    $this->actingAs($f['stranger'])->get("/tickets/{$f['other']->ticket_number}/attachments/{$f['foreign']->id}")->assertOk();
});

it('has no numeric-only attachment URL any more', function () {
    $f = routingFixture();

    foreach (['first', 'reply', 'internal', 'foreign'] as $key) {
        $this->actingAs($f['operator'])->get("/attachments/{$f[$key]->id}/download")->assertNotFound();
    }
});

it('refuses a non-numeric attachment segment, a numeric ticket segment and an anonymous caller', function () {
    $f = routingFixture();
    $reference = $f['ticket']->ticket_number;

    $this->actingAs($f['owner'])->get("/tickets/{$reference}/attachments/abc")->assertNotFound();
    $this->actingAs($f['owner'])->get("/tickets/{$reference}/attachments/99999999")->assertNotFound();
    $this->actingAs($f['owner'])->get("/tickets/{$f['ticket']->id}/attachments/{$f['first']->id}")->assertNotFound();
    auth()->forgetGuards();
    $this->get("/tickets/{$reference}/attachments/{$f['first']->id}")->assertRedirect('/login');
});

it('keeps the attachment route name inside the customer pilot allowlist', function () {
    expect(route('tickets.attachment.download', ['TK-ABCDE-FGHJK', 5], false))->toBe('/tickets/TK-ABCDE-FGHJK/attachments/5')
        ->and(CustomerPilotGate::PILOT_ROUTES)->toHaveKey('tickets.attachment.download');
});
