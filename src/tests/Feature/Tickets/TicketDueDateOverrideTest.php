<?php

/*
 * Release 1.0: Help Desk due-date overrides. `sla_due_at` is the immutable original SLA deadline; `due_override_at` is the
 * operator's working due date, changed only as an optional part of a reply or note, with a history row tied to that reply.
 */

use App\Models\Ticket;
use App\Models\TicketDueDateChange;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\TicketRepliedNotification;
use App\Services\TicketService;
use App\Support\Helpdesk\DueDateAdjustment;
use App\Support\Helpdesk\DueDateInput;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/TicketTestHelpers.php';
require_once dirname(__DIR__).'/Pilot/PilotTestHelpers.php';
require_once dirname(__DIR__, 2).'/Support/UiHtmlHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    Storage::fake('local');
    Carbon::setTestNow('2026-11-04 10:00:00');
    $this->operator = ticketUser('operator');
    $this->owner = ticketUser();
    $this->ticket = ticketFor($this->owner, ['status' => 'open', 'priority' => 'medium', 'sla_due_at' => Carbon::parse('2026-11-05 10:00:00')]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** The operator's reply form post, with a due-date adjustment. */
function dueDatePost(Ticket $ticket, User $actor, array $data)
{
    return test()->actingAs($actor)->post(route('operator.tickets.replies.store', $ticket), $data);
}

// ── The data model ───────────────────────────────────────────────────────────────────────────────

it('leaves every ticket without an override: the effective due date is the SLA deadline', function () {
    expect($this->ticket->due_override_at)->toBeNull()
        ->and($this->ticket->hasDueOverride())->toBeFalse()
        ->and($this->ticket->effectiveDueAt()->equalTo($this->ticket->sla_due_at))->toBeTrue();
});

it('refuses to change the original SLA deadline once set', function () {
    expect(fn () => $this->ticket->update(['sla_due_at' => now()->addDays(3)]))->toThrow(LogicException::class);
    expect($this->ticket->fresh()->sla_due_at->toDateTimeString())->toBe('2026-11-05 10:00:00');
});

it('does not mass-assign the override: only the service writes it', function () {
    $this->ticket->update(['due_override_at' => now()->addDays(3)]);

    expect($this->ticket->fresh()->due_override_at)->toBeNull();
});

it('refuses, in the database, a history row whose reply belongs to another ticket or is missing', function () {
    $other = ticketFor($this->owner);
    $reply = ticketReply($other, $this->operator, true);

    $insert = fn (int $ticketId, ?int $replyId) => DB::table('ticket_due_date_changes')->insert([
        'ticket_id' => $ticketId, 'reply_id' => $replyId, 'user_id' => $this->operator->id, 'created_at' => now(),
    ]);

    expect(fn () => $insert($this->ticket->id, $reply->id))->toThrow(QueryException::class);
    expect(fn () => $insert($this->ticket->id, null))->toThrow(QueryException::class);
    $insert($other->id, $reply->id);
    expect(fn () => $insert($other->id, $reply->id))->toThrow(QueryException::class); // one change per reply
});

// ── Authorization ────────────────────────────────────────────────────────────────────────────────

it('lets an operator with tickets.assign set a due date through the reply form', function () {
    dueDatePost($this->ticket, $this->operator, [
        'body' => 'Waiting on the vendor.', 'is_internal' => '1', 'due_action' => 'set', 'due_at' => '2026-11-10T14:30',
    ])->assertRedirect()->assertSessionHas('status', 'Reply added and due date updated.');

    $ticket = $this->ticket->fresh();
    expect($ticket->due_override_at->toDateTimeString())->toBe('2026-11-10 14:30:00')
        ->and($ticket->sla_due_at->toDateTimeString())->toBe('2026-11-05 10:00:00');

    $reply = $ticket->replies()->sole();
    $change = $reply->dueDateChange;
    expect($change->ticket_id)->toBe($ticket->id)
        ->and($change->user_id)->toBe($this->operator->id)
        ->and($change->previous_override_at)->toBeNull()
        ->and($change->new_override_at->toDateTimeString())->toBe('2026-11-10 14:30:00')
        ->and($change->created_at->toDateTimeString())->toBe('2026-11-04 10:00:00');
});

it('works for a capability-only operator without the literal operator role', function () {
    dueDatePost($this->ticket, ticketAgent(), ['body' => 'Moving this out.', 'due_action' => 'set', 'due_at' => '2026-11-12T09:00'])
        ->assertRedirect()->assertSessionDoesntHaveErrors();

    expect($this->ticket->fresh()->due_override_at->toDateTimeString())->toBe('2026-11-12 09:00:00');
});

it('forbids a requester from changing the due date, and writes nothing at all', function (array $fields) {
    $before = DB::table('tickets')->where('id', $this->ticket->id)->first();

    $this->actingAs($this->owner)
        ->post(route('tickets.replies.store', $this->ticket), ['body' => 'Please extend this.', ...$fields])
        ->assertForbidden();

    expect($this->ticket->replies()->count())->toBe(0)
        ->and(TicketDueDateChange::count())->toBe(0)
        ->and(DB::table('tickets')->where('id', $this->ticket->id)->first())->toEqual($before);
    Notification::assertNothingSent();
})->with([
    'set' => [['due_action' => 'set', 'due_at' => '2026-11-10T14:30']],
    'restore' => [['due_action' => 'restore']],
    'date only' => [['due_at' => '2026-11-10T14:30']],
]);

it('lets a requester keep replying normally, with or without an explicit "keep"', function () {
    $this->actingAs($this->owner)->post(route('tickets.replies.store', $this->ticket), ['body' => 'More detail.'])->assertRedirect();
    $this->actingAs($this->owner)->post(route('tickets.replies.store', $this->ticket), ['body' => 'Again.', 'due_action' => 'keep', 'due_at' => ''])->assertRedirect();

    expect($this->ticket->replies()->count())->toBe(2)->and(TicketDueDateChange::count())->toBe(0);
});

it('does not let a requester who holds a stray tickets.assign move the deadline, in any customer mode', function (string $mode, int $status) {
    pilotMode($mode);
    $stray = ticketCustomRoleUser('stray_assign', ['tickets.view', 'tickets.create', 'tickets.assign']);
    $ticket = ticketFor($stray, ['status' => 'open']);

    $this->actingAs($stray)
        ->post(route('tickets.replies.store', $ticket), ['body' => 'Mine.', 'due_action' => 'set', 'due_at' => '2026-11-10T14:30'])
        ->assertStatus($status);

    expect($ticket->replies()->count())->toBe(0)->and($ticket->fresh()->due_override_at)->toBeNull();
})->with([
    'closed (the route is outside the customer boundary)' => ['closed', 404],
    'pilot (the route is open, the capability is not)' => ['pilot', 403],
]);

it('also refuses at the service when a customer author is handed an adjustment', function () {
    $adjustment = DueDateAdjustment::set(CarbonImmutable::parse('2026-11-10 14:30:00'));

    expect(fn () => app(TicketService::class)->addReply($this->ticket, $this->owner, 'Sneaky.', false, [], $adjustment))
        ->toThrow(AuthorizationException::class);
    expect($this->ticket->replies()->count())->toBe(0);
});

it('offers the due-date control to an operator and never to the requester page', function () {
    $this->actingAs($this->operator)->get(route('operator.tickets.show', $this->ticket))
        ->assertOk()->assertSee('data-due-control', false)->assertSee('data-due-trigger', false)
        ->assertSee('Change due date')->assertDontSee('data-due-date-adjust', false);

    $page = $this->actingAs($this->owner)->get(route('tickets.show', $this->ticket))->assertOk()->getContent();
    expect($page)->not->toContain('due_action')->and($page)->not->toContain('Change due date')->and($page)->not->toContain('due_at')
        ->and($page)->not->toContain('data-due-control');
});

// ── Every change needs a message ─────────────────────────────────────────────────────────────────

it('refuses an adjustment without a message, for set and for restore, changing nothing', function (array $fields) {
    $this->ticket->forceFill(['due_override_at' => Carbon::parse('2026-11-08 10:00:00')])->saveQuietly();

    dueDatePost($this->ticket, $this->operator, ['body' => '   ', ...$fields])->assertSessionHasErrors('body');

    expect($this->ticket->replies()->count())->toBe(0)
        ->and($this->ticket->fresh()->due_override_at->toDateTimeString())->toBe('2026-11-08 10:00:00')
        ->and(TicketDueDateChange::count())->toBe(0);
})->with([
    'set' => [['due_action' => 'set', 'due_at' => '2026-11-10T14:30']],
    'restore' => [['due_action' => 'restore']],
]);

it('accepts an adjustment on a public reply: the requester is told of the reply (the existing mail), never of the date', function () {
    dueDatePost($this->ticket, $this->operator, ['body' => 'We need a few more days.', 'due_action' => 'set', 'due_at' => '2026-11-10T14:30'])
        ->assertRedirect()->assertSessionDoesntHaveErrors();

    expect($this->ticket->replies()->sole()->is_internal)->toBeFalse();
    Notification::assertSentToTimes($this->owner, TicketRepliedNotification::class, 1);
    Notification::assertSentTo($this->owner, TicketRepliedNotification::class, function ($notification) {
        $payload = json_encode($notification->toMail($this->owner)->toArray());

        return ! str_contains($payload, '2026-11-10') && ! str_contains($payload, '10 Nov');
    });
});

it('sends no mail for an internal note that carries an adjustment', function () {
    dueDatePost($this->ticket, $this->operator, ['body' => 'Internal reason.', 'is_internal' => '1', 'due_action' => 'set', 'due_at' => '2026-11-10T14:30'])->assertRedirect();

    Notification::assertNothingSent();
});

// ── The rules ────────────────────────────────────────────────────────────────────────────────────

it('requires a future date within 365 days for a new override', function (string $at, string $error) {
    dueDatePost($this->ticket, $this->operator, ['body' => 'x', 'due_action' => 'set', 'due_at' => $at])->assertSessionHasErrors(['due_at' => $error]);

    expect($this->ticket->replies()->count())->toBe(0)->and($this->ticket->fresh()->due_override_at)->toBeNull();
})->with([
    'the past' => ['2026-11-04T09:00', 'The new due date must be in the future.'],
    'this very minute' => ['2026-11-04T10:00', 'The new due date must be in the future.'],
    'beyond a year' => ['2027-11-05T10:00', 'The new due date can be at most 365 days ahead.'],
]);

it('accepts the nearest and the furthest permitted dates', function (string $at) {
    dueDatePost($this->ticket, $this->operator, ['body' => 'x', 'due_action' => 'set', 'due_at' => $at])->assertSessionDoesntHaveErrors();

    expect($this->ticket->fresh()->due_override_at->format(DueDateInput::FORMAT))->toBe($at);
})->with(['2026-11-04T10:01', '2027-11-04T10:00']);

it('rejects a malformed or impossible date and an absent date for "set"', function (array $fields) {
    dueDatePost($this->ticket, $this->operator, ['body' => 'x', 'due_action' => 'set', ...$fields])->assertSessionHasErrors('due_at');

    expect($this->ticket->replies()->count())->toBe(0);
})->with([
    'missing' => [[]],
    'not a date' => [['due_at' => 'tomorrow']],
    'impossible day' => [['due_at' => '2026-02-31T10:00']],
    'with seconds and zone' => [['due_at' => '2026-11-10T14:30:00Z']],
    'date only' => [['due_at' => '2026-11-10']],
]);

it('rejects an unknown action', function () {
    dueDatePost($this->ticket, $this->operator, ['body' => 'x', 'due_action' => 'extend'])->assertSessionHasErrors('due_action');
});

it('refuses a no-op: the same date again', function () {
    dueDatePost($this->ticket, $this->operator, ['body' => 'one', 'due_action' => 'set', 'due_at' => '2026-11-10T14:30'])->assertSessionDoesntHaveErrors();
    dueDatePost($this->ticket, $this->operator, ['body' => 'two', 'due_action' => 'set', 'due_at' => '2026-11-10T14:30'])->assertSessionHasErrors('due_at');

    expect($this->ticket->replies()->count())->toBe(1)->and(TicketDueDateChange::count())->toBe(1);
});

it('refuses to restore when there is no override', function () {
    dueDatePost($this->ticket, $this->operator, ['body' => 'x', 'due_action' => 'restore'])->assertSessionHasErrors('due_action');

    expect($this->ticket->replies()->count())->toBe(0);
});

it('restores the SLA deadline, even when that deadline is already in the past', function () {
    $this->ticket->forceFill(['sla_due_at' => Carbon::parse('2026-11-03 08:00:00'), 'due_override_at' => Carbon::parse('2026-11-09 08:00:00')])->saveQuietly();

    dueDatePost($this->ticket, $this->operator, ['body' => 'Back to the SLA.', 'is_internal' => '1', 'due_action' => 'restore'])
        ->assertRedirect()->assertSessionDoesntHaveErrors();

    $ticket = $this->ticket->fresh();
    $change = $ticket->replies()->sole()->dueDateChange;
    expect($ticket->due_override_at)->toBeNull()
        ->and($ticket->isOverdue())->toBeTrue()
        ->and($change->previous_override_at->toDateTimeString())->toBe('2026-11-09 08:00:00')
        ->and($change->new_override_at)->toBeNull();
});

it('restores a legacy override that has no SLA deadline to: the override clears, the change is recorded, the reply is stored', function () {
    $legacy = ticketFor($this->owner, ['status' => 'open', 'sla_due_at' => null]);
    $legacy->forceFill(['due_override_at' => Carbon::parse('2026-11-09 08:00:00')])->saveQuietly();

    dueDatePost($legacy, $this->operator, ['body' => 'Back to no deadline.', 'is_internal' => '0', 'due_action' => 'restore'])
        ->assertRedirect()->assertSessionDoesntHaveErrors();

    $legacy = $legacy->fresh();
    $reply = $legacy->replies()->sole();
    $change = $reply->dueDateChange;
    expect($reply->body)->toBe('Back to no deadline.')
        ->and($legacy->due_override_at)->toBeNull()
        ->and($legacy->hasDueOverride())->toBeFalse()
        ->and($legacy->sla_due_at)->toBeNull()
        ->and($legacy->effectiveDueAt())->toBeNull()
        ->and($change->previous_override_at->toDateTimeString())->toBe('2026-11-09 08:00:00')
        ->and($change->new_override_at)->toBeNull();
});

it('refuses an adjustment on a resolved or closed ticket, but still takes the reply when none is asked for', function (string $status) {
    $ticket = ticketFor($this->owner, ['status' => $status, 'resolved_at' => now()->subHour(), 'closed_at' => $status === 'closed' ? now() : null]);

    dueDatePost($ticket, $this->operator, ['body' => 'x', 'due_action' => 'set', 'due_at' => '2026-11-10T14:30'])->assertSessionHasErrors('due_action');
    expect($ticket->replies()->count())->toBe(0);

    dueDatePost($ticket, $this->operator, ['body' => 'Just a note.', 'is_internal' => '1'])->assertSessionDoesntHaveErrors();
    expect($ticket->replies()->count())->toBe(1);
})->with(['resolved', 'closed']);

it('refuses a date typed while "keep" is selected instead of dropping it silently', function () {
    dueDatePost($this->ticket, $this->operator, ['body' => 'Plain reply.', 'due_action' => 'keep', 'due_at' => '2026-11-10T14:30'])
        ->assertSessionHasErrors(['due_at' => 'Choose "Set a new due date" to use this date, or clear it.']);
    dueDatePost($this->ticket, $this->operator, ['body' => 'Plain reply.', 'due_at' => '2026-11-10T14:30'])->assertSessionHasErrors('due_at');
    dueDatePost($this->ticket, $this->operator, ['body' => 'Plain reply.', 'due_action' => 'restore', 'due_at' => '2026-11-10T14:30'])->assertSessionHasErrors('due_at');

    expect($this->ticket->replies()->count())->toBe(0)->and(TicketDueDateChange::count())->toBe(0)->and($this->ticket->fresh()->due_override_at)->toBeNull();
});

it('takes a plain reply that carries an empty date field, as the form sends it', function () {
    dueDatePost($this->ticket, $this->operator, ['body' => 'Plain reply.', 'due_action' => 'keep', 'due_at' => ''])->assertSessionDoesntHaveErrors();

    expect($this->ticket->replies()->count())->toBe(1)->and(TicketDueDateChange::count())->toBe(0)->and($this->ticket->fresh()->due_override_at)->toBeNull();
});

// ── Atomicity ────────────────────────────────────────────────────────────────────────────────────

it('rolls back the reply, the attachment, the file and the override when the history row cannot be written', function () {
    TicketDueDateChange::creating(fn () => throw new RuntimeException('history write failed'));

    expect(fn () => app(TicketService::class)->addReply(
        $this->ticket, $this->operator, 'Will not survive.', true, [ticketUpload('evidence.pdf')],
        DueDateAdjustment::set(CarbonImmutable::parse('2026-11-10 14:30:00')),
    ))->toThrow(RuntimeException::class, 'history write failed');

    expect($this->ticket->replies()->count())->toBe(0)
        ->and(DB::table('ticket_attachments')->count())->toBe(0)
        ->and($this->ticket->fresh()->due_override_at)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    TicketDueDateChange::flushEventListeners();
});

it('chains history across adjustments: each previous value is the one the last change set', function () {
    $service = app(TicketService::class);
    $set = fn (string $at) => DueDateAdjustment::set(CarbonImmutable::parse($at));

    $service->addReply($this->ticket, $this->operator, 'one', true, [], $set('2026-11-10 14:30:00'));
    $service->addReply($this->ticket, $this->operator, 'two', true, [], $set('2026-11-12 09:00:00'));
    $service->addReply($this->ticket, $this->operator, 'three', true, [], DueDateAdjustment::restore());

    $changes = $this->ticket->dueDateChanges()->get();
    expect($changes->map(fn ($c) => [$c->previous_override_at?->toDateTimeString(), $c->new_override_at?->toDateTimeString()])->all())->toBe([
        [null, '2026-11-10 14:30:00'],
        ['2026-11-10 14:30:00', '2026-11-12 09:00:00'],
        ['2026-11-12 09:00:00', null],
    ])->and($changes->pluck('reply_id')->unique())->toHaveCount(3);
    expect($this->ticket->fresh()->due_override_at)->toBeNull();
});

it('reads the committed row under the lock, not a stale page', function () {
    $stale = Ticket::find($this->ticket->id);
    app(TicketService::class)->addReply($this->ticket, $this->operator, 'first', true, [], DueDateAdjustment::set(CarbonImmutable::parse('2026-11-10 14:30:00')));

    app(TicketService::class)->addReply($stale, $this->operator, 'second', true, [], DueDateAdjustment::set(CarbonImmutable::parse('2026-11-11 14:30:00')));

    expect($this->ticket->dueDateChanges()->get()->last()->previous_override_at->toDateTimeString())->toBe('2026-11-10 14:30:00');
});

// ── Effective due date: overdue, ordering, display ───────────────────────────────────────────────

it('is overdue by the effective due date, in the model and in the SQL scope', function () {
    $late = ticketFor($this->owner, ['status' => 'open', 'sla_due_at' => now()->subHours(2)]);
    $extended = ticketFor($this->owner, ['status' => 'open', 'sla_due_at' => now()->subHours(2)]);
    $extended->forceFill(['due_override_at' => now()->addDay()])->saveQuietly();
    $shortened = ticketFor($this->owner, ['status' => 'open', 'sla_due_at' => now()->addDay()]);
    $shortened->forceFill(['due_override_at' => now()->subHour()])->saveQuietly();

    expect($late->isOverdue())->toBeTrue()
        ->and($extended->fresh()->isOverdue())->toBeFalse()
        ->and($shortened->fresh()->isOverdue())->toBeTrue();

    $ids = Ticket::overdue()->pluck('id')->all();
    expect($ids)->toContain($late->id, $shortened->id)->not->toContain($extended->id);
});

it('orders the operator queue by the effective due date within a status and priority', function () {
    Ticket::query()->delete();
    $mk = fn (string $title, $sla, $override = null) => tap(ticketFor($this->owner, ['title' => $title, 'status' => 'open', 'priority' => 'medium', 'sla_due_at' => $sla]),
        fn (Ticket $t) => $override && $t->forceFill(['due_override_at' => $override])->saveQuietly());

    $mk('Zebra', now()->addHours(1), now()->addDays(5));   // pushed out
    $mk('Mango', now()->addHours(10));
    $mk('Apple', now()->addHours(30), now()->addHours(2)); // pulled in

    $this->actingAs($this->operator)->get(route('operator.tickets.index'))
        ->assertOk()->assertSeeInOrder(['Apple', 'Mango', 'Zebra']);
});

it('labels the queue and the ticket page "Due", with the SLA mark only when no override exists', function () {
    $this->actingAs($this->operator)->get(route('operator.tickets.index'))
        ->assertOk()->assertSee('Due (UTC)')->assertDontSee('SLA Due')->assertSee('>SLA<', false)->assertDontSee('Original SLA');

    $this->actingAs($this->operator)->get(route('operator.tickets.show', $this->ticket))
        ->assertOk()->assertSee('Due (UTC)')->assertDontSee('SLA Due')->assertSee('>SLA<', false)->assertDontSee('Original SLA');
});

it('shows the effective date without the SLA mark when overridden, and discloses the original SLA', function () {
    $this->ticket->forceFill(['due_override_at' => Carbon::parse('2026-11-10 14:30:00')])->saveQuietly();

    $queue = $this->actingAs($this->operator)->get(route('operator.tickets.index'))->assertOk();
    $queue->assertSee('10 Nov 14:30')->assertSee('<summary', false)->assertSee('Original SLA: 05 Nov 10:00')->assertDontSee('>SLA<', false);

    $this->actingAs($this->operator)->get(route('operator.tickets.show', $this->ticket))
        ->assertOk()->assertSee('10 Nov 2026 14:30')->assertSee('Original SLA: 05 Nov 2026 10:00')->assertDontSee('>SLA<', false);
});

it('shows the change under its reply on the operator page, and the restore wording', function () {
    $service = app(TicketService::class);
    $service->addReply($this->ticket, $this->operator, 'Vendor delay.', false, [], DueDateAdjustment::set(CarbonImmutable::parse('2026-11-10 14:30:00')));
    $service->addReply($this->ticket, $this->operator, 'Vendor done.', false, [], DueDateAdjustment::restore());

    $page = $this->actingAs($this->operator)->get(route('operator.tickets.show', $this->ticket))->assertOk();
    $page->assertSee('data-due-date-change', false)
        ->assertSee('the SLA deadline (05 Nov 2026 10:00)')
        ->assertSee('10 Nov 2026 14:30');
});

it('offers "restore" only while an override exists, and nothing once the ticket is resolved', function () {
    $this->actingAs($this->operator)->get(route('operator.tickets.show', $this->ticket))->assertDontSee('data-due-restore', false);

    $this->ticket->forceFill(['due_override_at' => Carbon::parse('2026-11-10 14:30:00')])->saveQuietly();
    $this->actingAs($this->operator)->get(route('operator.tickets.show', $this->ticket))->assertSee('data-due-restore', false);

    $this->ticket->forceFill(['status' => 'resolved', 'resolved_at' => now()])->saveQuietly();
    $this->actingAs($this->operator)->get(route('operator.tickets.show', $this->ticket))->assertDontSee('data-due-control', false)->assertDontSee('data-due-restore', false);
});

it('redisplays a refused form with the message, the hidden choice and the pending date kept', function () {
    $show = route('operator.tickets.show', $this->ticket);

    // The date is fine; the attachment is not, so the post is refused and the form comes back.
    $this->actingAs($this->operator)->from($show)->post(route('operator.tickets.replies.store', $this->ticket), [
        'body' => 'My long explanation.', 'is_internal' => '1', 'due_action' => 'set', 'due_at' => '2026-11-10T14:30',
        'attachments' => [UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload')],
    ])->assertRedirect($show)->assertSessionHasErrors();

    $html = $this->actingAs($this->operator)->get($show)->assertOk()->getContent();
    $xpath = uiDom($html);
    expect($html)->toContain('My long explanation.')
        ->and(uiOne($xpath, '//input[@name="is_internal"]')->getAttribute('value'))->toBe('1')
        ->and(uiOne($xpath, '//*[@data-visibility-button]')->getAttribute('data-state'))->toBe('hidden')
        ->and(uiOne($xpath, '//*[@data-visibility-button]')->hasAttribute('aria-pressed'))->toBeFalse()
        ->and(uiOne($xpath, '//input[@name="due_at"]')->getAttribute('value'))->toBe('2026-11-10T14:30')
        ->and(uiOne($xpath, '//input[@name="due_action"]')->getAttribute('value'))->toBe('set')
        ->and(trim(uiOne($xpath, '//*[@data-due-text]')->textContent))->toBe('10 Nov 2026 14:30')
        ->and(uiOne($xpath, '//*[@data-due-trigger]')->getAttribute('data-pending'))->toBe('set');
});

it('keeps a refused date as the pending change with the server message beside it, instead of silently dropping it', function () {
    $show = route('operator.tickets.show', $this->ticket);

    // The editor input is detached from the form, so the browser never blocks a post on a stale date; the server refuses it, and the
    // operator's request stays visible (as pending) next to the reason.
    $this->actingAs($this->operator)->from($show)
        ->post(route('operator.tickets.replies.store', $this->ticket), ['body' => 'My long explanation.', 'due_action' => 'set', 'due_at' => '2026-11-01T10:00'])
        ->assertRedirect($show);

    $page = $this->actingAs($this->operator)->get($show)->assertOk();
    $page->assertSee('My long explanation.')->assertSee('must be in the future');
    $xpath = uiDom($page->getContent());
    expect(uiOne($xpath, '//input[@name="due_at"]')->getAttribute('value'))->toBe('2026-11-01T10:00')
        ->and(uiOne($xpath, '//input[@name="due_action"]')->getAttribute('value'))->toBe('set')
        ->and(uiOne($xpath, '//*[@id="due_at-error"]')->textContent)->toContain('must be in the future')
        ->and(uiOne($xpath, '//*[@data-due-trigger]')->getAttribute('aria-describedby'))->toContain('due_at-error');
});

it('does not refill an impossible date: the control falls back to keep and the server message stands', function () {
    $show = route('operator.tickets.show', $this->ticket);

    $this->actingAs($this->operator)->from($show)
        ->post(route('operator.tickets.replies.store', $this->ticket), ['body' => 'My long explanation.', 'due_action' => 'set', 'due_at' => '2026-02-31T10:00'])
        ->assertRedirect($show);

    $xpath = uiDom($this->actingAs($this->operator)->get($show)->assertOk()->getContent());
    expect(uiOne($xpath, '//input[@name="due_at"]')->getAttribute('value'))->toBe('')
        ->and(uiOne($xpath, '//input[@name="due_action"]')->getAttribute('value'))->toBe('keep');
});

it('says "none set" instead of printing an empty value for a legacy ticket with no SLA deadline', function () {
    $legacy = ticketFor($this->owner, ['status' => 'open', 'sla_due_at' => null]);
    $xpath = uiDom($this->actingAs($this->operator)->get(route('operator.tickets.show', $legacy))->assertOk()->getContent());
    expect(trim(uiOne($xpath, '//*[@data-due-text]')->textContent))->toBe('No due date')
        ->and(uiOne($xpath, '//*[@data-due-control]')->getAttribute('data-original-wall'))->toBe('');

    $legacy->forceFill(['due_override_at' => now()->addDay()])->saveQuietly();
    $this->actingAs($this->operator)->get(route('operator.tickets.show', $legacy))->assertOk()
        ->assertSee('Original SLA: none set')->assertSee('data-sla-text="none set"', false)->assertSee('data-due-restore', false)->assertDontSee('SLA deadline ()', false);
    $this->actingAs($this->operator)->get(route('operator.tickets.index'))->assertOk()->assertSee('Original SLA: none set');
});

it('renders the control of a legacy ticket with an override and no SLA deadline: empty SLA wall, override flagged, Restore SLA says none set', function () {
    $legacy = ticketFor($this->owner, ['status' => 'open', 'sla_due_at' => null]);
    $legacy->forceFill(['due_override_at' => Carbon::parse('2026-11-09 08:00:00')])->saveQuietly();

    $xpath = uiDom($this->actingAs($this->operator)->get(route('operator.tickets.show', $legacy))->assertOk()->getContent());
    $control = uiOne($xpath, '//*[@data-due-control]');
    $restore = uiOne($xpath, '//*[@data-due-restore]');

    expect($control->getAttribute('data-sla-wall'))->toBe('')
        ->and($control->getAttribute('data-sla-epoch'))->toBe('')
        ->and($control->getAttribute('data-has-override'))->toBe('1')
        ->and($control->getAttribute('data-sla-text'))->toBe('none set')
        ->and(preg_replace('/\s+/', ' ', trim($restore->textContent)))->toBe('Restore SLA none set')
        ->and($restore->getAttribute('title'))->toBe('Restore the original SLA deadline (none set)');
});

// ── Requester boundary ───────────────────────────────────────────────────────────────────────────

it('never sends due-date data to the requester page or props', function () {
    dueDatePost($this->ticket, $this->operator, ['body' => 'A public explanation.', 'due_action' => 'set', 'due_at' => '2026-11-10T14:30'])->assertRedirect();

    $response = $this->actingAs($this->owner)->get(route('tickets.show', $this->ticket))->assertOk();
    $body = $response->getContent();

    expect($body)->toContain('A public explanation.')
        ->and($body)->not->toContain('due_override_at')
        ->and($body)->not->toContain('dueDateChange')
        ->and($body)->not->toContain('2026-11-10')
        ->and($body)->not->toContain('previous_override_at');
});

// ── The export ───────────────────────────────────────────────────────────────────────────────────

it('exports the original SLA unchanged and appends the effective due date', function () {
    Ticket::query()->delete();
    $plain = ticketFor($this->owner, ['sla_due_at' => Carbon::parse('2026-11-05 10:00:00')]);
    $moved = ticketFor($this->owner, ['sla_due_at' => Carbon::parse('2026-11-05 10:00:00')]);
    $moved->forceFill(['due_override_at' => Carbon::parse('2026-11-10 14:30:00')])->saveQuietly();

    $csv = $this->actingAs($this->operator)->get(route('operator.tickets.export'))->assertOk()->streamedContent();
    $rows = collect(ticketParseCsv($csv));
    $header = $rows->shift();

    expect(array_slice($header, -2))->toBe(['SLA Due', 'Effective Due']);
    $byNumber = $rows->keyBy(0);
    expect(array_slice($byNumber[$plain->ticket_number], -2))->toBe(['2026-11-05 10:00:00', '2026-11-05 10:00:00'])
        ->and(array_slice($byNumber[$moved->ticket_number], -2))->toBe(['2026-11-05 10:00:00', '2026-11-10 14:30:00']);
});

// ── Time zone ────────────────────────────────────────────────────────────────────────────────────

it('reads the typed wall-clock time in the application zone, and names that zone', function () {
    expect(DueDateInput::timezone())->toBe('UTC')
        ->and(DueDateInput::zoneLabel())->toBe('UTC')
        ->and(DueDateInput::parse('2026-11-10T14:30')->toIso8601String())->toBe('2026-11-10T14:30:00+00:00')
        ->and(DueDateInput::format(CarbonImmutable::parse('2026-11-10 14:30:45')))->toBe('2026-11-10T14:30');
});

it('parses strictly: only a real wall-clock minute in the exact format', function (mixed $value) {
    expect(DueDateInput::parse($value))->toBeNull();
})->with([null, '', 'x', '2026-11-10', '2026-11-10 14:30', '2026-11-10T14:30:00', '2026-11-10T24:00', '2026-13-01T10:00', '2026-02-30T10:00']);

it('parses nothing that is not a string', function () {
    expect(DueDateInput::parse(['2026-11-10T14:30']))->toBeNull()->and(DueDateInput::parse(20261110))->toBeNull();
});

it('handles daylight saving: a spring-forward gap time is refused and an autumn overlap means its first occurrence', function () {
    config(['app.timezone' => 'America/New_York']);

    expect(DueDateInput::zoneLabel())->toBeIn(['EST', 'EDT'])
        ->and(DueDateInput::parse('2027-03-14T02:30'))->toBeNull()                          // 02:00-03:00 does not exist
        ->and(DueDateInput::parse('2027-03-14T03:30')->utc()->format('Y-m-d H:i'))->toBe('2027-03-14 07:30')
        ->and(DueDateInput::parse('2027-11-07T01:30')->utc()->format('Y-m-d H:i'))->toBe('2027-11-07 05:30') // first (EDT) 01:30
        ->and(DueDateInput::parse('2027-11-07T02:30')->utc()->format('Y-m-d H:i'))->toBe('2027-11-07 07:30');
});

it('refuses a gap time through the form too, with a message naming the zone', function () {
    config(['app.timezone' => 'America/New_York']);

    dueDatePost($this->ticket, $this->operator, ['body' => 'x', 'due_action' => 'set', 'due_at' => '2027-03-14T02:30'])
        ->assertSessionHasErrors(['due_at' => 'Enter the due date and time as a real date in '.DueDateInput::zoneLabel().'.']);
});

it('removes a change together with its reply (no reply-deletion path exists today)', function () {
    dueDatePost($this->ticket, $this->operator, ['body' => 'x', 'due_action' => 'set', 'due_at' => '2026-11-10T14:30'])->assertRedirect();

    TicketReply::query()->delete();

    expect(TicketDueDateChange::count())->toBe(0);
});
