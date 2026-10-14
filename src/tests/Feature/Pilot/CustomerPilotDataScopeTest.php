<?php

/*
 * R0.9-CS1 review R-1: operational capabilities must not widen the data scope of the routes a restricted customer is
 * allowed. An account WITHOUT portal.operator that also holds tickets.assign and/or projects.admin is still a customer:
 * its tickets are its own, internal notes and their attachments stay closed, operator ticket actions and redirects are
 * unavailable, and projects are reached by membership only. Real persisted roles, PILOT mode throughout.
 */

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Models\User;
use App\Shared\Permissions\PermissionCatalogue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/PilotTestHelpers.php';
require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    Storage::fake('local');
});

afterEach(function () {
    ticketCleanFakeStorage();
});

/** The three restricted shapes the review reproduced, as real accounts holding the customer defaults plus the extras. */
function scopeAccounts(): array
{
    return [
        'tickets.assign' => pilotCustomRoleUser('scope-assign', [...PermissionCatalogue::userDefaults(), 'tickets.assign']),
        'projects.admin' => pilotCustomRoleUser('scope-admin', [...PermissionCatalogue::userDefaults(), 'projects.admin']),
        'both' => pilotCustomRoleUser('scope-both', [...PermissionCatalogue::userDefaults(), 'tickets.assign', 'projects.admin']),
    ];
}

/**
 * A foreign customer's ticket with a public reply, an internal note by an operator and an attachment on each, and the
 * same for a ticket the account owns.
 *
 * @return array{foreign: Ticket, foreignPublic: TicketAttachment, foreignInternal: TicketAttachment, own: Ticket, ownInternal: TicketAttachment, operator: User}
 */
function scopeTickets(User $account): array
{
    $operator = pilotOperator();
    $stranger = pilotCustomer();

    $foreign = ticketFor($stranger, ['title' => 'SCOPE-SECRET foreign ticket']);
    $foreignPublic = ticketAttachment($foreign, null, $stranger, 'foreign-public.txt', 'foreign public bytes');
    $foreignInternal = ticketAttachment($foreign, ticketReply($foreign, $operator, true, 'SCOPE-SECRET internal note'), $operator, 'foreign-internal.txt', 'foreign internal bytes');

    $own = ticketFor($account, ['title' => 'Own ticket']);
    $ownInternal = ticketAttachment($own, ticketReply($own, $operator, true, 'SCOPE-SECRET own internal note'), $operator, 'own-internal.txt', 'own internal bytes');

    return compact('foreign', 'foreignPublic', 'foreignInternal', 'own', 'ownInternal', 'operator');
}

/** What a real client's next request gets: authenticated afresh from the session. */
function scopeNextRequest(): void
{
    app('auth')->forgetGuards();
}

// ── Tickets ───────────────────────────────────────────────────────────────────

