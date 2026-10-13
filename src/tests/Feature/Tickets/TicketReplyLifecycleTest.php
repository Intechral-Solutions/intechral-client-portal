<?php

/*
 * EPIC-017 WP2 step 3 — the O2 reply lifecycle (§13, §37) and the transaction it runs in.
 *
 * A CUSTOMER reply (an author without `tickets.assign`, whatever route or shell) moves
 * `pending_user` → `in_progress` and `resolved` → `open`, with a history row naming the customer,
 * in the same transaction as the reply; on `open` / `in_progress` it changes nothing; on `closed`
 * it is refused before anything is written. An OPERATOR reply never changes status and is accepted
 * on any status. Decisions use the committed, locked row, never the caller's model. Mail goes out
 * only after commit. Real multi-process races are TicketReplyRaceTest.
 */

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Models\TicketStatusHistory;
use App\Notifications\TicketRepliedNotification;
use App\Services\TicketService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/TicketTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    Storage::fake('local');
});

afterEach(function () {
    ticketCleanFakeStorage();
});

/** Everything a reply could have written, as raw stored values, so a refusal can be shown to write nothing. */
function lifecycleSnapshot(Ticket $ticket): array
{
    return [
        'ticket' => Ticket::whereKey($ticket->id)->sole()->getRawOriginal(),
        'replies' => TicketReply::count(),
        'attachments' => TicketAttachment::count(),
        'histories' => TicketStatusHistory::count(),
        'files' => Storage::disk('local')->allFiles(),
    ];
}

/** @return array<int, array{int|null, string, string}> */
function lifecycleHistory(Ticket $ticket): array
{
    return TicketStatusHistory::where('ticket_id', $ticket->id)->orderBy('id')->get()
        ->map(fn (TicketStatusHistory $h) => [$h->user_id, $h->old_status, $h->new_status])->all();
}

// ── Customer replies ───────────────────────────────────────────────────────

it('applies the O2 transition of a customer reply, with a history row naming the customer', function (string $from, string $to) {
    $owner = ticketUser();
    $agent = ticketAgent();
    $ticket = ticketFor($owner, ['status' => $from, 'assignee_id' => $agent->id, 'resolved_at' => $from === 'resolved' ? now()->subHour() : null]);

    $this->actingAs($owner)
        ->post(route('tickets.replies.store', $ticket), ['body' => 'Customer follow-up'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $ticket->refresh();
    expect($ticket->status)->toBe($to)
        ->and(TicketReply::where('ticket_id', $ticket->id)->sole()->only(['user_id', 'is_internal']))->toBe(['user_id' => $owner->id, 'is_internal' => false])
        ->and(lifecycleHistory($ticket))->toBe($from === $to ? [] : [[$owner->id, $from, $to]]);

    if ($from === 'resolved') {
        // Reopening clears the resolution, so the 72-hour auto-close clock no longer applies.
        expect($ticket->resolved_at)->toBeNull();
    }

    Notification::assertSentToTimes($agent, TicketRepliedNotification::class, 1);
})->with([
    'waiting on the customer → in progress' => ['pending_user', 'in_progress'],
    'resolved → open' => ['resolved', 'open'],
    'open stays open' => ['open', 'open'],
    'in progress stays in progress' => ['in_progress', 'in_progress'],
]);

it('refuses a customer reply on a closed ticket before writing anything', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner, ['status' => 'closed', 'closed_at' => now()->subDay(), 'assignee_id' => ticketAgent()->id]);
    $before = lifecycleSnapshot($ticket);

    $this->actingAs($owner)
        ->post(route('tickets.replies.store', $ticket), ['body' => 'Is anyone there?', 'attachments' => [ticketUpload('a.pdf'), ticketUpload('b.png', 'png')]])
        ->assertRedirect()
        ->assertSessionHasErrors(['body' => TicketService::CLOSED_REPLY_MESSAGE]);

    expect(lifecycleSnapshot($ticket))->toBe($before);
    Notification::assertNothingSent();
});

