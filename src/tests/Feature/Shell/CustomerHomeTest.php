<?php

/*
 * EPIC-017 WP1 (§5, §26): the Focused customer Home.
 *
 * `/dashboard` keeps its route and controller. A user who resolves to the Focused shell gets
 * `dashboard/customer-home`; anyone else gets the operator dashboard, unchanged (pinned by
 * `DashboardInertiaTest`). The page is built only from data the dashboard already computes for the
 * actor, scoped to their own records, with an exact-key allowlist: a later change that adds a key
 * must change this test.
 *
 * Helper names carry the `customerHome` prefix (Pest loads every test file into one process).
 */

use App\Models\Invoice;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Shared\Permissions\PermissionCatalogue;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

/** A customer; `$without` takes some customer defaults away through a custom role. */
function customerHomeUser(array $without = []): User
{
    $user = User::factory()->create();

    if ($without === []) {
        $user->assignRole('user');
    } else {
        $role = Role::firstOrCreate(['name' => 'customer-home-'.md5(implode(',', $without)), 'guard_name' => 'web']);
        $role->syncPermissions(array_values(array_diff(PermissionCatalogue::userDefaults(), $without)));
        $user->assignRole($role);
    }

    return User::findOrFail($user->id);
}

/** @param  array<string, mixed>  $attributes */
function customerHomeTickets(User $owner, string $status, int $count, string $titlePrefix, array $attributes = []): void
{
    Ticket::factory()->count($count)->for($owner, 'user')->create(['status' => $status, 'title' => $titlePrefix, ...$attributes]);
}

/** Every string key at any depth. @return list<string> */
function customerHomeKeysDeep(mixed $payload): array
{
    $found = [];

    if (is_array($payload)) {
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $found[] = $key;
            }

            array_push($found, ...customerHomeKeysDeep($value));
        }
    }

    return $found;
}

/** The page's own props (everything beyond the shared set), as a plain array. */
function customerHomeProps(User $user): array
{
    $response = test()->actingAs($user)->get(route('dashboard'));
    $response->assertOk();

    return json_decode(json_encode($response->viewData('page')), true)['props'];
}

it('renders the customer Home for a Focused user, with exactly the planned props', function () {
    $user = customerHomeUser();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/customer-home')
            ->where('shell.presentation', 'focused'));

    $props = customerHomeProps($user);
    $own = array_values(array_diff(array_keys($props), ['errors', 'app', 'auth', 'shell', 'navigation', 'flash']));

    expect($own)->toEqualCanonicalizing(['waitingOnYou', 'openRequests', 'invoices', 'projects', 'support', 'newRequest']);
});

it('lists only the actor\'s own requests: pending ones as waiting, open and in-progress ones as open', function () {
    $user = customerHomeUser();
    $other = customerHomeUser();

    Ticket::factory()->for($user, 'user')->create(['status' => 'pending_user', 'title' => 'Needs my answer']);
    Ticket::factory()->for($user, 'user')->create(['status' => 'open', 'title' => 'Open one']);
    Ticket::factory()->for($user, 'user')->create(['status' => 'in_progress', 'title' => 'Being worked']);
    Ticket::factory()->for($user, 'user')->create(['status' => 'resolved', 'title' => 'Resolved one']);
    Ticket::factory()->for($user, 'user')->create(['status' => 'closed', 'title' => 'Closed one']);
    Ticket::factory()->for($other, 'user')->create(['status' => 'pending_user', 'title' => 'Not mine, waiting']);
    Ticket::factory()->for($other, 'user')->create(['status' => 'open', 'title' => 'Not mine, open']);

    $props = customerHomeProps($user);

    expect(array_column($props['waitingOnYou'], 'title'))->toBe(['Needs my answer'])
        ->and(array_column($props['openRequests'], 'title'))->toEqualCanonicalizing(['Open one', 'Being worked'])
        ->and(array_column($props['openRequests'], 'statusLabel'))->toEqualCanonicalizing(['Open', 'In progress']);

    // Nothing of anyone else's, or of a finished request, reaches the page anywhere.
    $json = json_encode($props);

    foreach (['Not mine', 'Resolved one', 'Closed one'] as $absent) {
        expect($json)->not->toContain($absent);
    }
});

