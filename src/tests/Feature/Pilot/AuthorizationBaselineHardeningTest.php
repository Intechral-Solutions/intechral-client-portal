<?php

/*
 * Release 1.0, R1-1: authorization baseline hardening, in preparation for authorized UNISA support staff and managed
 * customer users. Six rules, each proved in the restricting modes (pilot, and closed where the surface is the same) and
 * never by a legacy-mode pass alone:
 *
 *   1. the built-in `user` role is the customer minimum and carries no billing, time or organization-wide permission;
 *   2. "operator" for the three organization scopes is the explicit `portal.operator` eligibility, not a role name;
 *   3. ProjectPolicy::manageMembers and the task-row projection restate `administers()`: `projects.admin` widens no customer;
 *   4. a ticket assignee must be operator-eligible, not merely hold `tickets.assign`;
 *   5. ticket-linked task visibility and time attribution do not follow a wider TicketPolicy::view;
 *   6. what a direct customer does today (tickets, member projects, Home) is unchanged.
 */

use App\Models\CrmCompany;
use App\Models\CrmContact;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;
use App\Policies\ProjectTimeAccess;
use App\Policies\TicketPolicy;
use App\Queries\TaskRowAbilities;
use App\Rules\AccessibleTimeContext;
use App\Services\BuiltInRoleSynchronizer;
use App\Services\CustomerSurfacePreflight;
use App\Services\InvoiceService;
use App\Services\RoleSyncPlan;
use App\Services\TicketService;
use App\Shared\Permissions\BuiltInRoles;
use App\Shared\Permissions\PermissionCatalogue;
use App\Shared\Pilot\CustomerPilotGate;
use App\Shared\Shell\ShellPresentation;
use App\Shared\Shell\ShellPresentationResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/PilotTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

/** What the built-in `user` role carried before R1-1, minus nothing: the permissions the package removes are the difference. */
const HARDENING_REMOVED_DEFAULTS = [
    'tickets.view_org', 'projects.view_org', 'tasks.view_org', 'billing.view', 'billing.view_org',
    'time.log', 'time.view_own', 'time.view_org',
];

const HARDENING_CUSTOMER_MINIMUM = ['tickets.view', 'tickets.create', 'projects.view', 'cms.view'];

/** @return list<string> */
function hardeningActions(RoleSyncPlan $plan, string $type): array
{
    return collect($plan->actions)->where('action', $type)->map(fn ($a) => ($a['role'] ?? '').':'.($a['permission'] ?? ''))->sort()->values()->all();
}

/** An account holding every operational capability and no operator eligibility: the isolated-permission customer. */
function hardeningEverythingButEligibility(): User
{
    return pilotCustomRoleUser('everything-but-eligibility', array_values(array_diff(PermissionCatalogue::all(), ['portal.operator'])));
}

/** An operator that is not the built-in role: `portal.operator` plus the endpoint permissions it needs. */
function hardeningCustomOperator(array $permissions = []): User
{
    // A role of its own per call: pilotCustomRoleUser() resets a role's permissions to exactly the list it is given.
    return pilotCustomRoleUser('support-lead-'.uniqid(), ['portal.operator', ...$permissions]);
}

/** @return array{organizations: list<int>, companies: list<int>, contacts: list<int>} what the organization scopes show this account */
function hardeningScopeView(User $user): array
{
    test()->actingAs($user);

    return [
        'organizations' => Organization::query()->pluck('id')->sort()->values()->all(),
        'companies' => CrmCompany::query()->pluck('id')->sort()->values()->all(),
        'contacts' => CrmContact::query()->pluck('id')->sort()->values()->all(),
    ];
}

// ── 1. The customer default role ──────────────────────────────────────────────────────────────────────────────────

