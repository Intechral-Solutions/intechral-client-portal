<?php

/*
 * Release 1.0 (R1-4): the partner support SURFACE, in PILOT mode (the mode customers actually run in; the suite default is legacy). What a designated
 * partner staff member may read and reply to, exactly what is sent, that nothing else opens, and every refusal being a 404.
 */

use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Notifications\TicketRepliedNotification;
use App\Services\OrganizationRelationships;
use App\Services\PartnerSupportGrants;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/PartnerSupportTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Storage::fake('local');
    pilotMode('pilot');
});

afterEach(function () {
    ticketCleanFakeStorage();
});

const PS_LIST_ROW_KEYS = ['number', 'title', 'organization', 'requester', 'status', 'statusLabel', 'priority', 'createdAt', 'createdAtHuman', 'href'];

const PS_DETAIL_PATHS = [
    'ticket', 'ticket.number', 'ticket.title', 'ticket.organization', 'ticket.status', 'ticket.statusLabel', 'ticket.priority', 'ticket.category',
    'ticket.createdAt', 'ticket.createdAtHuman', 'ticket.handledBy', 'ticket.requester',
    'conversation', 'conversation.*', 'conversation.*.key', 'conversation.*.kind', 'conversation.*.author', 'conversation.*.author.name', 'conversation.*.body',
    'conversation.*.createdAt', 'conversation.*.createdAtHuman', 'conversation.*.attachments', 'conversation.*.attachments.*', 'conversation.*.attachments.*.id',
    'conversation.*.attachments.*.filename', 'conversation.*.attachments.*.size', 'conversation.*.attachments.*.href',
    'history', 'history.*', 'history.*.statusLabel', 'history.*.at', 'history.*.atHuman',
    'reply', 'reply.allowed', 'reply.notice', 'reply.closedMessage',
    'attachmentPolicy', 'attachmentPolicy.maxFiles', 'attachmentPolicy.maxSizeMb', 'attachmentPolicy.extensions', 'attachmentPolicy.extensions.*',
    'abilities', 'abilities.create', 'backLink', 'backLink.href', 'backLink.label', 'replyUrl',
];

const PS_SENTINELS = ['INTERNAL-NOTE-SENTINEL', 'internal-attachment-sentinel.pdf', 'Coworker-Time-SENTINEL', 'operator-history-sentinel', 'assignee-sentinel@example.test'];

/** A world whose ticket carries every operator-only field, filled with sentinels. */
function psLoaded(): array
{
    $w = psWorld(['status' => 'pending_user']);
    $assignee = ticketAgent(['name' => 'Handler Hana', 'email' => 'assignee-sentinel@example.test']);
    $w['ticket']->forceFill(['assignee_id' => $assignee->id])->save();
    $coworker = ticketUser('operator', ['name' => 'Coworker-Time-SENTINEL']);

    $public = psReply($w['ticket'], $assignee, false, 'We are looking into it.');
    $internal = psReply($w['ticket'], $assignee, true, 'INTERNAL-NOTE-SENTINEL credit hold');
    ticketAttachment($w['ticket'], null, $w['requester'], 'photo.png', 'png-bytes');
    $w['publicAttachment'] = ticketAttachment($w['ticket'], $public, $assignee, 'diagnostics.txt', 'text');
    $w['internalAttachment'] = ticketAttachment($w['ticket'], $internal, $assignee, 'internal-attachment-sentinel.pdf', 'pdf-bytes');
    TimeEntry::factory()->create(['user_id' => $coworker->id, 'ticket_id' => $w['ticket']->id, 'description' => 'Coworker-Time-SENTINEL']);
    DB::table('ticket_status_histories')->insert([
        'ticket_id' => $w['ticket']->id, 'user_id' => ticketAgent(['name' => 'operator-history-sentinel'])->id,
        'old_status' => 'open', 'new_status' => 'pending_user', 'created_at' => now(),
    ]);
    $w['assignee'] = $assignee;

    return $w;
}

// ── reading ─────────────────────────────────────────────

