<?php

use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketRepliedNotification;
use App\Support\TicketReplyThrottle;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/TicketTestHelpers.php';
require_once __DIR__.'/../Pilot/PilotTestHelpers.php';
require_once __DIR__.'/../PartnerSupport/PartnerSupportTestHelpers.php';

/*
 * Release 1.0 pre-RC1 hardening: ONE per-account allowance shared by the requester, operator and partner-support reply routes (release gate 7).
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    Storage::fake('local');
    pilotMode('pilot');
});

function trtOperator(): User
{
    return User::factory()->create()->assignRole('operator');
}

it('lets an operator work a queue at a realistic pace without being limited', function () {
    $operator = trtOperator();
    $ticket = Ticket::factory()->open()->create();

    // 25 replies in a minute is far above a person; the burst limit is 30.
    for ($i = 0; $i < 25; $i++) {
        $this->actingAs($operator)->post(route('operator.tickets.replies.store', $ticket), ['body' => "Note {$i}", 'is_internal' => '1'])->assertRedirect();
    }

    expect($ticket->replies()->count())->toBe(25);
});

it('refuses the reply past the per-minute limit before validating, storing or mailing anything', function () {
    $operator = trtOperator();
    $owner = User::factory()->create()->assignRole('user');
    $ticket = Ticket::factory()->open()->for($owner, 'user')->create();

    for ($i = 0; $i < TicketReplyThrottle::ATTEMPTS_PER_MINUTE; $i++) {
        $this->actingAs($operator)->post(route('operator.tickets.replies.store', $ticket), ['body' => "Reply {$i}"])->assertRedirect();
    }

    $before = $ticket->replies()->count();
    Notification::fake();

    // Past the limit even an invalid body is a 429, not a validation error: the limiter runs first.
    $this->actingAs($operator)->post(route('operator.tickets.replies.store', $ticket), ['body' => ''])->assertStatus(429);
    $this->actingAs($operator)->post(route('operator.tickets.replies.store', $ticket), ['body' => 'One more'])->assertStatus(429);

    expect($ticket->replies()->count())->toBe($before);
    Notification::assertNothingSent();

    // Reading is not limited.
    $this->actingAs($operator)->get(route('operator.tickets.show', $ticket))->assertOk();
});

it('spends one allowance across the requester, operator and partner-support routes', function () {
    $w = psWorld();
    $staff = $w['staff'];
    $operator = trtOperator();

    $ticket = $w['ticket'];

    // The shared limiter name and key are what make this one bucket: spend the allowance on the support route, then show the other routes see it.
    for ($i = 0; $i < TicketReplyThrottle::ATTEMPTS_PER_MINUTE; $i++) {
        $this->actingAs($staff)->post(route('support.replies.store', $ticket), ['body' => "Support {$i}"])->assertRedirect();
    }
    $this->actingAs($staff)->post(route('support.replies.store', $ticket), ['body' => 'Over'])->assertStatus(429);
    // The requester route (it takes any authenticated account; the policy decides) is the same bucket, so it is refused by the limiter first.
    $this->actingAs($staff)->post(route('tickets.replies.store', $ticket), ['body' => 'Over'])->assertStatus(429);

    // The operator route draws on the same bucket: the limiter runs before `can:tickets.assign`, so the spent account is refused there with a 429
    // (a route with its own limiter name would answer 403 here, because this account is not an operator).
    $this->actingAs($staff)->post(route('operator.tickets.replies.store', $ticket), ['body' => 'Over'])->assertStatus(429);

    // And an operator's allowance is spent across the requester route and the operator route alike.
    for ($i = 0; $i < TicketReplyThrottle::ATTEMPTS_PER_MINUTE; $i++) {
        $this->actingAs($operator)->post($i % 2 === 0 ? route('tickets.replies.store', $ticket) : route('operator.tickets.replies.store', $ticket), ['body' => "Mixed {$i}"])->assertRedirect();
    }
    $this->actingAs($operator)->post(route('operator.tickets.replies.store', $ticket), ['body' => 'Over'])->assertStatus(429);
    $this->actingAs($operator)->post(route('tickets.replies.store', $ticket), ['body' => 'Over'])->assertStatus(429);

    // Another account is unaffected: the key is the account, not the address.
    $this->actingAs($w['requester'])->post(route('tickets.replies.store', $ticket), ['body' => 'Requester reply'])->assertRedirect();
    $this->actingAs(trtOperator())->post(route('operator.tickets.replies.store', $ticket), ['body' => 'Operator reply'])->assertRedirect();
});

it('bounds sustained volume per hour even when the per-minute window is respected', function () {
    $operator = trtOperator();
    $ticket = Ticket::factory()->open()->create();

    $this->travelTo(now()->startOfHour());
    $sent = 0;

    while ($sent < TicketReplyThrottle::ATTEMPTS_PER_HOUR) {
        $this->actingAs($operator)->post(route('operator.tickets.replies.store', $ticket), ['body' => "n{$sent}", 'is_internal' => '1'])->assertRedirect();
        $sent++;

        if ($sent % TicketReplyThrottle::ATTEMPTS_PER_MINUTE === 0) {
            $this->travel(61)->seconds();
        }
    }

    // Still inside the hour (10 windows of 61 s = about 10 minutes), a fresh minute window, but the hour allowance is spent.
    $this->actingAs($operator)->post(route('operator.tickets.replies.store', $ticket), ['body' => 'over', 'is_internal' => '1'])->assertStatus(429);
    expect($ticket->replies()->count())->toBe(TicketReplyThrottle::ATTEMPTS_PER_HOUR);

    // An hour later the allowance is back.
    $this->travel(61)->minutes();
    $this->actingAs($operator)->post(route('operator.tickets.replies.store', $ticket), ['body' => 'back', 'is_internal' => '1'])->assertRedirect();
});

it('counts a refused attempt, and clearing an account restores only that account', function () {
    $owner = User::factory()->create()->assignRole('user');
    $stranger = User::factory()->create()->assignRole('user');
    $ticket = Ticket::factory()->open()->for($owner, 'user')->create();

    // A stranger's reply is refused by the policy (403), and every such attempt still counts.
    for ($i = 0; $i < TicketReplyThrottle::ATTEMPTS_PER_MINUTE; $i++) {
        $this->actingAs($stranger)->post(route('tickets.replies.store', $ticket), ['body' => 'x'])->assertForbidden();
    }
    $this->actingAs($stranger)->post(route('tickets.replies.store', $ticket), ['body' => 'x'])->assertStatus(429);

    TicketReplyThrottle::clearFor($stranger->id);
    $this->actingAs($stranger)->post(route('tickets.replies.store', $ticket), ['body' => 'x'])->assertForbidden();

    expect($ticket->replies()->count())->toBe(0);
    Notification::assertNotSentTo($owner, TicketRepliedNotification::class);
});