describe('the default customer role', function () {
    it('is exactly the Release 1.0 customer minimum, and nothing billing, time or organization-wide', function () {
        expect(PermissionCatalogue::userDefaults())->toEqualCanonicalizing(HARDENING_CUSTOMER_MINIMUM)
            ->and(BuiltInRoles::definitions()['user'])->toEqualCanonicalizing(HARDENING_CUSTOMER_MINIMUM)
            ->and(array_intersect(PermissionCatalogue::userDefaults(), HARDENING_REMOVED_DEFAULTS))->toBe([])
            ->and(BuiltInRoles::problems())->toBe([]);

        $stored = Role::findByName('user', 'web')->permissions()->pluck('name')->all();
        expect($stored)->toEqualCanonicalizing(HARDENING_CUSTOMER_MINIMUM);
    });

    it('leaves every removed permission in the catalogue, and on the operator, as recorded debt and for explicit grants', function () {
        $operator = Role::findByName('operator', 'web')->permissions()->pluck('name')->all();

        foreach (HARDENING_REMOVED_DEFAULTS as $permission) {
            expect(PermissionCatalogue::all())->toContain($permission)
                ->and($operator)->toContain($permission);
        }
    });

    it('gives an invited customer none of the removed permissions, and a managed-customer user the same', function () {
        $customer = pilotCustomer();

        foreach (HARDENING_REMOVED_DEFAULTS as $permission) {
            expect($customer->can($permission))->toBeFalse("a default customer must not hold {$permission}");
        }
        foreach (HARDENING_CUSTOMER_MINIMUM as $permission) {
            expect($customer->can($permission))->toBeTrue();
        }
    });

    it('plans the move for an existing database as revocations only, and keeps explicit grants through the synchronization', function () {
        $sync = app(BuiltInRoleSynchronizer::class);
        $user = Role::findByName('user', 'web');
        $user->givePermissionTo(HARDENING_REMOVED_DEFAULTS);
        $billingContact = pilotCustomer()->givePermissionTo(['billing.view']);
        $custom = pilotCustomRoleUser('billing-contact', ['tickets.view', 'billing.view', 'time.log']);

        $plan = $sync->plan();

        expect(hardeningActions($plan, 'revoke'))->toBe(collect(HARDENING_REMOVED_DEFAULTS)->map(fn ($p) => "user:{$p}")->sort()->values()->all())
            ->and(hardeningActions($plan, 'grant'))->toBe([])
            ->and($plan->hasRevocations())->toBeTrue();

        // Revocations need explicit approval; with it, only the built-in role changes.
        $sync->apply($plan->fingerprint(), allowRevocations: true, audit: false);

        expect(Role::findByName('user', 'web')->permissions()->pluck('name')->all())->toEqualCanonicalizing(HARDENING_CUSTOMER_MINIMUM)
            ->and($billingContact->fresh()->can('billing.view'))->toBeTrue('a direct grant to a billing contact is not touched')
            ->and($custom->fresh()->can('billing.view'))->toBeTrue('a custom role is not touched')
            ->and($custom->fresh()->can('time.log'))->toBeTrue()
            ->and($sync->plan()->isEmpty())->toBeTrue();
    });
});

// ── 6. Direct-customer behaviour that must survive, and managed-customer isolation ─────────────────────────────────

describe('a direct customer in pilot mode', function () {
    it('still works its own tickets and member project, and sees Home', function () {
        pilotMode('pilot');
        $customer = pilotCustomer();
        $fx = pilotProjectFor($customer);
        $ticket = Ticket::factory()->open()->for($customer, 'user')->create(['title' => 'Mine']);
        Ticket::factory()->open()->create(['title' => 'Somebody else']);

        $this->actingAs($customer)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->component('dashboard/customer-home'));
        $this->actingAs($customer)->get(route('tickets.index'))->assertOk()->assertSee('Mine')->assertDontSee('Somebody else');
        $this->actingAs($customer)->get(route('tickets.show', $ticket))->assertOk();
        $this->actingAs($customer)->get(route('tickets.create'))->assertOk();
        $this->actingAs($customer)->get(route('projects.index'))->assertOk()->assertSee('Customer Visible Project');
        $this->actingAs($customer)->get(route('projects.show', $fx['project']))->assertOk();
    });

    it('sees no project it is not a member of', function () {
        pilotMode('pilot');
        $customer = pilotCustomer();
        $other = pilotProjectFor(pilotCustomer(), 'Not Mine');

        $this->actingAs($customer)->get(route('projects.show', $other['project']))->assertNotFound();
        expect(Project::visibleTo($customer)->count())->toBe(0);
    });

    it('is refused time, invoices and the task board by the gate AND by its permissions, not by the gate alone', function () {
        $customer = pilotCustomer();
        $fx = pilotProjectFor($customer);
        $invoice = pilotInvoiceFor($customer);

        // Pilot mode: the surface gate refuses (404) whatever is granted.
        pilotMode('pilot');
        foreach (['/time', '/my/invoices', "/my/invoices/{$invoice->id}", '/tasks'] as $uri) {
            $this->actingAs($customer)->get($uri)->assertNotFound();
        }

        // The permissions refuse on their own too: with the gate off, no billing, time or Time tab is offered.
        pilotMode('legacy');
        $this->actingAs($customer)->get('/time')->assertForbidden();
        expect(Gate::forUser($customer)->allows('view', $invoice))->toBeFalse()
            ->and(ProjectTimeAccess::scope($customer))->toBeNull();
    });

    it('keeps an explicit billing grant on existing functionality, still behind the pilot gate', function () {
        $contact = pilotCustomer()->givePermissionTo('billing.view');
        $invoice = pilotInvoiceFor($contact);
        $someoneElses = pilotInvoiceFor(pilotCustomer());

        // Existing functionality: the grant, not a built-in default, lets a customer read its OWN invoices.
        expect(Gate::forUser($contact)->allows('view', $invoice))->toBeTrue()
            ->and(Gate::forUser($contact)->allows('view', $someoneElses))->toBeFalse()
            ->and(Gate::forUser($contact)->allows('billing.manage'))->toBeFalse();

        // R1-1 builds no billing UI and widens no surface: pilot mode keeps the pages closed.
        pilotMode('pilot');
        $this->actingAs($contact)->get("/my/invoices/{$invoice->id}")->assertNotFound();
    });

    it('can be granted time by an authorized operator without becoming an operator', function () {
        $customer = pilotCustomer()->givePermissionTo('time.log');

        expect(ProjectTimeAccess::scope($customer))->toBe(ProjectTimeAccess::SCOPE_OWN)
            ->and(CustomerPilotGate::isOperator($customer))->toBeFalse();
    });
});