it('refuses a closed-ticket customer reply before ANY write is even attempted, not merely rolled back', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner, ['status' => 'closed', 'closed_at' => now()]);
    $storeCalls = 0;
    $counting = new class(tempnam(sys_get_temp_dir(), 'count'), 'a.pdf', 'application/pdf', null, true) extends UploadedFile
    {
        public $onStore;

        public function store($path = '', $options = [])
        {
            ($this->onStore)();

            return parent::store($path, $options);
        }
    };
    $counting->onStore = function () use (&$storeCalls) {
        $storeCalls++;
    };
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    expect(fn () => app(TicketService::class)->addReply($ticket, $owner, 'Hello?', false, [$counting]))
        ->toThrow(ValidationException::class);

    // Compensation would hide a write that was rolled back; this asserts none was ever issued.
    expect($storeCalls)->toBe(0)
        ->and($writes)->toBe([]);
});

it('answers a JSON customer reply on a closed ticket with 422 and the customer-safe message', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner, ['status' => 'closed', 'closed_at' => now()]);
    $before = lifecycleSnapshot($ticket);

    $this->actingAs($owner)
        ->postJson(route('tickets.replies.store', $ticket), ['body' => 'Hello?'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body' => TicketService::CLOSED_REPLY_MESSAGE]);

    expect(lifecycleSnapshot($ticket))->toBe($before);
    Notification::assertNothingSent();
});

it('decides on the COMMITTED status: a stale model that still says resolved cannot sneak a reply onto a closed ticket', function () {
    $owner = ticketUser();
    $stale = ticketFor($owner, ['status' => 'resolved', 'resolved_at' => now()->subDay()]);
    app(TicketService::class)->transition(Ticket::findOrFail($stale->id), ticketAgent(), 'closed');
    $before = lifecycleSnapshot($stale);

    expect($stale->status)->toBe('resolved')
        ->and(fn () => app(TicketService::class)->addReply($stale, $owner, 'Late reply', false, [ticketUpload('a.pdf')]))
        ->toThrow(ValidationException::class);

    expect(lifecycleSnapshot($stale))->toBe($before);
    Notification::assertNothingSent();
});

it('decides on the COMMITTED status: a stale model that says open still moves a waiting ticket', function () {
    $owner = ticketUser();
    $stale = ticketFor($owner, ['status' => 'open']);
    Ticket::whereKey($stale->id)->update(['status' => 'pending_user']);

    app(TicketService::class)->addReply($stale, $owner, 'Here is the answer', false);

    expect(Ticket::findOrFail($stale->id)->status)->toBe('in_progress')
        ->and($stale->status)->toBe('in_progress')
        ->and(lifecycleHistory($stale))->toBe([[$owner->id, 'pending_user', 'in_progress']]);
});

// ── Operator replies: unchanged ────────────────────────────────────────────

it('never transitions on an operator reply, and accepts one on any status, closed included', function (string $status) {
    $owner = ticketUser();
    $agent = ticketAgent();
    $ticket = ticketFor($owner, ['status' => $status]);

    $this->actingAs($agent)
        ->post(route('operator.tickets.replies.store', $ticket), ['body' => 'Operator note to the customer'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($ticket->fresh()->status)->toBe($status)
        ->and(TicketReply::where('ticket_id', $ticket->id)->count())->toBe(1)
        ->and(lifecycleHistory($ticket))->toBe([]);
})->with(['open', 'in_progress', 'pending_user', 'resolved', 'closed']);

it('decides by capability, not by route: an operator replying to their OWN request on /tickets causes no transition', function (string $status) {
    $agent = ticketAgent();
    $ticket = ticketFor($agent, ['status' => $status]);

    $this->actingAs($agent)
        ->post(route('tickets.replies.store', $ticket), ['body' => 'Replying on my own request'])
        ->assertSessionHasNoErrors();

    expect($ticket->fresh()->status)->toBe($status)
        ->and(lifecycleHistory($ticket))->toBe([]);
})->with(['pending_user', 'resolved', 'closed']);

// ── One transaction; mail after commit ─────────────────────────────────────

it('commits the reply, its files and the transition together: a history failure rolls all of it back', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner, ['status' => 'pending_user', 'assignee_id' => ticketAgent()->id]);
    $before = lifecycleSnapshot($ticket);
    TicketStatusHistory::creating(function () {
        throw new RuntimeException('simulated history failure');
    });

    expect(fn () => app(TicketService::class)->addReply($ticket, $owner, 'With files', false, [ticketUpload('a.pdf'), ticketUpload('b.pdf')]))
        ->toThrow(RuntimeException::class, 'simulated history failure');

    TicketStatusHistory::flushEventListeners();
    expect(lifecycleSnapshot($ticket))->toBe($before);
    Notification::assertNothingSent();
});