it('keeps a restricted account’s own ticket reachable and everyone else’s closed, whatever tickets.assign adds', function (string $shape) {
    $account = scopeAccounts()[$shape];
    $fx = scopeTickets($account);
    pilotMode('pilot');

    $this->actingAs($account)->get(route('tickets.show', $fx['own']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tickets/show'));

    // A foreign ticket is refused where it stands: not a redirect into the operator application, and not its data.
    foreach ([
        $this->actingAs($account)->get(route('tickets.show', $fx['foreign'])),
        $this->actingAs($account)->getJson(route('tickets.show', $fx['foreign'])),
        $this->actingAs($account)->get(route('tickets.show', $fx['foreign']), ['X-Inertia' => 'true', 'X-Inertia-Version' => inertia()->getVersion()]),
    ] as $response) {
        $response->assertForbidden();
        expect($response->headers->get('Location'))->toBeNull()->and($response->headers->get('X-Inertia-Location'))->toBeNull()
            ->and($response->getContent())->not->toContain('SCOPE-SECRET');
    }
})->with(['tickets.assign', 'both']);

it('lists only a restricted account’s own tickets', function (string $shape) {
    $account = scopeAccounts()[$shape];
    $fx = scopeTickets($account);
    pilotMode('pilot');

    $response = $this->actingAs($account)->get(route('tickets.index'));

    $response->assertOk();
    expect($response->getContent())->toContain('Own ticket')->not->toContain('SCOPE-SECRET');
    $this->actingAs($account)->getJson(route('tickets.index'))->assertOk();
})->with(['tickets.assign', 'both']);

it('refuses internal-note attachments to a restricted account, on its own ticket as well as on a foreign one', function (string $shape) {
    $account = scopeAccounts()[$shape];
    $fx = scopeTickets($account);
    pilotMode('pilot');

    foreach (['foreignInternal', 'foreignPublic', 'ownInternal'] as $name) {
        foreach ([$this->actingAs($account)->get(route('tickets.attachment.download', $fx[$name])), $this->actingAs($account)->getJson(route('tickets.attachment.download', $fx[$name]))] as $response) {
            $response->assertForbidden();
            expect($response->getContent())->not->toContain('internal bytes')->not->toContain('foreign public bytes');
        }
    }
})->with(['tickets.assign', 'both']);

it('refuses an internal note on a foreign ticket and changes nothing, in form and JSON', function (string $shape) {
    $account = scopeAccounts()[$shape];
    $fx = scopeTickets($account);
    pilotMode('pilot');
    $replies = TicketReply::count();
    $internal = TicketReply::where('is_internal', true)->count();
    $status = $fx['foreign']->fresh()->status;

    $this->actingAs($account)->post(route('tickets.replies.store', $fx['foreign']), ['body' => 'SCOPE-SECRET planted note', 'is_internal' => true])->assertForbidden();
    $this->actingAs($account)->postJson(route('tickets.replies.store', $fx['foreign']), ['body' => 'SCOPE-SECRET planted note', 'is_internal' => true])->assertForbidden();
    $this->actingAs($account)->post(route('tickets.replies.store', $fx['foreign']), ['body' => 'SCOPE-SECRET planted public'])->assertForbidden();

    expect(TicketReply::count())->toBe($replies)
        ->and(TicketReply::where('is_internal', true)->count())->toBe($internal)
        ->and(TicketReply::where('body', 'like', '%planted%')->exists())->toBeFalse()
        ->and($fx['foreign']->fresh()->status)->toBe($status);
})->with(['tickets.assign', 'both']);

it('turns an internal-note request on the account’s own ticket into an ordinary public reply', function (string $shape) {
    $account = scopeAccounts()[$shape];
    $fx = scopeTickets($account);
    pilotMode('pilot');

    $this->actingAs($account)->post(route('tickets.replies.store', $fx['own']), ['body' => 'asked to be internal', 'is_internal' => true])->assertRedirect();

    $reply = TicketReply::where('body', 'asked to be internal')->firstOrFail();
    expect($reply->is_internal)->toBeFalse()->and($reply->user_id)->toBe($account->id);
})->with(['tickets.assign', 'both']);

it('treats a restricted account’s reply as a customer’s: closed tickets refuse it and replies move the status', function () {
    $account = scopeAccounts()['tickets.assign'];
    pilotMode('pilot');

    $closed = ticketFor($account, ['status' => 'closed']);
    $this->actingAs($account)->post(route('tickets.replies.store', $closed), ['body' => 'late'])->assertSessionHasErrors('body');
    expect($closed->replies()->count())->toBe(0);

    $waiting = ticketFor($account, ['status' => 'pending_user']);
    $this->actingAs($account)->post(route('tickets.replies.store', $waiting), ['body' => 'here is the info'])->assertRedirect();
    expect($waiting->fresh()->status)->toBe('in_progress');
});

it('never offers a restricted account the operator application: no queue item, no New project, no operator route', function () {
    // projects.manage is what builds the "New project" action, tickets.assign the queue and reports: give them both.
    $account = pilotCustomRoleUser('scope-navigation', [...PermissionCatalogue::userDefaults(), 'tickets.assign', 'projects.manage', 'projects.admin']);
    pilotMode('pilot');

    // Asserted on the serialized navigation itself: the page JSON escapes slashes, so a search of the HTML for a URL proves nothing.
    $response = $this->actingAs($account)->get(route('dashboard'));
    $response->assertOk();

    $items = collect(json_decode(json_encode($response->viewData('page')), true)['props']['navigation']['workspaces'])
        ->flatMap(fn (array $workspace) => collect($workspace['context'] ?? [])->flatMap(fn (array $section) => $section['items'] ?? []))
        ->pluck('key')->all();

    expect($response->viewData('page')['props']['shell']['presentation'])->toBe('operational') // the shell that WOULD list them
        ->and($items)->toContain('helpdesk.requests')->toContain('projects.all')
        ->and($items)->not->toContain('helpdesk.queue')->not->toContain('helpdesk.reports')->not->toContain('projects.create');

    foreach (['/operator/tickets', '/operator/tickets/reports', '/projects/create'] as $uri) {
        $this->actingAs($account)->get($uri)->assertNotFound();
    }

    $this->actingAs($account)->get(route('projects.index'))->assertOk();

    // The same account made eligible does get them: the assertions above are not satisfied by an empty navigation.
    User::findOrFail($account->id)->givePermissionTo('portal.operator');
    scopeNextRequest();
    $eligible = $this->actingAs(User::findOrFail($account->id))->get(route('dashboard'));
    $eligibleItems = collect(json_decode(json_encode($eligible->viewData('page')), true)['props']['navigation']['workspaces'])
        ->flatMap(fn (array $workspace) => collect($workspace['context'] ?? [])->flatMap(fn (array $section) => $section['items'] ?? []))
        ->pluck('key')->all();
    expect($eligibleItems)->toContain('helpdesk.queue')->toContain('projects.create');
});

// ── Projects ──────────────────────────────────────────────────────────────────

it('shows a projects.admin account without eligibility only the projects it is a member of', function (string $shape) {
    $account = scopeAccounts()[$shape];
    $mine = pilotProjectFor($account, 'Mine One');
    $theirs = pilotProjectFor(pilotCustomer(), 'Theirs Only');
    pilotMode('pilot');

    $list = $this->actingAs($account)->get(route('projects.index'));

    $list->assertOk()->assertInertia(fn (Assert $page) => $page->component('projects/customer-index'));
    expect($list->getContent())->toContain('Mine One')->not->toContain('Theirs Only');
    expect(json_decode(json_encode($list->viewData('page')), true)['props']['projects']['data'])
        ->toBe([['id' => $mine['project']->id, 'name' => 'Mine One', 'status' => 'active']]);

    // The foreign project cannot be opened, in any request form, and the answer is the unknown-project answer.
    foreach ([$this->actingAs($account)->get("/projects/{$theirs['project']->id}"), $this->actingAs($account)->getJson("/projects/{$theirs['project']->id}")] as $response) {
        $response->assertNotFound();
        expect($response->getContent())->not->toContain('Theirs Only')->not->toContain('PILOT-SECRET');
    }

    // The member project opens as the customer presentation, with the approved fields only.
    $own = $this->actingAs($account)->get("/projects/{$mine['project']->id}");
    $own->assertOk()->assertInertia(fn (Assert $page) => $page->component('projects/customer-show'));
    $props = array_diff_key(json_decode(json_encode($own->viewData('page')), true)['props'], array_flip(['errors', 'app', 'auth', 'shell', 'navigation', 'flash']));
    expect($props)->toBe([
        'project' => ['name' => 'Mine One', 'status' => 'active'],
        'milestones' => [['name' => 'Go-live milestone', 'dueDate' => '2026-11-02']],
    ]);
    expect($own->getContent())->not->toContain('PILOT-SECRET')->not->toContain('98765');
})->with(['projects.admin', 'both']);

it('gives a projects.admin account no operator project routes either', function () {
    $account = scopeAccounts()['projects.admin'];
    $theirs = pilotProjectFor(pilotCustomer(), 'Theirs Only');
    pilotMode('pilot');

    foreach (['board', 'tasks', 'milestones', 'time', 'edit'] as $tail) {
        $this->actingAs($account)->get("/projects/{$theirs['project']->id}/{$tail}")->assertNotFound();
    }

    $this->actingAs($account)->put("/projects/{$theirs['project']->id}/members", ['members' => []])->assertNotFound();
    $this->actingAs($account)->delete("/projects/{$theirs['project']->id}")->assertNotFound();
    expect(Project::whereKey($theirs['project']->id)->exists())->toBeTrue();
});

// ── Closed mode ───────────────────────────────────────────────────────────────

it('keeps every business route closed to these accounts in closed mode, whatever they hold', function (string $shape) {
    $account = scopeAccounts()[$shape];
    $fx = scopeTickets($account);
    $project = pilotProjectFor($account, 'Mine One');
    pilotMode('closed');

    foreach (['/tickets', "/tickets/{$fx['own']->id}", "/attachments/{$fx['ownInternal']->id}/download", '/projects', "/projects/{$project['project']->id}", '/operator/tickets'] as $uri) {
        $this->actingAs($account)->get($uri)->assertNotFound();
        $this->actingAs($account)->getJson($uri)->assertNotFound();
    }

    $this->actingAs($account)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('dashboard/customer-unavailable'));
})->with(['tickets.assign', 'projects.admin', 'both']);

// ── Eligible operators keep everything ────────────────────────────────────────

it('leaves an eligible operator with the broader ticket and project access it has always had', function () {
    $fx = scopeTickets(pilotCustomer());
    $project = pilotProjectFor(pilotCustomer(), 'Theirs Only');
    $operator = pilotOperator();
    pilotMode('pilot');

    // Foreign ticket: the operator is sent to the operator view, and may read the internal attachment and post a note.
    $this->actingAs($operator)->get(route('tickets.show', $fx['foreign']))->assertStatus(302)->assertRedirect(route('operator.tickets.show', $fx['foreign']));
    $this->actingAs($operator)->get(route('operator.tickets.show', $fx['foreign']))->assertOk();
    $this->actingAs($operator)->get(route('tickets.attachment.download', $fx['foreignInternal']))->assertOk();
    $this->actingAs($operator)->post(route('operator.tickets.replies.store', $fx['foreign']), ['body' => 'operator internal', 'is_internal' => true])->assertRedirect();
    expect(TicketReply::where('body', 'operator internal')->firstOrFail()->is_internal)->toBeTrue();

    // Every project is listed and open, in the full internal presentation.
    $this->actingAs($operator)->get(route('projects.index'))->assertInertia(fn (Assert $page) => $page->component('projects/index'));
    $this->actingAs($operator)->get("/projects/{$project['project']->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('projects/show'));
});

it('leaves a custom role that was deliberately made eligible with its tickets.assign and projects.admin', function () {
    $fx = scopeTickets(pilotCustomer());
    $project = pilotProjectFor(pilotCustomer(), 'Theirs Only');
    $staff = pilotCustomRoleUser('eligible-staff', [...PermissionCatalogue::userDefaults(), 'portal.operator', 'tickets.assign', 'projects.admin']);
    pilotMode('pilot');

    $this->actingAs($staff)->get(route('tickets.attachment.download', $fx['foreignInternal']))->assertOk();
    $this->actingAs($staff)->get(route('tickets.show', $fx['foreign']))->assertRedirect(route('operator.tickets.show', $fx['foreign']));
    $this->actingAs($staff)->get("/projects/{$project['project']->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('projects/show'));
});

it('leaves legacy mode exactly as it was for the same accounts', function () {
    $account = scopeAccounts()['both'];
    $fx = scopeTickets($account);
    $project = pilotProjectFor(pilotCustomer(), 'Theirs Only');
    pilotMode('legacy');

    $this->actingAs($account)->get(route('tickets.attachment.download', $fx['foreignInternal']))->assertOk();
    $this->actingAs($account)->get("/projects/{$project['project']->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('projects/show'));
});

// ── Role changes take effect on the next request ──────────────────────────────

it('widens and narrows scope on the next request when eligibility is granted and revoked', function () {
    $account = scopeAccounts()['both'];
    $fx = scopeTickets($account);
    $project = pilotProjectFor(pilotCustomer(), 'Theirs Only');
    pilotMode('pilot');

    $this->actingAs($account)->get(route('tickets.attachment.download', $fx['foreignInternal']))->assertForbidden();
    $this->actingAs($account)->get("/projects/{$project['project']->id}")->assertNotFound();

    User::findOrFail($account->id)->givePermissionTo('portal.operator');
    scopeNextRequest();
    $this->actingAs(User::findOrFail($account->id))->get(route('tickets.attachment.download', $fx['foreignInternal']))->assertOk();
    $this->actingAs(User::findOrFail($account->id))->get("/projects/{$project['project']->id}")->assertOk();

    User::findOrFail($account->id)->revokePermissionTo('portal.operator');
    scopeNextRequest();
    $this->actingAs(User::findOrFail($account->id))->get(route('tickets.attachment.download', $fx['foreignInternal']))->assertForbidden();
    $this->actingAs(User::findOrFail($account->id))->get("/projects/{$project['project']->id}")->assertNotFound();
});

it('narrows an operator to a customer on the next request when the operator role is removed', function () {
    $fx = scopeTickets(pilotCustomer());
    $user = pilotOperator();
    pilotMode('pilot');

    $this->actingAs($user)->get(route('tickets.attachment.download', $fx['foreignInternal']))->assertOk();

    User::findOrFail($user->id)->removeRole('operator');
    scopeNextRequest();
    $this->actingAs(User::findOrFail($user->id))->get(route('tickets.attachment.download', $fx['foreignInternal']))->assertForbidden();
});