describe('a customer with an explicit billing or time grant', function () {
    it('keeps the customer shell and is not flagged by the pilot preflight as needing an eligibility decision', function () {
        $billing = pilotCustomRoleUser('billing-contact', [...PermissionCatalogue::userDefaults(), 'billing.view']);
        $timeKeeper = pilotCustomer()->givePermissionTo('time.log');

        foreach ([$billing, $timeKeeper] as $user) {
            expect(app(ShellPresentationResolver::class)->forUser($user))->toBe(ShellPresentation::Focused);
        }

        $report = app(CustomerSurfacePreflight::class)->report();
        expect($report['unresolvedUsers'])->toBe([])->and($report['unresolvedRoles'])->toBe([]);

        // ...whereas a real operational capability still is.
        $flagged = pilotCustomRoleUser('needs-a-decision', [...PermissionCatalogue::userDefaults(), 'billing.manage']);
        $report = app(CustomerSurfacePreflight::class)->report();
        expect(collect($report['unresolvedRoles'])->pluck('name')->all())->toContain('needs-a-decision');
    });

    it('names the retired defaults once, and the 0.9 role definition is exactly the minimum plus them', function () {
        expect(PermissionCatalogue::RETIRED_USER_DEFAULTS)->toEqualCanonicalizing(HARDENING_REMOVED_DEFAULTS)
            ->and(PermissionCatalogue::release09UserDefaults())->toEqualCanonicalizing([...HARDENING_CUSTOMER_MINIMUM, ...HARDENING_REMOVED_DEFAULTS]);
    });
});

// ── 2. Operator eligibility for the three organization scopes ──────────────────────────────────────────────────────

describe('organization scopes follow portal.operator, not a role name', function () {
    beforeEach(function () {
        $admin = pilotOperator();
        $this->mine = Organization::factory()->create(['owner_id' => $admin->id]);
        $this->theirs = Organization::factory()->create(['owner_id' => $admin->id]);
        $this->myCompany = CrmCompany::factory()->create(['created_by' => $admin->id, 'organization_id' => $this->mine->id]);
        $this->theirCompany = CrmCompany::factory()->create(['created_by' => $admin->id, 'organization_id' => $this->theirs->id]);
        $this->myContact = CrmContact::factory()->create(['crm_company_id' => $this->myCompany->id, 'created_by' => $admin->id]);
        $this->theirContact = CrmContact::factory()->create(['crm_company_id' => $this->theirCompany->id, 'created_by' => $admin->id]);
    });

    it('gives the built-in operator and a custom-role operator the same cross-organization view, in every mode', function (string $mode) {
        pilotMode($mode);
        $builtIn = hardeningScopeView(pilotOperator());
        $custom = hardeningScopeView(hardeningCustomOperator(['crm.view']));

        expect($custom)->toBe($builtIn)
            ->and($custom['organizations'])->toContain($this->mine->id, $this->theirs->id)
            ->and($custom['companies'])->toContain($this->myCompany->id, $this->theirCompany->id)
            ->and($custom['contacts'])->toContain($this->myContact->id, $this->theirContact->id);
    })->with(['closed', 'pilot', 'legacy']);

    it('keeps an ordinary customer, and a customer with every operational capability, to its own organizations', function (string $mode) {
        pilotMode($mode);
        $member = pilotCustomer();
        $everything = hardeningEverythingButEligibility();
        $this->mine->members()->attach($member->id, ['role' => 'member']);
        $this->mine->members()->attach($everything->id, ['role' => 'member']);

        foreach ([$member, $everything] as $user) {
            $seen = hardeningScopeView($user);

            expect($seen['organizations'])->toBe([$this->mine->id])
                ->and($seen['companies'])->toBe([$this->myCompany->id])
                ->and($seen['contacts'])->toBe([$this->myContact->id]);
        }
    })->with(['closed', 'pilot', 'legacy']);

    it('shows a customer with no membership nothing', function () {
        pilotMode('pilot');
        $seen = hardeningScopeView(hardeningEverythingButEligibility());

        expect($seen)->toBe(['organizations' => [], 'companies' => [], 'contacts' => []]);
    });

    it('does not treat the operator role NAME as eligibility: an operator whose capability was never synchronized is scoped like a customer', function () {
        // Only an unsynchronized database can produce this (roles:sync grants portal.operator to the built-in role); the
        // customer boundary already treats the account as a customer, and the scopes now agree with it.
        Role::findByName('operator', 'web')->revokePermissionTo('portal.operator');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        pilotMode('pilot');

        $seen = hardeningScopeView(pilotOperator());

        expect($seen['organizations'])->toBe([])->and($seen['companies'])->toBe([]);
    });
});