it('records the transaction level at dispatch, so the checks below can see mail sent inside a transaction', function () {
    $fake = ticketRecordNotificationLevels();
    $agent = ticketAgent();
    $ticket = ticketFor(ticketUser());
    $reply = ticketReply($ticket, ticketUser(), false);
    $baseline = ticketTransactionLevel();

    DB::transaction(fn () => $agent->notify(new TicketRepliedNotification($ticket, $reply)));
    $agent->notify(new TicketRepliedNotification($ticket, $reply));

    expect($fake->levels)->toBe([$baseline + 1, $baseline]);
});

it('hands reply mail to the dispatcher only after the reply transaction has finished, on every reply path', function (string $author, string $status, bool $transitions) {
    $fake = ticketRecordNotificationLevels();
    $owner = ticketUser();
    $agent = ticketAgent();
    $ticket = ticketFor($owner, ['status' => $status, 'assignee_id' => $agent->id]);
    $baseline = ticketTransactionLevel();

    app(TicketService::class)->addReply($ticket, $author === 'customer' ? $owner : $agent, 'Answer', false, [ticketUpload('a.pdf')]);

    // The mail is real (the recipient is the other party) and every dispatch saw the caller's own
    // level: the reply, its file, its status change and its history row had all committed.
    expect($fake->levels)->toBe([$baseline])
        ->and($ticket->fresh()->status)->toBe($transitions ? ($status === 'resolved' ? 'open' : 'in_progress') : $status);
    Notification::assertSentTo($author === 'customer' ? $agent : $owner, TicketRepliedNotification::class);
})->with([
    'customer reply that moves pending_user' => ['customer', 'pending_user', true],
    'customer reply that reopens resolved' => ['customer', 'resolved', true],
    'customer reply that changes nothing' => ['customer', 'open', false],
    'operator reply' => ['operator', 'in_progress', false],
]);

it('hands reply mail over HTTP to the dispatcher only after the transaction has finished', function () {
    $fake = ticketRecordNotificationLevels();
    $owner = ticketUser();
    $ticket = ticketFor($owner, ['status' => 'pending_user', 'assignee_id' => ticketAgent()->id]);
    $baseline = ticketTransactionLevel();

    $this->actingAs($owner)->post(route('tickets.replies.store', $ticket), ['body' => 'Answer'])->assertRedirect();

    expect($fake->levels)->toBe([$baseline]);
});

it('hands over no mail at all when the reply transaction rolls back', function () {
    $fake = ticketRecordNotificationLevels();
    $owner = ticketUser();
    $ticket = ticketFor($owner, ['status' => 'pending_user', 'assignee_id' => ticketAgent()->id]);
    TicketStatusHistory::creating(function () {
        throw new RuntimeException('simulated history failure');
    });

    expect(fn () => app(TicketService::class)->addReply($ticket, $owner, 'Answer', false))->toThrow(RuntimeException::class);

    TicketStatusHistory::flushEventListeners();
    expect($fake->levels)->toBe([]);
});

// ── Stale operator transitions ─────────────────────────────────────────────

it('validates an operator transition against the committed status, not a stale model', function () {
    $ticket = ticketFor(ticketUser(), ['status' => 'open']);
    $stale = Ticket::findOrFail($ticket->id);
    app(TicketService::class)->transition(Ticket::findOrFail($ticket->id), ticketAgent(), 'closed');
    $before = lifecycleSnapshot($ticket);

    // `open → pending_user` is valid from what the stale model shows, but not from `closed`.
    expect(fn () => app(TicketService::class)->transition($stale, ticketAgent(), 'pending_user'))
        ->toThrow(ValidationException::class);

    expect(lifecycleSnapshot($ticket))->toBe($before);
});