it('carries a deliberately small, allowlisted row: no requester, priority, SLA, assignee, company or time', function () {
    $user = customerHomeUser();
    $agent = User::factory()->create();
    Ticket::factory()->for($user, 'user')->create(['status' => 'pending_user', 'assignee_id' => $agent->id, 'priority' => 'critical']);
    Ticket::factory()->for($user, 'user')->create(['status' => 'open', 'assignee_id' => $agent->id, 'priority' => 'high']);

    $props = customerHomeProps($user);

    expect(array_keys($props['waitingOnYou'][0]))->toEqualCanonicalizing(['id', 'number', 'title', 'createdAt', 'createdAtHuman', 'href', 'visit'])
        ->and(array_keys($props['openRequests'][0]))->toEqualCanonicalizing(['id', 'number', 'title', 'createdAt', 'createdAtHuman', 'href', 'visit', 'status', 'statusLabel']);

    // Keys, at any depth — never substrings of values, which are free text.
    $keys = customerHomeKeysDeep($props);

    foreach (['requester', 'requesterName', 'user', 'priority', 'sla', 'sla_due_at', 'overdue', 'assignee', 'assignee_id', 'assigned_to', 'company_id', 'time_entries', 'duration', 'durationMinutes', 'internal', 'is_internal'] as $forbidden) {
        expect($keys)->not->toContain($forbidden, "the customer Home must not carry [{$forbidden}]");
    }
});

it('links each request to its own page and each module to its module', function () {
    $user = customerHomeUser();
    $ticket = Ticket::factory()->for($user, 'user')->create(['status' => 'open']);

    $props = customerHomeProps($user);

    expect($props['openRequests'][0]['href'])->toBe(route('tickets.show', $ticket))
        // EPIC-017 WP2: every Helpdesk destination is a React page, so these are client visits; invoices
        // are still Blade, which is why `visit` is per destination.
        ->and($props['openRequests'][0]['visit'])->toBe('inertia')
        ->and($props['support'])->toBe(['href' => route('tickets.index'), 'visit' => 'inertia'])
        ->and($props['newRequest'])->toBe(['href' => route('tickets.create'), 'visit' => 'inertia'])
        ->and($props['invoices']['href'])->toBe(route('billing.client.invoices.index'))
        ->and($props['invoices']['visit'])->toBe('document')
        ->and($props['projects']['href'])->toBe(route('projects.index'))
        ->and($props['projects']['visit'])->toBe('inertia');
});

it('counts only the actor\'s outstanding invoices and active projects', function () {
    $user = customerHomeUser();
    $other = customerHomeUser();

    Invoice::factory()->sent()->create(['client_id' => $user->id]);
    Invoice::factory()->overdue()->create(['client_id' => $user->id]);
    Invoice::factory()->paid()->create(['client_id' => $user->id]);
    Invoice::factory()->sent()->create(['client_id' => $other->id]);

    $mine = Project::factory()->active()->create();
    $mine->members()->attach($user, ['role' => 'member']);
    Project::factory()->active()->create();

    $props = customerHomeProps($user);

    expect($props['invoices']['outstanding'])->toBe(2)
        ->and($props['projects']['active'])->toBe(1);
});