// ── 3. Project policy ────────────────────────────────────────────────────────────────────────────────────────────

describe('project policy at the customer boundary', function () {
    it('refuses membership administration to a customer holding projects.admin, for an existing project and for creation', function (string $mode) {
        pilotMode($mode);
        $customer = pilotCustomRoleUser('project-admin-customer', [...PermissionCatalogue::userDefaults(), 'projects.admin', 'projects.manage']);
        $fx = pilotProjectFor($customer);

        expect($customer->can('projects.admin'))->toBeTrue()
            ->and(Gate::forUser($customer)->allows('manageMembers', $fx['project']))->toBeFalse()
            ->and(Gate::forUser($customer)->allows('manageMembers', Project::class))->toBeFalse();
    })->with(['closed', 'pilot']);

    it('does not let projects.admin manage a project a customer is only a member of, or see one it is not in', function () {
        pilotMode('pilot');
        $customer = pilotCustomRoleUser('project-admin-customer', [...PermissionCatalogue::userDefaults(), 'projects.admin']);
        $member = pilotProjectFor($customer);
        $stranger = pilotProjectFor(pilotCustomer(), 'Not Mine');

        expect(Gate::forUser($customer)->allows('view', $member['project']))->toBeTrue()
            ->and(Gate::forUser($customer)->allows('manage', $member['project']))->toBeFalse()
            ->and(Gate::forUser($customer)->allows('view', $stranger['project']))->toBeFalse()
            ->and(Gate::forUser($customer)->allows('manage', $stranger['project']))->toBeFalse()
            ->and(Project::visibleTo($customer)->pluck('id')->all())->toBe([$member['project']->id]);
    });

    it('preserves membership semantics: a member with projects.manage and the manager role still manages that project only', function () {
        pilotMode('pilot');
        $customer = pilotCustomRoleUser('project-manager-customer', [...PermissionCatalogue::userDefaults(), 'projects.manage']);
        $managed = pilotProjectFor($customer);
        $managed['project']->members()->updateExistingPivot($customer->id, ['role' => 'manager']);
        $plain = pilotProjectFor($customer, 'Plain Member');

        expect(Gate::forUser($customer)->allows('manage', $managed['project']))->toBeTrue()
            ->and(Gate::forUser($customer)->allows('manage', $plain['project']))->toBeFalse()
            ->and(Gate::forUser($customer)->allows('manageMembers', $managed['project']))->toBeFalse();
    });

    it('leaves legitimate operator behaviour unchanged', function (string $mode) {
        pilotMode($mode);
        $builtIn = pilotOperator();
        $custom = hardeningCustomOperator(['projects.admin', 'projects.manage']);
        $manageOnly = hardeningCustomOperator(['projects.manage']);
        $fx = pilotProjectFor(pilotCustomer());

        foreach ([$builtIn, $custom] as $operator) {
            expect(Gate::forUser($operator)->allows('manageMembers', $fx['project']))->toBeTrue()
                ->and(Gate::forUser($operator)->allows('manageMembers', Project::class))->toBeTrue()
                ->and(Gate::forUser($operator)->allows('manage', $fx['project']))->toBeTrue()
                ->and(Gate::forUser($operator)->allows('view', $fx['project']))->toBeTrue();
        }

        // Narrower than manage(): projects.manage alone never administered members, eligible or not.
        expect(Gate::forUser($manageOnly)->allows('manageMembers', $fx['project']))->toBeFalse();
    })->with(['closed', 'pilot', 'legacy']);

    it('keeps the task-row projection identical to TaskPolicy for every actor shape, in pilot mode', function () {
        pilotMode('pilot');
        $adminCustomer = pilotCustomRoleUser('project-admin-customer', [...PermissionCatalogue::userDefaults(), 'projects.admin', 'projects.manage']);
        $plainCustomer = pilotCustomer();
        $operator = pilotOperator();
        $customOperator = hardeningCustomOperator(['projects.admin', 'projects.manage']);

        $mine = pilotProjectFor($adminCustomer, 'Admin Customer Member');
        $mine['project']->members()->attach($plainCustomer->id, ['role' => 'member']);
        $theirs = pilotProjectFor(pilotCustomer(), 'Foreign');
        $tasks = [$mine['task'], $theirs['task']];

        foreach ([$adminCustomer, $plainCustomer, $operator, $customOperator] as $actor) {
            $abilities = TaskRowAbilities::for($actor, $tasks);

            foreach ($tasks as $task) {
                $task->unsetRelation('project');
                expect($abilities->canViewProject($task->project_id))->toBe(Gate::forUser($actor)->allows('view', $task->project), "view: {$actor->id} on task {$task->id}");

                $row = $abilities->of($task);
                expect($row)->toBe([
                    'complete' => Gate::forUser($actor)->allows('complete', $task),
                    'reopen' => Gate::forUser($actor)->allows('reopen', $task),
                    'assign' => Gate::forUser($actor)->allows('assign', $task),
                ], "row abilities: actor {$actor->id}, task {$task->id}");
            }
        }

        // The case this fixed: the admin customer is a member, and must not be shown structural abilities on the row.
        expect(TaskRowAbilities::for($adminCustomer, [$mine['task']])->of($mine['task'])['assign'])->toBeFalse();
    });
});

