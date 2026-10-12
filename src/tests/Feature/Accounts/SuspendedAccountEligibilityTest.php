<?php

use App\Http\Presenters\ProjectPresenter;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Notifications\TicketRepliedNotification;
use App\Rules\ProjectTaskAssignee;
use App\Services\TicketService;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * Release 0.9 (H3, R0.9-1C): a suspended account is left out of NEW selections (assignment and membership candidates,
 * invoice clients) and NEW notifications, at the query and validation boundaries where they are chosen, while every
 * historical reference to it (author, owner, assignee, member lists) still shows it. There is no global scope on User.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = makeUser('operator', ['name' => 'Olive Operator']);
});

describe('ticket assignment', function () {
    beforeEach(function () {
        $this->activeAgent = makeUser('operator', ['name' => 'Active Agent']);
        $this->suspendedAgent = makeUser('operator', ['name' => 'Suspended Agent', 'disabled_at' => now()]);
    });

    it('offers only active holders as assignees, but keeps suspended ones in the index filter', function () {
        $ticket = Ticket::factory()->create();

        $index = $this->actingAs($this->operator)->get(route('operator.tickets.index'))->assertOk();
        $show = $this->actingAs($this->operator)->get(route('operator.tickets.show', $ticket))->assertOk();

        expect($index->viewData('assignableOperators')->pluck('name')->all())->toContain('Active Agent')->not->toContain('Suspended Agent')
            ->and($index->viewData('operators')->pluck('name')->all())->toContain('Active Agent', 'Suspended Agent')
            ->and($show->viewData('operators')->pluck('name')->all())->toContain('Active Agent')->not->toContain('Suspended Agent');
    });

    it('refuses to assign a ticket to a suspended holder, singly or in bulk', function () {
        $ticket = Ticket::factory()->create();

        $this->actingAs($this->operator)
            ->put(route('operator.tickets.assign', $ticket), ['assignee_id' => $this->suspendedAgent->id])
            ->assertSessionHasErrors(['assignee_id' => 'The selected assignee cannot be assigned tickets.']);
        $this->actingAs($this->operator)
            ->post(route('operator.tickets.bulk'), ['ticket_ids' => [$ticket->id], 'action' => 'assign', 'assignee_id' => $this->suspendedAgent->id])
            ->assertSessionHasErrors('assignee_id');

        expect($ticket->fresh()->assignee_id)->toBeNull();

        $this->actingAs($this->operator)
            ->put(route('operator.tickets.assign', $ticket), ['assignee_id' => $this->activeAgent->id])
            ->assertSessionHasNoErrors();
        expect($ticket->fresh()->assignee_id)->toBe($this->activeAgent->id);
    });

    it('still shows a suspended owner and assignee on the ticket', function () {
        $owner = makeUser('user', ['name' => 'Suspended Owner', 'disabled_at' => now()]);
        $ticket = Ticket::factory()->create(['user_id' => $owner->id, 'assignee_id' => $this->suspendedAgent->id]);

        // The assignee is shown as the selected current value, marked suspended, not as a new choice.
        $this->actingAs($this->operator)->get(route('operator.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Suspended Owner')
            ->assertSee('<option value="'.$this->suspendedAgent->id.'" selected>Suspended Agent (suspended)</option>', false);
        expect($ticket->fresh()->assignee_id)->toBe($this->suspendedAgent->id);
    });
});

describe('notifications', function () {
    it('sends no reply notification to a suspended owner or assignee, and still notifies the active one', function () {
        Notification::fake();
        $owner = makeUser('user', ['disabled_at' => now()]);
        $assignee = makeUser('operator');
        $ticket = Ticket::factory()->create(['user_id' => $owner->id, 'assignee_id' => $assignee->id]);

        app(TicketService::class)->addReply($ticket, $this->operator, 'An update', false);

        Notification::assertNotSentTo($owner, TicketRepliedNotification::class);
        Notification::assertSentTo($assignee, TicketRepliedNotification::class);
    });

    it('drops a notification queued before the suspension when it is delivered', function () {
        Event::fake([NotificationSent::class]);
        $recipient = makeUser('user');
        $ticket = Ticket::factory()->create(['user_id' => $recipient->id]);
        $reply = TicketReply::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $this->operator->id]);

        // Queued (serialized) while the recipient is active...
        $queued = serialize(new SendQueuedNotifications($recipient, new TicketRepliedNotification($ticket, $reply), ['mail']));
        // ...suspended before the worker runs it.
        $recipient->forceFill(['disabled_at' => now()])->save();

        unserialize($queued)->handle(app(ChannelManager::class));

        Event::assertNotDispatched(NotificationSent::class);
    });

    it('still delivers a queued notification to an active recipient (control)', function () {
        Event::fake([NotificationSent::class]);
        $recipient = makeUser('user');
        $ticket = Ticket::factory()->create(['user_id' => $recipient->id]);
        $reply = TicketReply::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $this->operator->id]);

        $queued = serialize(new SendQueuedNotifications($recipient, new TicketRepliedNotification($ticket, $reply), ['mail']));
        unserialize($queued)->handle(app(ChannelManager::class));

        Event::assertDispatched(NotificationSent::class, fn (NotificationSent $event) => $event->notifiable->is($recipient));
    });

    it('leaves notifications routed to an address alone (the invitation is not a user notification)', function () {
        Notification::fake();

        $this->actingAs($this->operator)->post(route('invitations.store'), ['email' => 'new.person@example.com'])
            ->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(InvitationNotification::class);
    });
});