it('records the committed old status when a stale operator transition is still valid', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner, ['status' => 'resolved', 'resolved_at' => now()->subDay()]);
    $stale = Ticket::findOrFail($ticket->id);
    app(TicketService::class)->addReply(Ticket::findOrFail($ticket->id), $owner, 'Reopening', false);
    $agent = ticketAgent();

    app(TicketService::class)->transition($stale, $agent, 'closed');

    expect(lifecycleHistory($ticket))->toBe([
        [$owner->id, 'resolved', 'open'],
        [$agent->id, 'open', 'closed'],
    ])->and($stale->status)->toBe('closed');
});

// ── Auto-close vs a customer reopen ────────────────────────────────────────

it('does not auto-close a ticket a customer reopened after the candidates were read', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner, ['status' => 'resolved', 'resolved_at' => now()->subHours(100)]);
    $reopened = false;

    // Deterministic interleaving: the reply lands right after auto-close has read its candidate ids
    // and before it writes, exactly the window a concurrent request could hit.
    DB::listen(function ($query) use (&$reopened, $ticket, $owner) {
        if (! $reopened && str_starts_with($query->sql, 'select `id` from `tickets` where `status` = ?')) {
            $reopened = true;
            app(TicketService::class)->addReply(Ticket::findOrFail($ticket->id), $owner, 'Wait, not fixed', false);
        }
    });

    $closed = app(TicketService::class)->autoCloseResolved(72);

    expect($reopened)->toBeTrue()
        ->and($closed)->toBe(0)
        ->and($ticket->fresh()->only(['status', 'closed_at']))->toBe(['status' => 'open', 'closed_at' => null])
        ->and(lifecycleHistory($ticket))->toBe([[$owner->id, 'resolved', 'open']]);
});

it('does not auto-close a ticket that was reopened and resolved again after the candidates were read', function () {
    $owner = ticketUser();
    $agent = ticketAgent();
    $ticket = ticketFor($owner, ['status' => 'resolved', 'resolved_at' => now()->subHours(100)]);
    $interleaved = false;

    // Same window as above, but the ticket is back in `resolved` by the time auto-close writes, with
    // a fresh `resolved_at`. Only the resolved_at half of the compare-and-set can tell it from the
    // stale candidate, so a status-only guard would close a ticket resolved moments ago.
    DB::listen(function ($query) use (&$interleaved, $ticket, $owner, $agent) {
        if (! $interleaved && str_starts_with($query->sql, 'select `id` from `tickets` where `status` = ?')) {
            $interleaved = true;
            app(TicketService::class)->addReply(Ticket::findOrFail($ticket->id), $owner, 'Not fixed', false);
            app(TicketService::class)->transition(Ticket::findOrFail($ticket->id), $agent, 'resolved');
        }
    });

    $closed = app(TicketService::class)->autoCloseResolved(72);

    $fresh = $ticket->fresh();
    expect($interleaved)->toBeTrue()
        ->and($closed)->toBe(0)
        ->and($fresh->status)->toBe('resolved')
        ->and($fresh->closed_at)->toBeNull()
        ->and($fresh->resolved_at->greaterThan(now()->subHour()))->toBeTrue()
        ->and(lifecycleHistory($ticket))->toBe([[$owner->id, 'resolved', 'open'], [$agent->id, 'open', 'resolved']]);
});

it('still auto-closes idle resolved tickets, one history row each, and reports how many', function () {
    $idle = ticketFor(ticketUser(), ['status' => 'resolved', 'resolved_at' => now()->subHours(100)]);
    $recent = ticketFor(ticketUser(), ['status' => 'resolved', 'resolved_at' => now()->subHours(10)]);
    $open = ticketFor(ticketUser(), ['status' => 'open']);

    expect(app(TicketService::class)->autoCloseResolved(72))->toBe(1)
        ->and($idle->fresh()->status)->toBe('closed')
        ->and($idle->fresh()->closed_at)->not->toBeNull()
        ->and(lifecycleHistory($idle))->toBe([[null, 'resolved', 'closed']])
        ->and($recent->fresh()->status)->toBe('resolved')
        ->and($open->fresh()->status)->toBe('open')
        ->and(app(TicketService::class)->autoCloseResolved(72))->toBe(0);
});