// ── 4. Ticket assignee eligibility ─────────────────────────────────────────────────────────────────────────────────

describe('ticket assignees must be operator-eligible', function () {
    it('refuses a customer holding tickets.assign, in every mode, and accepts an eligible operator', function (string $mode) {
        pilotMode($mode);
        $service = app(TicketService::class);
        $assignOnly = pilotCustomer()->givePermissionTo('tickets.assign');
        $everything = hardeningEverythingButEligibility();
        $customOperator = hardeningCustomOperator(['tickets.assign']);
        $builtIn = pilotOperator();

        foreach ([$assignOnly, $everything] as $customer) {
            expect($customer->can('tickets.assign'))->toBeTrue()
                ->and(fn () => $service->assertAssignable($customer->id))->toThrow(ValidationException::class)
                ->and(TicketService::isAssignable($customer))->toBeFalse();
        }

        $service->assertAssignable($customOperator->id);
        $service->assertAssignable($builtIn->id);
        $service->assertAssignable(null);
        expect(TicketService::isAssignable($customOperator))->toBeTrue();
    })->with(['closed', 'pilot', 'legacy']);

    it('still refuses an eligible operator who lacks tickets.assign, and a suspended one', function () {
        pilotMode('pilot');
        $eligibleOnly = hardeningCustomOperator();
        $suspended = pilotOperator(['disabled_at' => now()]);

        expect(TicketService::isAssignable($eligibleOnly))->toBeFalse()
            ->and(TicketService::isAssignable($suspended))->toBeFalse();
    });

    it('never assigns a ticket to such a customer through the single or the bulk action, and writes nothing', function () {
        pilotMode('pilot');
        $operator = pilotOperator();
        $customer = pilotCustomer()->givePermissionTo('tickets.assign');
        $ticket = Ticket::factory()->open()->create();

        $this->actingAs($operator)->put(route('operator.tickets.assign', $ticket), ['assignee_id' => $customer->id])->assertSessionHasErrors('assignee_id');
        $this->actingAs($operator)->post(route('operator.tickets.bulk'), ['ticket_refs' => [$ticket->ticket_number], 'action' => 'assign', 'assignee_id' => $customer->id])->assertSessionHasErrors('assignee_id');

        expect($ticket->fresh()->assignee_id)->toBeNull();
    });

    it('offers only eligible operators in the assignee choices, and still lists a customer-held assignment in the filter', function () {
        pilotMode('pilot');
        $operator = pilotOperator();
        $customOperator = hardeningCustomOperator(['tickets.assign']);
        $customer = pilotCustomer(['name' => 'Assign Only Customer'])->givePermissionTo('tickets.assign');
        // A historical assignment to that customer (made before eligibility was enforced): it must stay searchable.
        $ticket = Ticket::factory()->open()->create(['assignee_id' => $customer->id]);

        $this->actingAs($operator)->get(route('operator.tickets.index'))
            ->assertOk()
            ->assertViewHas('assignableOperators', fn ($list) => $list->pluck('id')->contains($operator->id)
                && $list->pluck('id')->contains($customOperator->id)
                && ! $list->pluck('id')->contains($customer->id))
            // The FILTER lists every tickets.assign holder, so the historical assignment can still be searched for...
            ->assertViewHas('operators', fn ($list) => $list->pluck('id')->contains($customer->id)
                && $list->pluck('id')->contains($operator->id)
                && $list->pluck('id')->contains($customOperator->id));

        // ...and filtering by that assignee returns the ticket.
        $this->actingAs($operator)->get(route('operator.tickets.index', ['assignee' => $customer->id]))
            ->assertOk()
            ->assertViewHas('tickets', fn ($page) => $page->pluck('id')->contains($ticket->id));

        $this->actingAs($operator)->get(route('operator.tickets.show', $ticket))
            ->assertOk()
            ->assertViewHas('operators', fn ($list) => $list->pluck('id')->contains($customOperator->id) && ! $list->pluck('id')->contains($customer->id));
    });

    it('keeps an existing assignee who is no longer assignable as the selected value, instead of silently showing "Unassigned"', function (string $case) {
        pilotMode('pilot');
        $operator = pilotOperator();
        // ACTIVE but not operator-eligible (the case R1-1 creates), or suspended (the Release 0.9 case that must keep working).
        $assignee = $case === 'suspended'
            ? pilotOperator(['name' => 'Stale Assignee', 'disabled_at' => now()])
            : pilotCustomer(['name' => 'Stale Assignee'])->givePermissionTo('tickets.assign');
        $label = $case === 'suspended' ? '(suspended)' : '(not assignable)';
        $ticket = Ticket::factory()->open()->create(['assignee_id' => $assignee->id]);
        expect($case === 'suspended' ? $assignee->isSuspended() : TicketService::isAssignable($assignee))->toBe($case === 'suspended');

        $html = $this->actingAs($operator)->get(route('operator.tickets.show', $ticket))->assertOk()->getContent();
        preg_match('/<select[^>]*name="assignee_id".*?<\/select>/s', $html, $select);
        $options = $select[0] ?? '';

        // The selected option is the existing assignee, labelled, and the blank "Unassigned" option is not selected.
        expect($options)->toMatch('/<option value="'.$assignee->id.'"\s+selected[^>]*>\s*Stale Assignee '.preg_quote($label, '/').'\s*<\/option>/')
            ->and($options)->toMatch('/<option value="">\s*Unassigned\s*<\/option>/')
            ->and($options)->not->toMatch('/<option value=""[^>]*selected/')
            // It is shown as the current value only: exactly one option carries its id, and it is that one.
            ->and(substr_count($options, 'value="'.$assignee->id.'"'))->toBe(1);

        // Server-side eligibility is untouched: re-submitting the stale assignee is refused and changes nothing...
        $this->actingAs($operator)->put(route('operator.tickets.assign', $ticket), ['assignee_id' => $assignee->id])->assertSessionHasErrors('assignee_id');
        expect($ticket->fresh()->assignee_id)->toBe($assignee->id);

        // ...and the explicit "Unassigned" choice still unassigns.
        $this->actingAs($operator)->put(route('operator.tickets.assign', $ticket), ['assignee_id' => ''])->assertSessionHasNoErrors();
        expect($ticket->fresh()->assignee_id)->toBeNull();
    })->with(['active, not operator-eligible' => 'not-eligible', 'suspended' => 'suspended']);

    it('lists an eligible assignee once, as a normal choice, with no stale-assignee label', function () {
        pilotMode('pilot');
        $operator = pilotOperator();
        $assignee = pilotOperator(['name' => 'Current Operator']);
        $ticket = Ticket::factory()->open()->create(['assignee_id' => $assignee->id]);

        $html = $this->actingAs($operator)->get(route('operator.tickets.show', $ticket))->assertOk()->getContent();
        preg_match('/<select[^>]*name="assignee_id".*?<\/select>/s', $html, $select);

        expect(substr_count($select[0], 'value="'.$assignee->id.'"'))->toBe(1)
            ->and($select[0])->not->toContain('not assignable')->not->toContain('(suspended)');
    });
});