it('shows only the sections whose capability the actor holds', function (array $without, array $nullKeys) {
    $props = customerHomeProps(customerHomeUser($without));

    foreach (['waitingOnYou', 'openRequests', 'invoices', 'projects', 'support', 'newRequest'] as $key) {
        if (in_array($key, $nullKeys, true)) {
            expect($props[$key])->toBeNull("{$key} must be absent without its capability");
        } else {
            expect($props[$key])->not->toBeNull("{$key} should be present");
        }
    }
})->with([
    'no tickets.view' => [[PermissionCatalogue::TICKETS_VIEW], ['waitingOnYou', 'openRequests', 'support']],
    'no tickets.create' => [[PermissionCatalogue::TICKETS_CREATE], ['newRequest']],
    'no billing.view' => [[PermissionCatalogue::BILLING_VIEW], ['invoices']],
    'no projects.view' => [[PermissionCatalogue::PROJECTS_VIEW], ['projects']],
]);

it('limits each request list to the five newest', function () {
    $user = customerHomeUser();

    foreach (range(1, 8) as $number) {
        Ticket::factory()->for($user, 'user')->create([
            'status' => 'open',
            'title' => "Open {$number}",
            'created_at' => now()->subMinutes(10 - $number),
        ]);
    }

    $titles = array_column(customerHomeProps($user)['openRequests'], 'title');

    expect($titles)->toBe(['Open 8', 'Open 7', 'Open 6', 'Open 5', 'Open 4']);
});

it('leaves the operator dashboard to anyone with an operator-surface capability', function (string $role, array $permissions) {
    $user = User::factory()->create();
    $user->assignRole($role);

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    $this->actingAs(User::findOrFail($user->id))->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/index')
            ->where('shell.presentation', 'operational'));
})->with([
    'the operator' => ['operator', []],
    'a customer who also manages projects' => ['user', ['projects.manage']],
    'a customer who also sees every task' => ['user', ['tasks.view_all']],
]);

it('keeps the customer Home free of per-row queries: the whole query list is the same at 1, 5 and 30 of each record', function () {
    $user = customerHomeUser();
    $other = customerHomeUser();
    $agent = User::factory()->create();

    // Every query one warm request issues, in order. The request's fixed overhead (session, user, roles
    // and permissions) is identical at every size, so it needs no allowance: a query that appears per
    // row, against ANY table (an assignee, requester or project lookup included), changes this list.
    $queriesFor = function () use ($user): array {
        // Warm the permission and route caches, then measure.
        test()->actingAs($user)->get(route('dashboard'))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs(User::findOrFail($user->id))->get(route('dashboard'))->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->all();
        DB::disableQueryLog();

        return $queries;
    };

    // Tickets carry an assignee on purpose: a per-row `users` lookup is the likeliest hidden N+1.
    $grow = function (int $n) use ($user, $other, $agent) {
        customerHomeTickets($user, 'pending_user', $n, 'Waiting', ['assignee_id' => $agent->id]);
        customerHomeTickets($user, 'open', $n, 'Open', ['assignee_id' => $agent->id]);
        customerHomeTickets($other, 'open', $n, 'Someone else');
        Invoice::factory()->count($n)->sent()->create(['client_id' => $user->id]);

        for ($i = 0; $i < $n; $i++) {
            Project::factory()->active()->create()->members()->attach($user, ['role' => 'member']);
        }
    };

    // The lists show at most five rows, so 1 -> 5 -> 30 covers a growing list and a capped one.
    $grow(1);
    $one = $queriesFor();
    $grow(4);
    $five = $queriesFor();
    $grow(25);
    $thirty = $queriesFor();

    $detail = fn () => sprintf("queries at 1 of each: %d, at 5: %d, at 30: %d\n%s", count($one), count($five), count($thirty), implode("\n", $thirty));

    // The same statements, not merely the same count: one extra query cannot hide behind a removed one.
    expect($five)->toBe($one, $detail())
        ->and($thirty)->toBe($one, $detail());

    // The data share of that fixed total: the two ticket lists, the invoice count and the project count.
    $data = collect($thirty)->filter(fn (string $sql) => preg_match('/from `(tickets|invoices|projects)`/', $sql))->count();

    expect($data)->toBe(4);
});