it('lists the supported requests with names and no ids, and offers the entry in the navigation', function () {
    $w = psWorld();
    // A request of an UNSUPPORTED organization must not appear.
    Ticket::factory()->open()->create(['organization_id' => psPaired('Elsewhere')->id, 'title' => 'Not yours']);

    $this->actingAs($w['staff'])->get(route('support.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('support/index')
            ->has('tickets.data', 1)
            ->where('tickets.data.0.number', $w['ticket']->ticket_number)
            ->where('tickets.data.0.organization', 'Charter')
            ->where('tickets.data.0.requester', 'Rae Requester')
            ->where('tickets.data.0.href', route('support.show', $w['ticket']))
            ->where('navigation.workspaces', fn ($workspaces) => collect($workspaces)->pluck('key')->contains('helpdesk'))
        );

    $props = $this->actingAs($w['staff'])->get(route('support.index'))->viewData('page')['props'];
    expect(array_keys($props['tickets']['data'][0]))->toEqualCanonicalizing(PS_LIST_ROW_KEYS);
    expect(json_encode($props))->not->toContain('Not yours')->not->toContain('organization_id')->not->toContain('user_id');
});

it('shows the public conversation with exactly the allowed keys and none of the operator-only data', function () {
    $w = psLoaded();

    $response = $this->actingAs($w['staff'])->get(route('support.show', $w['ticket']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tickets/show')
            ->where('ticket.requester', 'Rae Requester')
            ->where('ticket.organization', 'Charter')
            ->where('ticket.statusLabel', 'Waiting on requester')
            ->where('reply.allowed', true)->where('reply.notice', null)
            ->where('abilities.create', false)
            ->where('replyUrl', route('support.replies.store', $w['ticket']))
            ->where('backLink.href', route('support.index'))
        );

    $props = $response->viewData('page')['props'];
    $paths = collect(Arr::dot(Arr::except($props, ['auth', 'shell', 'navigation', 'flash', 'errors', 'ziggy', 'app'])))->keys()
        ->map(fn (string $key) => preg_replace('/\.\d+(?=\.|$)/', '.*', $key))->unique();
    $allowedLeaf = collect(PS_DETAIL_PATHS);
    expect($paths->reject(fn ($p) => $allowedLeaf->contains($p))->values()->all())->toBe([]);

    $json = json_encode(Arr::except($props, ['auth', 'shell', 'navigation', 'app']));
    foreach (PS_SENTINELS as $sentinel) {
        expect($json)->not->toContain($sentinel);
    }
    // Public reply, its attachment and the opening one ARE there, pointing at the SUPPORT download route.
    expect($json)->toContain('We are looking into it.')->toContain('diagnostics.txt')
        ->and($json)->toContain('support\/requests')->not->toContain('tickets\/'.$w['ticket']->ticket_number.'\/attachments');
    expect($props['ticket']['handledBy'])->toBe('Handler Hana');
});

it('downloads public attachments and refuses internal and foreign ones as not found', function () {
    $w = psLoaded();
    $other = Ticket::factory()->open()->create(['organization_id' => $w['customer']->id]);
    $foreign = ticketAttachment($other, null, $w['requester'], 'other.txt', 'text');

    $this->actingAs($w['staff'])->get(route('support.attachment.download', [$w['ticket'], $w['publicAttachment']]))->assertOk();
    $this->actingAs($w['staff'])->get(route('support.attachment.download', [$w['ticket'], $w['internalAttachment']]))->assertNotFound();
    // An attachment id of ANOTHER ticket, asked for through this one.
    $this->actingAs($w['staff'])->get(route('support.attachment.download', [$w['ticket'], $foreign]))->assertNotFound();
});

// ── replying ────────────────────────────────────────────

it('adds a public reply, mails the requester and the assignee but not the author, and changes nothing else', function () {
    Notification::fake();
    $w = psLoaded();
    $before = psWrites();
    $dueBefore = DB::table('tickets')->where('id', $w['ticket']->id)->first(['status', 'due_override_at', 'assignee_id', 'sla_due_at']);

    $this->actingAs($w['staff'])->post(route('support.replies.store', $w['ticket']), [
        'body' => 'Can you try restarting it?',
        // None of these is read: not internal, no due date, no status.
        'is_internal' => true, 'due_action' => 'set', 'due_at' => '2030-01-01 10:00', 'status' => 'closed', 'assignee_id' => $w['staff']->id,
    ])->assertRedirect();

    $reply = DB::table('ticket_replies')->latest('id')->first();
    expect($reply->body)->toBe('Can you try restarting it?')->and($reply->user_id)->toBe($w['staff']->id)->and((bool) $reply->is_internal)->toBeFalse();
    expect(DB::table('ticket_replies')->count())->toBe($before['replies'] + 1)
        ->and(DB::table('ticket_status_histories')->count())->toBe($before['history'])
        ->and(DB::table('ticket_due_date_changes')->count())->toBe($before['dueChanges']);
    // Status stays `pending_user` (no customer-style transition), the due date and assignee are untouched.
    expect(DB::table('tickets')->where('id', $w['ticket']->id)->first(['status', 'due_override_at', 'assignee_id', 'sla_due_at']))->toEqual($dueBefore)
        ->and($dueBefore->status)->toBe('pending_user');

    Notification::assertSentTo($w['requester'], TicketRepliedNotification::class);
    Notification::assertSentTo($w['assignee'], TicketRepliedNotification::class);
    Notification::assertNotSentTo($w['staff'], TicketRepliedNotification::class);
    Notification::assertCount(2);
});

it('refuses a reply on a resolved or closed request without reopening it', function (string $status) {
    $w = psWorld(['status' => $status]);
    $before = psWrites();

    $this->actingAs($w['staff'])->post(route('support.replies.store', $w['ticket']), ['body' => 'Hello?'])
        ->assertSessionHasErrors('body');

    expect(psWrites())->toBe($before)->and(DB::table('tickets')->where('id', $w['ticket']->id)->value('status'))->toBe($status);
})->with(['resolved', 'closed']);

it('stores a reply attachment on the reply', function () {
    Notification::fake();
    $w = psWorld();

    $this->actingAs($w['staff'])->post(route('support.replies.store', $w['ticket']), [
        'body' => 'See attached', 'attachments' => [ticketUpload('notes.pdf')],
    ])->assertSessionHasNoErrors();

    $reply = DB::table('ticket_replies')->latest('id')->first();
    expect(DB::table('ticket_attachments')->where('reply_id', $reply->id)->where('user_id', $w['staff']->id)->count())->toBe(1);
});

// ── refusals: every one a 404, nothing written ──────────

dataset('psOutsiders', [
    'a partner member WITHOUT a designation' => function (array $w) {
        $user = pilotCustomer();
        psJoin($user, $w['partner']);

        return $user;
    },
    'a designated member of ANOTHER partner' => function (array $w) {
        $other = psPaired('Other Partner');
        app(OrganizationRelationships::class)->designatePartner($other, $w['operator']);
        $user = pilotCustomer();
        psJoin($user, $other);
        app(PartnerSupportGrants::class)->grant($other, $user, $w['operator']);

        return $user;
    },
    'a member of the CUSTOMER organization' => function (array $w) {
        $user = pilotCustomer();
        psJoin($user, $w['customer'], 'admin');

        return $user;
    },
    'the requester' => fn (array $w) => $w['requester'],
    'an unrelated customer' => fn (array $w) => pilotCustomer(),
    'an operator holding a forged designation' => function (array $w) {
        $operator = pilotOperator();
        psJoin($operator, $w['partner']);
        DB::table('organization_members')->where('user_id', $operator->id)->update(['support_granted_at' => now(), 'support_granted_by' => $w['operator']->id]);

        return $operator;
    },
]);

it('answers 404 to everyone without authority, on every support route, and writes nothing', function (Closure $actor) {
    Notification::fake();
    $w = psLoaded();
    $user = $actor($w);
    $before = psWrites();

    $this->actingAs($user)->get(route('support.show', $w['ticket']))->assertNotFound();
    $this->actingAs($user)->post(route('support.replies.store', $w['ticket']), ['body' => 'x'])->assertNotFound();
    $this->actingAs($user)->get(route('support.attachment.download', [$w['ticket'], $w['publicAttachment']]))->assertNotFound();

    expect(psWrites())->toBe($before);
    Notification::assertNothingSent();
})->with('psOutsiders');

it('answers 404 to the list for anyone without a designation, and 404 for a reference that does not exist', function () {
    $w = psWorld();
    $member = pilotCustomer();
    psJoin($member, $w['partner']);

    $this->actingAs($member)->get(route('support.index'))->assertNotFound();
    $this->actingAs($w['requester'])->get(route('support.index'))->assertNotFound();
    $this->actingAs($w['staff'])->get('/support/requests/TK-AAAAA-AAAAA')->assertNotFound();
    $this->actingAs($w['staff'])->get('/support/requests/123')->assertNotFound();
});

it('is absent in closed mode and for guests', function () {
    $w = psWorld();
    pilotMode('closed');

    $this->actingAs($w['staff'])->get(route('support.index'))->assertNotFound();
    $this->actingAs($w['staff'])->get(route('support.show', $w['ticket']))->assertNotFound();
    $this->actingAs($w['staff'])->post(route('support.replies.store', $w['ticket']), ['body' => 'x'])->assertNotFound();

    auth()->logout();
    $this->get(route('support.index'))->assertRedirect(route('login'));
});

it('opens nothing else: no status, assignment, queue, report, export, bulk, note, time or requester route for a support user', function () {
    $w = psLoaded();
    $staff = $w['staff'];
    $ref = $w['ticket']->ticket_number;

    // The requester routes still refuse them, as before R1-4.
    $this->actingAs($staff)->get(route('tickets.show', $w['ticket']))->assertForbidden();
    $this->actingAs($staff)->post(route('tickets.replies.store', $w['ticket']), ['body' => 'x'])->assertForbidden();
    $this->actingAs($staff)->get(route('tickets.attachment.download', [$w['ticket'], $w['publicAttachment']]))->assertForbidden();

    // Operator routes: outside the pilot allowlist for a customer.
    foreach ([
        ['get', route('operator.tickets.index')], ['get', route('operator.tickets.show', $w['ticket'])], ['get', route('operator.tickets.reports')],
        ['get', route('operator.tickets.export')], ['post', route('operator.tickets.bulk')], ['put', route('operator.tickets.status', $w['ticket'])],
        ['put', route('operator.tickets.assign', $w['ticket'])], ['post', route('operator.tickets.replies.store', $w['ticket'])],
    ] as [$verb, $url]) {
        $this->actingAs($staff)->{$verb}($url, ['body' => 'x', 'status' => 'closed', 'assignee_id' => $staff->id])->assertNotFound();
    }

    // No such verb or path exists under the support prefix (404 from the boundary, or 405 for a known path with no such verb).
    foreach (['put' => "/support/requests/{$ref}/status", 'delete' => "/support/requests/{$ref}", 'post' => '/support/requests/bulk'] as $verb => $url) {
        expect($this->actingAs($staff)->{$verb}($url)->getStatusCode())->toBeIn([404, 405]); // never a success: no such route exists
    }
});

it('leaves every requester page exactly as it was: the requester sees the partner reply in their own thread, and nothing new', function () {
    $w = psWorld();
    $this->actingAs($w['staff'])->post(route('support.replies.store', $w['ticket']), ['body' => 'From the partner'])->assertRedirect();

    $response = $this->actingAs($w['requester'])->get(route('tickets.show', $w['ticket']))->assertOk();
    $props = $response->viewData('page')['props'];

    expect(json_encode($props['conversation']))->toContain('From the partner')->toContain('Pat Partner');
    expect(array_keys($props['ticket']))->not->toContain('requester')
        ->and(array_keys($props))->not->toContain('backLink')->not->toContain('replyUrl');
});

it('does not offer the support entry to a requester, an operator or a member without a designation', function () {
    $w = psWorld();
    $member = pilotCustomer();
    psJoin($member, $w['partner']);

    foreach ([$w['requester'], $member, $w['operator']] as $user) {
        $props = $this->actingAs($user)->get(route('dashboard'))->viewData('page')['props'];
        $items = collect($props['navigation']['workspaces'] ?? [])->flatMap(fn ($w) => collect($w['sections'] ?? [])->flatMap(fn ($s) => $s['items'] ?? []))->pluck('key');
        expect($items->contains('helpdesk.support'))->toBeFalse();
    }
});

it('searches public text only: an internal note is never an oracle, but a description and a public reply are found', function () {
    $w = psLoaded();

    $count = fn (string $term) => count($this->actingAs($w['staff'])->get(route('support.index', ['search' => $term]))->viewData('page')['props']['tickets']['data']);

    expect($count('INTERNAL-NOTE-SENTINEL'))->toBe(0)
        ->and($count('It prints nothing'))->toBe(1)
        ->and($count('We are looking into it'))->toBe(1);
});