// ── 5. Ticket authorization reuse ──────────────────────────────────────────────────────────────────────────────────

/**
 * A stand-in for the later partner-support widening of TicketPolicy::view (Release 1.0): it admits one extra account and
 * nothing else changes. It subclasses the real policy, so everything TaskPolicy and AccessibleTimeContext ask for the
 * ticket is answered by the real rules.
 */
class HardeningWiderTicketPolicy extends TicketPolicy
{
    public static ?int $partnerId = null;

    public function view(User $user, Ticket $ticket): bool
    {
        return $user->id === self::$partnerId || parent::view($user, $ticket);
    }
}

describe('ticket-linked task and time access does not follow a wider ticket viewer', function () {
    beforeEach(function () {
        $this->owner = pilotCustomer()->givePermissionTo('time.log');
        $this->ticket = Ticket::factory()->open()->for($this->owner, 'user')->create();
        $this->ticketTask = Task::factory()->standalone()->create(['ticket_id' => $this->ticket->id, 'assignee_id' => null, 'status' => 'todo']);
    });

    it('does not give a partner who gains Ticket visibility the ticket task, or time on the ticket or its task', function () {
        pilotMode('pilot');
        $partner = pilotCustomRoleUser('partner-support', ['tickets.view', 'tickets.assign', 'time.log']);
        HardeningWiderTicketPolicy::$partnerId = $partner->id;
        Gate::policy(Ticket::class, HardeningWiderTicketPolicy::class);

        expect(Gate::forUser($partner)->allows('view', $this->ticket))->toBeTrue('the widening is in force: the partner sees the ticket')
            ->and(Gate::forUser($partner)->allows('view', $this->ticketTask))->toBeFalse('but not its task')
            ->and(AccessibleTimeContext::allows($partner, 'task', $this->ticketTask->id))->toBeFalse()
            ->and(AccessibleTimeContext::allows($partner, 'ticket', $this->ticket->id))->toBeFalse();

        HardeningWiderTicketPolicy::$partnerId = null;
    });

    it('does not let a customer holding tickets.assign time their own ticket in a restricting mode', function (string $mode) {
        pilotMode($mode);
        $owner = $this->owner->givePermissionTo('tickets.assign');

        expect(AccessibleTimeContext::allows($owner, 'ticket', $this->ticket->id))->toBeFalse();
    })->with(['closed', 'pilot']);

    it('preserves what was independently authorized: the requester and an operator view the task, the assignee and requester keep task time, an operator times the ticket', function () {
        pilotMode('pilot');
        $assignee = pilotCustomer()->givePermissionTo('time.log');
        $this->ticketTask->update(['assignee_id' => $assignee->id]);
        $operator = pilotOperator();

        expect(Gate::forUser($this->owner)->allows('view', $this->ticketTask))->toBeTrue()
            ->and(Gate::forUser($operator)->allows('view', $this->ticketTask))->toBeTrue()
            ->and(Gate::forUser($assignee)->allows('view', $this->ticketTask))->toBeFalse('assignment never grants visibility')
            ->and(AccessibleTimeContext::allows($assignee, 'task', $this->ticketTask->id))->toBeTrue('the assignee shortcut is unchanged')
            ->and(AccessibleTimeContext::allows($this->owner, 'task', $this->ticketTask->id))->toBeTrue('the requester fallback is unchanged')
            ->and(AccessibleTimeContext::allows($operator, 'ticket', $this->ticket->id))->toBeTrue()
            ->and(AccessibleTimeContext::allows($this->owner, 'ticket', $this->ticket->id))->toBeFalse('ticket time stays operator work');

        foreach (['update', 'complete', 'reopen', 'delete', 'assign', 'move'] as $ability) {
            expect(Gate::forUser($operator)->allows($ability, $this->ticketTask))->toBeFalse("every ticket-task mutation stays denied ({$ability})");
        }
    });

    afterEach(function () {
        HardeningWiderTicketPolicy::$partnerId = null;
        Gate::policy(Ticket::class, TicketPolicy::class);
    });
});