describe('project task assignment', function () {
    beforeEach(function () {
        $this->project = makeProject($this->operator, 'Venture');
        $this->activeMember = makeUser('user', ['name' => 'Active Member']);
        $this->suspendedMember = makeUser('user', ['name' => 'Suspended Member', 'disabled_at' => now()]);
        $this->project->members()->attach([
            $this->activeMember->id => ['role' => 'member'],
            $this->suspendedMember->id => ['role' => 'member'],
        ]);
    });

    it('refuses a suspended member as a new assignee but keeps one who already holds the task', function () {
        $unassigned = makeTask($this->project->columns[1], ['title' => 'Open']);
        $held = makeTask($this->project->columns[1], ['title' => 'Held', 'assignee_id' => $this->suspendedMember->id]);
        $passes = fn ($task, $id) => Validator::make(['assignee_id' => $id], ['assignee_id' => [new ProjectTaskAssignee($this->project, $task)]])->passes();

        expect($passes($unassigned, $this->suspendedMember->id))->toBeFalse()
            ->and($passes($unassigned, $this->activeMember->id))->toBeTrue()
            ->and($passes($held, $this->suspendedMember->id))->toBeTrue()
            ->and($passes(null, $this->suspendedMember->id))->toBeFalse();
    });

    it('flags a suspended member on the board task page instead of offering them', function () {
        $task = makeTask($this->project->columns[1], ['title' => 'Held', 'assignee_id' => $this->suspendedMember->id]);

        $this->actingAs($this->operator)->get(route('projects.tasks.show', [$this->project, $task]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('task.assignee', ['id' => $this->suspendedMember->id, 'name' => 'Suspended Member'])
                ->where('options.members', fn ($members) => collect($members)->firstWhere('id', $this->suspendedMember->id) === ['id' => $this->suspendedMember->id, 'name' => 'Suspended Member', 'suspended' => true]
                    && collect($members)->firstWhere('id', $this->activeMember->id) === ['id' => $this->activeMember->id, 'name' => 'Active Member'])
                ->etc());
    });

    it('flags a suspended member in the Tasks list assignee options', function () {
        makeTask($this->project->columns[1], ['title' => 'Row', 'assignee_id' => $this->operator->id]);

        $options = $this->actingAs($this->operator)->get(route('tasks.index', ['view' => 'all']))
            ->viewData('page')['props']['assigneeOptions'];
        $members = collect(collect($options['projects'])->firstWhere('projectId', $this->project->id)['members'] ?? []);

        expect($members->firstWhere('id', $this->suspendedMember->id))->toBe(['id' => $this->suspendedMember->id, 'name' => 'Suspended Member', 'suspended' => true])
            ->and($members->firstWhere('id', $this->activeMember->id))->toBe(['id' => $this->activeMember->id, 'name' => 'Active Member']);
    });
});

describe('project membership', function () {
    beforeEach(function () {
        $this->project = makeProject($this->operator, 'Venture');
        $this->activeUser = makeUser('user', ['name' => 'Active Person']);
        $this->suspendedUser = makeUser('user', ['name' => 'Suspended Person', 'disabled_at' => now()]);
        $this->suspendedMember = makeUser('user', ['name' => 'Suspended Member', 'disabled_at' => now()]);
        $this->project->members()->attach($this->suspendedMember->id, ['role' => 'member']);
    });

    it('offers only active users as new members, and keeps an existing suspended member flagged on the edit page', function () {
        $create = collect(ProjectPresenter::memberCandidates());
        $edit = collect(ProjectPresenter::memberCandidates($this->project));

        expect($create->pluck('name')->all())->toContain('Active Person')->not->toContain('Suspended Person')->not->toContain('Suspended Member')
            ->and($edit->pluck('name')->all())->toContain('Active Person')->not->toContain('Suspended Person')
            ->and($edit->firstWhere('id', $this->suspendedMember->id))->toMatchArray(['name' => 'Suspended Member', 'suspended' => true])
            ->and($edit->firstWhere('id', $this->activeUser->id))->not->toHaveKey('suspended');
    });

    it('refuses a suspended user as a new member, but saving the list keeps an existing suspended member', function () {
        $this->actingAs($this->operator)->put(route('projects.members.sync', $this->project), [
            'members' => [
                ['user_id' => $this->suspendedMember->id, 'role' => 'member'],
                ['user_id' => $this->suspendedUser->id, 'role' => 'member'],
            ],
        ])->assertSessionHasErrors('members.1.user_id')->assertSessionDoesntHaveErrors('members.0.user_id');

        $this->actingAs($this->operator)->put(route('projects.members.sync', $this->project), [
            'members' => [
                ['user_id' => $this->suspendedMember->id, 'role' => 'manager'],
                ['user_id' => $this->activeUser->id, 'role' => 'member'],
            ],
        ])->assertSessionHasNoErrors();

        expect($this->project->members()->pluck('users.id')->sort()->values()->all())
            ->toBe(collect([$this->operator->id, $this->suspendedMember->id, $this->activeUser->id])->sort()->values()->all());
    });

    it('refuses a suspended user as an initial member of a new project', function () {
        $this->actingAs($this->operator)->post(route('projects.store'), [
            'name' => 'New venture',
            'members' => [['user_id' => $this->suspendedUser->id, 'role' => 'member']],
        ])->assertSessionHasErrors('members.0.user_id');
    });

    it('still lists a suspended member in the project\'s membership', function () {
        expect(collect(ProjectPresenter::members($this->project))->pluck('name')->all())->toContain('Suspended Member');
    });
});

describe('organization membership', function () {
    it('offers and accepts only active users as new members', function () {
        $organization = Organization::factory()->create(['owner_id' => $this->operator->id]);
        $active = makeUser('user', ['name' => 'Active Person']);
        $suspended = makeUser('user', ['name' => 'Suspended Person', 'disabled_at' => now()]);

        $page = $this->actingAs($this->operator)->get(route('organizations.show', $organization))->assertOk();
        expect($page->viewData('availableUsers')->pluck('name')->all())->toContain('Active Person')->not->toContain('Suspended Person');

        $this->actingAs($this->operator)->post(route('organizations.members.store', $organization), ['user_id' => $suspended->id, 'role' => 'member'])
            ->assertSessionHasErrors('user_id');
        expect($organization->members()->where('users.id', $suspended->id)->exists())->toBeFalse();
    });
});

describe('invoice clients', function () {
    beforeEach(function () {
        $this->active = makeUser('user', ['name' => 'Active Client']);
        $this->suspended = makeUser('user', ['name' => 'Suspended Client', 'disabled_at' => now()]);
        $this->payload = fn (int $clientId) => [
            'client_id' => $clientId,
            'issued_at' => now()->toDateString(),
            'due_at' => now()->addDays(30)->toDateString(),
            'items' => [['description' => 'Work', 'quantity' => 1, 'unit_price' => 100]],
        ];
    });

    it('offers and accepts only active clients for a new invoice', function () {
        $create = $this->actingAs($this->operator)->get(route('billing.invoices.create'))->assertOk();
        expect($create->viewData('clients')->pluck('name')->all())->toContain('Active Client')->not->toContain('Suspended Client');

        $this->actingAs($this->operator)->post(route('billing.invoices.store'), ($this->payload)($this->suspended->id))
            ->assertSessionHasErrors('client_id');
        expect(Invoice::where('client_id', $this->suspended->id)->exists())->toBeFalse();
    });

    it('keeps an invoice\'s suspended client on edit, but refuses switching to another suspended client', function () {
        $invoice = Invoice::factory()->create(['client_id' => $this->suspended->id, 'created_by' => $this->operator->id, 'status' => 'draft']);
        $other = makeUser('user', ['name' => 'Other Suspended', 'disabled_at' => now()]);

        $edit = $this->actingAs($this->operator)->get(route('billing.invoices.edit', $invoice))->assertOk();
        expect($edit->viewData('clients')->pluck('name')->all())->toContain('Suspended Client', 'Active Client')->not->toContain('Other Suspended');

        $this->actingAs($this->operator)->put(route('billing.invoices.update', $invoice), ($this->payload)($this->suspended->id))
            ->assertSessionDoesntHaveErrors('client_id');
        $this->actingAs($this->operator)->put(route('billing.invoices.update', $invoice), ($this->payload)($other->id))
            ->assertSessionHasErrors('client_id');

        expect($invoice->fresh()->client_id)->toBe($this->suspended->id);
    });
});