it('has no remaining operator test by role name in the organization scopes', function () {
    foreach (['OrganizationScope', 'OrganizationMembershipScope', 'OrganizationThroughCompanyScope'] as $scope) {
        $source = file_get_contents(app_path("Shared/Scopes/{$scope}.php"));

        expect($source)->not->toContain("hasRole('operator')")
            ->and($source)->toContain('CustomerPilotGate::isOperator');
    }
});

// ── 7. The plain customer, as the new defaults leave one ───────────────────────────────────────────────────────────────

describe('a plain customer on the new defaults', function () {
    it('holds exactly the customer minimum and is refused every billing, time and organization-wide ability', function () {
        $customer = pilotCustomer();
        $invoice = pilotInvoiceFor($customer);

        expect($customer->getAllPermissions()->pluck('name')->all())->toEqualCanonicalizing(HARDENING_CUSTOMER_MINIMUM);

        foreach (HARDENING_REMOVED_DEFAULTS as $permission) {
            expect($customer->can($permission))->toBeFalse("the default customer holds no {$permission}");
        }
        expect(Gate::forUser($customer)->allows('view', $invoice))->toBeFalse('not even their own invoice')
            ->and(Gate::forUser($customer)->allows('pay', $invoice))->toBeFalse()
            ->and(Gate::forUser($customer)->allows('viewOwn', Invoice::class))->toBeFalse()
            ->and(ProjectTimeAccess::scope($customer))->toBeNull();
    });

    it('is told only its customer minimum by the browser, and offered no Finance or Time navigation, in legacy mode', function () {
        pilotMode('legacy');
        $customer = pilotCustomer();

        $this->actingAs($customer)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/customer-home')
            ->where('shell.presentation', 'focused')
            ->where('auth.permissions', ['cms.view', 'projects.view', 'tickets.create', 'tickets.view'])
            ->where('invoices', null)
            ->where('navigation.workspaces', fn ($workspaces) => collect($workspaces)->pluck('key')->all() === ['home', 'helpdesk', 'projects', 'resources']));
    });

    it('is told only the three pilot capabilities, and offered Home, Support and Projects, in pilot mode', function () {
        pilotMode('pilot');
        $customer = pilotCustomer();

        $this->actingAs($customer)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('shell.presentation', 'focused')
            ->where('auth.permissions', ['projects.view', 'tickets.create', 'tickets.view'])
            ->where('invoices', null)
            ->where('navigation.workspaces', fn ($workspaces) => collect($workspaces)->pluck('key')->all() === ['home', 'helpdesk', 'projects']));

        pilotMode('closed');
        $this->actingAs($customer)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.permissions', [])
            ->where('navigation.workspaces', fn ($workspaces) => collect($workspaces)->pluck('key')->all() === ['home']));
    });

    it('cannot create, start or change time, in any mode, and nothing is written', function (string $mode) {
        pilotMode($mode);
        $customer = pilotCustomer();
        $before = TimeEntry::count();

        $payload = ['description' => 'Sneaky', 'minutes' => 30, 'date' => now()->toDateString()];
        $expected = $mode === 'legacy' ? 403 : 404;   // the pilot gate answers 404 before the capability's 403

        $this->actingAs($customer)->post(route('time.store'), $payload)->assertStatus($expected);
        $this->actingAs($customer)->get(route('time.index'))->assertStatus($expected);

        expect(TimeEntry::count())->toBe($before);
    })->with(['closed', 'pilot', 'legacy']);

    it('still reaches Time when explicitly granted time.log (legacy mode: the existing functionality)', function () {
        pilotMode('legacy');
        $customer = pilotCustomer()->givePermissionTo('time.log');

        $this->actingAs($customer)->get(route('time.index'))->assertOk();
    });
});

// ── 8. Customer invoices need an explicit billing.view ────────────────────────────────────────────────────────────────

describe('customer invoices: ownership and payable state are necessary, an explicit billing.view is required', function () {
    it('refuses the list, the page and payment to a plain customer in legacy mode, even for an invoice that names them', function () {
        pilotMode('legacy');
        $customer = pilotCustomer();
        $invoice = pilotInvoiceFor($customer);

        $this->actingAs($customer)->get(route('billing.client.invoices.index'))->assertForbidden();
        $this->actingAs($customer)->get(route('billing.client.invoices.show', $invoice))->assertForbidden();
        $this->actingAs($customer)->get(route('billing.invoices.pay', $invoice))->assertForbidden();
        $this->actingAs($customer)->postJson(route('billing.invoices.pay.intent', $invoice))->assertForbidden();
    });

    it('lets an explicitly authorized customer list and open only their own invoices, and pay only their own payable one', function () {
        pilotMode('legacy');
        $this->mock(InvoiceService::class, fn ($mock) => $mock->shouldReceive('createPaymentIntent')->andReturn('pi_secret_test'));
        $contact = pilotCustomer()->givePermissionTo('billing.view');
        $mine = pilotInvoiceFor($contact);
        $paid = Invoice::factory()->create(['client_id' => $contact->id, 'status' => 'paid']);
        $theirs = pilotInvoiceFor(pilotCustomer());

        $this->actingAs($contact)->get(route('billing.client.invoices.index'))->assertOk()
            ->assertSee($mine->invoice_number)->assertDontSee($theirs->invoice_number);
        $this->actingAs($contact)->get(route('billing.client.invoices.show', $mine))->assertOk();
        $this->actingAs($contact)->get(route('billing.client.invoices.show', $theirs))->assertForbidden();

        $this->actingAs($contact)->get(route('billing.invoices.pay', $mine))->assertOk();
        $this->actingAs($contact)->postJson(route('billing.invoices.pay.intent', $mine))->assertOk()->assertJson(['clientSecret' => 'pi_secret_test']);
        // Ownership and the payable state still apply on top of the capability.
        $this->actingAs($contact)->get(route('billing.invoices.pay', $theirs))->assertForbidden();
        $this->actingAs($contact)->get(route('billing.invoices.pay', $paid))->assertForbidden();
    });

    it('keeps the pilot gate: an explicitly authorized customer still gets 404 on every invoice route in pilot and closed mode', function (string $mode) {
        pilotMode($mode);
        $contact = pilotCustomer()->givePermissionTo('billing.view');
        $invoice = pilotInvoiceFor($contact);

        foreach (['/my/invoices', "/my/invoices/{$invoice->id}", "/billing/invoices/{$invoice->id}/pay"] as $uri) {
            $this->actingAs($contact)->get($uri)->assertNotFound();
        }
        $this->actingAs($contact)->postJson("/billing/invoices/{$invoice->id}/pay/intent")->assertNotFound();
    })->with(['pilot', 'closed']);

    it('answers the ability from the capability, not from the role: a custom-role billing contact is authorized, a managed-style customer is not', function () {
        pilotMode('legacy');
        $contact = pilotCustomRoleUser('billing-contact-'.uniqid(), [...PermissionCatalogue::userDefaults(), 'billing.view']);
        $managed = pilotCustomRoleUser('managed-customer-'.uniqid(), PermissionCatalogue::userDefaults());
        $contactInvoice = pilotInvoiceFor($contact);
        $managedInvoice = pilotInvoiceFor($managed);

        expect(Gate::forUser($contact)->allows('viewOwn', Invoice::class))->toBeTrue()
            ->and(Gate::forUser($contact)->allows('pay', $contactInvoice))->toBeTrue()
            ->and(Gate::forUser($managed)->allows('viewOwn', Invoice::class))->toBeFalse()
            ->and(Gate::forUser($managed)->allows('pay', $managedInvoice))->toBeFalse()
            ->and(Gate::forUser($managed)->allows('view', $managedInvoice))->toBeFalse();
    });

    it('leaves operator billing capabilities as they were', function () {
        pilotMode('pilot');
        $operator = pilotOperator();
        $invoice = pilotInvoiceFor(pilotCustomer());

        expect(Gate::forUser($operator)->allows('view', $invoice))->toBeTrue()
            ->and(Gate::forUser($operator)->allows('viewAny', Invoice::class))->toBeTrue()
            ->and(Gate::forUser($operator)->allows('viewOwn', Invoice::class))->toBeTrue();
        $this->actingAs($operator)->get(route('billing.invoices.index'))->assertOk();
        $this->actingAs($operator)->get(route('billing.invoices.show', $invoice))->assertOk();
    });
});
