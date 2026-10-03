<?php

use App\Models\CrmCompany;
use App\Models\Organization;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\TaskPolicy;
use App\Queries\TaskRowAbilities;
use App\Shared\Permissions\PermissionCatalogue;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-014 WP3 (§9, §13.3) at the HTTP boundary: `GET /tasks` resolves its view on the server,
 * normalizes every URL parameter (an unknown value is dropped, never a validation redirect), and
 * offers filter options computed only from already-authorized sets. Query semantics live in
 * TaskQueryTest; this file is about what crosses the wire.
 */

function listProps($response): array
{
    return $response->viewData('page')['props'];
}

function listTitles($response): array
{
    return collect(listProps($response)['tasks']['data'])->pluck('title')->sort()->values()->all();
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = makeUser('operator', ['name' => 'Olive Operator']);
    $this->member = makeUser('user', ['name' => 'Max Member']);
    $this->outsider = makeUser('user', ['name' => 'Otto Outsider']);

    $this->visible = makeProject($this->operator, 'Visible Venture');
    $this->hidden = makeProject($this->operator, 'Hidden Harbor');
    $this->visible->members()->attach($this->member->id, ['role' => 'member']);

    $this->mine = makeTask($this->visible->columns[1], ['title' => 'Visible mine', 'assignee_id' => $this->member->id]);
    $this->theirs = makeTask($this->visible->columns[1], ['title' => 'Visible theirs', 'assignee_id' => $this->outsider->id, 'position' => 1]);
    $this->secret = makeTask($this->hidden->columns[1], ['title' => 'Hidden secret', 'assignee_id' => $this->member->id]);
    $this->privateStandalone = Task::factory()->standalone()->create([
        'title' => 'Outsider private', 'created_by' => $this->outsider->id, 'assignee_id' => null, 'status' => 'todo',
    ]);
});

// ── tasks.view_all: catalogue, seeding, policy (§7.1, §7.2) ───────────────────

it('adds tasks.view_all to the catalogue, grants it to operators only, and keeps tasks.view_org inert but present', function () {
    expect(PermissionCatalogue::TASKS_VIEW_ALL)->toBe('tasks.view_all')
        ->and(PermissionCatalogue::all())->toContain('tasks.view_all', 'tasks.view_org')
        ->and(PermissionCatalogue::userDefaults())->not->toContain('tasks.view_all')
        ->and(PermissionCatalogue::userDefaults())->toContain('tasks.view_org');

    expect(Role::findByName('operator')->hasPermissionTo('tasks.view_all'))->toBeTrue()
        ->and(Role::findByName('user')->hasPermissionTo('tasks.view_all'))->toBeFalse()
        ->and($this->operator->can('tasks.view_all'))->toBeTrue()
        ->and($this->member->can('tasks.view_all'))->toBeFalse();
});

it('answers viewAny for every authenticated user and viewAll only for tasks.view_all', function () {
    $policy = new TaskPolicy;
    $bare = User::factory()->create();

    expect($policy->viewAny($bare))->toBeTrue()
        ->and($policy->viewAll($bare))->toBeFalse()
        ->and($policy->viewAll($this->operator))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('viewAll', Task::class))->toBeFalse();

    $this->member->givePermissionTo('tasks.view_all');
    expect(Gate::forUser($this->member->fresh())->allows('viewAll', Task::class))->toBeTrue();
});

// ── View resolution (§9.4) ───────────────────────────────────────────────────

it('clamps every view other than an authorized "all" to mine, without a redirect or 403', function () {
    foreach ([[], ['view' => 'mine'], ['view' => 'org'], ['view' => 'garbage'], ['view' => 'all'], ['view' => ['all']]] as $query) {
        $response = $this->actingAs($this->member)->get(route('tasks.index', $query))->assertOk();

        expect(listProps($response)['view'])->toBe('mine', json_encode($query))
            ->and(listProps($response)['canViewAll'])->toBeFalse()
            ->and(listTitles($response))->toBe(['Visible mine'], json_encode($query));
    }
});

it('opens All Tasks with the permission and closes it when the permission is revoked', function () {
    $this->member->givePermissionTo('tasks.view_all');

    $response = $this->actingAs($this->member->fresh())->get(route('tasks.index', ['view' => 'all']))->assertOk();
    expect(listProps($response)['view'])->toBe('all')
        ->and(listProps($response)['canViewAll'])->toBeTrue()
        // Every visible-project row, but not the hidden project and not someone's private task.
        ->and(listTitles($response))->toBe(['Visible mine', 'Visible theirs']);

    $this->member->revokePermissionTo('tasks.view_all');
    $response = $this->actingAs($this->member->fresh())->get(route('tasks.index', ['view' => 'all']))->assertOk();
    expect(listProps($response)['view'])->toBe('mine')
        ->and(listTitles($response))->toBe(['Visible mine']);
});

it('never shows an operator another user\'s private standalone task in All Tasks', function () {
    $response = $this->actingAs($this->operator)->get(route('tasks.index', ['view' => 'all']))->assertOk();

    expect(listTitles($response))->toBe(['Hidden secret', 'Visible mine', 'Visible theirs']);
});

// ── Props contract (§13.3) ───────────────────────────────────────────────────

it('sends view, filters, filterOptions, sort, canViewAll and createOptions, and no canViewOrg', function () {
    $props = listProps($this->actingAs($this->member)->get(route('tasks.index'))->assertOk());

    expect($props)->toHaveKeys(['tasks', 'view', 'filters', 'filterOptions', 'sort', 'canViewAll', 'createOptions'])
        ->not->toHaveKey('canViewOrg');

    expect($props['filters'])->toBe([
        'completion' => 'open', 'priority' => [], 'due' => null, 'kind' => null, 'project' => null,
        'milestone' => null, 'assignee' => null, 'organization' => null, 'q' => '',
    ])->and($props['sort'])->toBe(['by' => 'due', 'dir' => 'asc']);

    expect(array_keys($props['filterOptions']))->toBe([
        'completion', 'priorities', 'due', 'kinds', 'sorts', 'projects', 'milestones', 'assignees', 'organizations',
    ])
        ->and(collect($props['filterOptions']['completion'])->pluck('value')->all())->toBe(['open', 'done', 'any'])
        ->and(collect($props['filterOptions']['priorities'])->pluck('value')->all())->toBe(['low', 'medium', 'high', 'critical'])
        ->and(collect($props['filterOptions']['due'])->pluck('value')->all())->toBe(['overdue', 'today', 'next7', 'none'])
        ->and(collect($props['filterOptions']['kinds'])->all())->toBe([
            ['value' => 'project', 'label' => 'Project'], ['value' => 'standalone', 'label' => 'Standalone'],
        ])
        ->and(collect($props['filterOptions']['sorts'])->pluck('value')->all())->toBe(['due', 'priority', 'title', 'updated', 'created']);
});

it('shapes each row with its surfaced kind and batch-computed abilities, and nothing more', function () {
    Task::factory()->standalone()->create(['title' => 'My loose end', 'created_by' => $this->member->id, 'assignee_id' => null]);

    $rows = collect(listProps($this->actingAs($this->member)->get(route('tasks.index')))['tasks']['data'])->keyBy('title');

    expect(array_keys($rows['Visible mine']))->toBe([
        'id', 'title', 'kind', 'projectId', 'priority', 'status', 'dueDate', 'overdue', 'assignee', 'context', 'url', 'abilities',
    ])
        ->and($rows['Visible mine']['kind'])->toBe('board')
        ->and($rows['Visible mine']['context'])->toBe(['kind' => 'project', 'label' => 'Visible Venture', 'url' => route('projects.board', $this->visible)])
        ->and($rows['Visible mine']['url'])->toBe(route('projects.tasks.show', [$this->visible, $this->mine]))
        ->and($rows['Visible mine']['projectId'])->toBe($this->visible->id)
        // Q1: the member-assignee may complete/reopen but not assign.
        ->and($rows['Visible mine']['abilities'])->toBe(['complete' => true, 'reopen' => true, 'assign' => false]);

    // WP5: tasks.show exists, so a standalone row opens its own detail page (its context stays unlinked).
    expect($rows['My loose end']['kind'])->toBe('standalone')
        ->and($rows['My loose end']['projectId'])->toBeNull()
        ->and($rows['My loose end']['context'])->toBe(['kind' => 'standalone', 'label' => 'Standalone', 'url' => null])
        ->and($rows['My loose end']['url'])->toBe(route('tasks.show', $rows['My loose end']['id']))
        ->and($rows['My loose end']['abilities'])->toBe(['complete' => true, 'reopen' => true, 'assign' => true]);
});

it('computes row abilities identical to TaskPolicy for every actor and row', function () {
    $manager = makeUser('user', ['name' => 'Mona Manager']);
    $manager->givePermissionTo(['projects.manage', 'tasks.view_all']);
    $this->visible->members()->attach($manager->id, ['role' => 'manager']);
    $roleManagerOnly = makeUser('user', ['name' => 'Rolf RoleOnly']);   // manager pivot, no projects.manage
    $roleManagerOnly->givePermissionTo('tasks.view_all');
    $this->visible->members()->attach($roleManagerOnly->id, ['role' => 'manager']);
    $adminOnly = makeUser();
    $adminOnly->givePermissionTo(['projects.admin', 'tasks.view_all']);
    $viewAllMember = makeUser('user', ['name' => 'Vera ViewAll']);
    $viewAllMember->givePermissionTo('tasks.view_all');
    $this->visible->members()->attach($viewAllMember->id, ['role' => 'member']);
    makeTask($this->visible->columns[4], ['title' => 'Done for vera', 'assignee_id' => $viewAllMember->id, 'position' => 0]);
    Task::factory()->standalone()->create(['title' => 'Vera standalone', 'created_by' => $this->operator->id, 'assignee_id' => $viewAllMember->id]);

    foreach ([$this->operator, $manager, $roleManagerOnly, $adminOnly, $viewAllMember, $this->member] as $actor) {
        foreach (['mine', 'all'] as $view) {
            $rows = listProps($this->actingAs($actor->fresh())->get(route('tasks.index', ['view' => $view, 'completion' => 'any'])))['tasks']['data'];

            foreach ($rows as $row) {
                $task = Task::findOrFail($row['id']);
                $expected = collect(['complete', 'reopen', 'assign'])
                    ->mapWithKeys(fn (string $ability) => [$ability => Gate::forUser($actor->fresh())->allows($ability, $task)])->all();

                expect($row['abilities'])->toBe($expected, "{$actor->name} {$view} {$row['title']}");
            }
        }
    }
});

it('agrees with TaskPolicy on every task, listed or not, so the projection holds beyond the list', function () {
    // Off the list the shortcut "a visible board row implies membership or admin" no longer
    // holds: a departed assignee, a ticket-kind or a dual-linked row must still answer as the
    // policy does.
    $departed = makeUser('user', ['name' => 'Dora Departed']);
    makeTask($this->visible->columns[1], ['title' => 'Departed holds', 'assignee_id' => $departed->id, 'position' => 7]);
    $ticket = Ticket::factory()->create(['user_id' => $this->member->id]);
    Task::factory()->standalone()->create(['title' => 'Ticket kind', 'ticket_id' => $ticket->id, 'assignee_id' => $this->member->id, 'created_by' => $this->member->id]);
    makeTask($this->visible->columns[1], ['title' => 'Dual', 'ticket_id' => $ticket->id, 'assignee_id' => $this->member->id, 'position' => 8]);
    $tasks = Task::all();

    foreach ([$this->operator, $this->member, $this->outsider, $departed] as $actor) {
        $abilities = TaskRowAbilities::for($actor, $tasks);

        foreach ($tasks as $task) {
            $expected = collect(['complete', 'reopen', 'assign'])
                ->mapWithKeys(fn (string $ability) => [$ability => Gate::forUser($actor)->allows($ability, $task)])->all();

            expect($abilities->of($task))->toBe($expected, "{$actor->name}: {$task->title}");
            if ($task->project_id !== null) {
                expect($abilities->canViewProject($task->project_id))->toBe(Gate::forUser($actor)->allows('view', $task->project));
            }
        }
    }
});

it('leaks nothing unauthorized anywhere in the page props', function () {
    $ticket = Ticket::factory()->create(['user_id' => $this->member->id, 'ticket_number' => 'TKT-4242']);
    Task::factory()->standalone()->create(['title' => 'Ticket bound', 'ticket_id' => $ticket->id, 'assignee_id' => $this->member->id]);
    makeTask($this->visible->columns[1], ['title' => 'Dual bound', 'ticket_id' => $ticket->id, 'assignee_id' => $this->member->id, 'position' => 5]);
    Task::factory()->standalone()->create(['title' => 'With secret notes', 'description' => 'Secret notes', 'assignee_id' => $this->member->id, 'created_by' => $this->member->id]);
    $this->member->givePermissionTo('tasks.view_all');

    foreach (['mine', 'all'] as $view) {
        $props = listProps($this->actingAs($this->member->fresh())->get(route('tasks.index', ['view' => $view, 'completion' => 'any'])));
        $json = json_encode(array_diff_key($props, array_flip(['auth', 'navigation', 'flash', 'errors', 'shell', 'ziggy', 'timer'])));

        foreach (['Hidden Harbor', 'Hidden secret', 'Outsider private', 'Ticket bound', 'Dual bound', 'TKT-4242', 'Secret notes', 'email', 'created_by', 'project_id', 'ticket_id', 'canViewOrg'] as $forbidden) {
            // One needle per assertion, the diagnostic as the message: the earlier
            // `->not->toContain($forbidden, "{$view}: {$forbidden}")` passed unless BOTH strings were
            // present, and the second never is, so no leak could fail it (EPIC-015 review F4).
            $this->assertStringNotContainsString($forbidden, $json, "{$view}: the page props leak {$forbidden}");
        }
    }
});

// ── Normalization (§9.5, §9.6) ───────────────────────────────────────────────

it('drops unknown, malformed or out-of-view filter values and echoes the canonical state', function () {
    $response = $this->actingAs($this->member)->get('/tasks?'.http_build_query([
        'completion' => 'bogus',
        'priority' => ['critical', 'nope', 'critical', 'low'],
        'due' => 'yesterday',
        'kind' => 'ticket',
        'project' => 'abc',
        'milestone' => '5',
        'assignee' => $this->member->id,   // My Tasks offers no assignee filter
        'organization' => '1 OR 1=1',
        'q' => ['array'],
        'sort' => 'status',
        'dir' => 'sideways',
    ]))->assertOk()->assertSessionHasNoErrors();

    expect(listProps($response)['filters'])->toBe([
        'completion' => 'open', 'priority' => ['low', 'critical'], 'due' => null, 'kind' => null, 'project' => null,
        'milestone' => null, 'assignee' => null, 'organization' => null, 'q' => '',
    ])->and(listProps($response)['sort'])->toBe(['by' => 'due', 'dir' => 'asc']);
});

it('accepts a single priority string, trims and clamps the search, and defaults direction per sort', function () {
    $response = $this->actingAs($this->member)->get(route('tasks.index', [
        'priority' => 'high', 'q' => '  '.str_repeat('x', 150).'  ', 'sort' => 'updated',
    ]))->assertOk();

    expect(listProps($response)['filters']['priority'])->toBe(['high'])
        ->and(listProps($response)['filters']['q'])->toBe(str_repeat('x', 100))
        ->and(listProps($response)['sort'])->toBe(['by' => 'updated', 'dir' => 'desc']);

    $response = $this->actingAs($this->member)->get(route('tasks.index', ['sort' => 'priority']))->assertOk();
    expect(listProps($response)['sort'])->toBe(['by' => 'priority', 'dir' => 'desc']);
    $response = $this->actingAs($this->member)->get(route('tasks.index', ['sort' => 'title', 'dir' => 'desc']))->assertOk();
    expect(listProps($response)['sort'])->toBe(['by' => 'title', 'dir' => 'desc']);
});

it('keeps the filter state in pagination links', function () {
    foreach (range(1, 31) as $i) {
        makeTask($this->visible->columns[1], ['title' => "Page row {$i}", 'assignee_id' => $this->member->id, 'priority' => 'high', 'position' => 10 + $i]);
    }

    $props = listProps($this->actingAs($this->member)->get(route('tasks.index', ['priority' => ['high'], 'q' => 'Page', 'sort' => 'title'])));

    expect($props['tasks']['total'])->toBe(31)
        ->and($props['tasks']['next_page_url'])->toContain('q=Page')->toContain('sort=title')->toContain('priority');
});

it('builds pagination links from the normalized state, keeping an active id filter and shedding junk', function () {
    foreach (range(1, 31) as $i) {
        makeTask($this->visible->columns[1], ['title' => "Linked {$i}", 'assignee_id' => $this->member->id, 'position' => 10 + $i]);
    }
    $this->member->givePermissionTo('tasks.view_all');
    $member = $this->member->fresh();

    $props = listProps($this->actingAs($member)->get('/tasks?'.http_build_query([
        'view' => 'all', 'project' => $this->visible->id, 'organization' => 'junk', 'foo' => 'bar', 'sort' => 'bogus',
    ])));
    $next = $props['tasks']['next_page_url'];

    expect($next)->toContain('view=all')->toContain('project='.$this->visible->id)
        ->not->toContain('junk')->not->toContain('foo=')->not->toContain('bogus');

    // An unseen project is preserved across pages too, rather than reverting to the whole view.
    $props = listProps($this->actingAs($member)->get(route('tasks.index', ['view' => 'all', 'project' => $this->hidden->id])));
    expect($props['tasks']['total'])->toBe(0)
        ->and($props['tasks']['first_page_url'])->toContain('project='.$this->hidden->id);
});

// ── Forged ids never widen or leak (INV-16, INV-17) ──────────────────────────

it('keeps a project filter for a project the actor cannot see and returns zero rows, revealing no metadata', function () {
    $this->member->givePermissionTo('tasks.view_all');
    $milestone = $this->hidden->milestones()->create(['name' => 'Hidden milestone', 'due_date' => today()]);

    foreach (['mine', 'all'] as $view) {
        $response = $this->actingAs($this->member->fresh())->get(route('tasks.index', [
            'view' => $view, 'completion' => 'any', 'project' => $this->hidden->id, 'milestone' => $milestone->id,
        ]))->assertOk();
        $props = listProps($response);

        // The explicit filter stays active and narrows to nothing; it never widens to the view.
        expect($props['filters']['project'])->toBe($this->hidden->id)
            ->and($props['filters']['milestone'])->toBeNull()
            ->and($props['filterOptions']['milestones'])->toBe([])
            ->and($props['tasks']['total'])->toBe(0)
            ->and(json_encode($props['tasks']).json_encode($props['filterOptions']))
            ->not->toContain('Hidden Harbor')->not->toContain('Hidden milestone')->not->toContain('Hidden secret');
    }
});

it('keeps a visible project with no rows in the view as an active filter with zero rows', function () {
    // Visible to the member, but holds nothing assigned to them: not offered, yet still applied.
    $empty = makeProject($this->operator, 'Empty But Visible');
    $empty->members()->attach($this->member->id, ['role' => 'member']);
    makeTask($empty->columns[1], ['title' => 'Someone elses', 'assignee_id' => $this->outsider->id]);

    $props = listProps($this->actingAs($this->member)->get(route('tasks.index', ['project' => $empty->id])));

    expect(collect($props['filterOptions']['projects'])->pluck('id')->all())->not->toContain($empty->id)
        ->and($props['filters']['project'])->toBe($empty->id)
        ->and($props['tasks']['total'])->toBe(0);
});

it('keeps a nonexistent project id as an active filter with zero rows, identical to an inaccessible one', function () {
    $missing = listProps($this->actingAs($this->member)->get(route('tasks.index', ['project' => 987654])));
    $hidden = listProps($this->actingAs($this->member)->get(route('tasks.index', ['project' => $this->hidden->id])));

    expect($missing['tasks']['total'])->toBe(0)->and($hidden['tasks']['total'])->toBe(0)
        ->and($missing['filters']['project'])->toBe(987654)->and($hidden['filters']['project'])->toBe($this->hidden->id)
        ->and($missing['filterOptions'])->toBe($hidden['filterOptions']);
});

it('still drops a malformed id of every kind, leaving that filter off', function () {
    $this->member->givePermissionTo('tasks.view_all');
    $member = $this->member->fresh();

    foreach (['0', '-4', '4.5', '4 OR 1=1', 'abc', '', '99999999999999999999999', ['4'], [['4']]] as $bad) {
        $props = listProps($this->actingAs($member)->get('/tasks?'.http_build_query([
            'view' => 'all', 'project' => $bad, 'assignee' => $bad, 'organization' => $bad,
        ])));

        expect($props['filters']['project'])->toBeNull(json_encode($bad))
            ->and($props['filters']['assignee'])->toBeNull(json_encode($bad))
            ->and($props['filters']['organization'])->toBeNull(json_encode($bad))
            ->and($props['tasks']['total'])->toBe(2, json_encode($bad));
    }
});

it('offers milestones only for exactly one selected project and drops a milestone of another project', function () {
    $this->member->givePermissionTo('tasks.view_all');
    $own = $this->visible->milestones()->create(['name' => 'Own milestone', 'due_date' => today()]);
    $foreign = $this->hidden->milestones()->create(['name' => 'Foreign milestone', 'due_date' => today()]);
    $this->mine->update(['milestone_id' => $own->id]);
    $this->secret->update(['milestone_id' => $foreign->id]);
    $member = $this->member->fresh();

    $props = listProps($this->actingAs($member)->get(route('tasks.index', ['view' => 'all'])));
    expect($props['filterOptions']['milestones'])->toBe([]);

    $props = listProps($this->actingAs($member)->get(route('tasks.index', ['view' => 'all', 'project' => $this->visible->id, 'milestone' => $foreign->id])));
    expect($props['filters']['project'])->toBe($this->visible->id)
        ->and($props['filters']['milestone'])->toBeNull()
        ->and($props['filterOptions']['milestones'])->toBe([['id' => $own->id, 'name' => 'Own milestone']]);

    $response = $this->actingAs($member)->get(route('tasks.index', ['view' => 'all', 'project' => $this->visible->id, 'milestone' => $own->id]));
    expect(listProps($response)['filters']['milestone'])->toBe($own->id)
        ->and(listTitles($response))->toBe(['Visible mine']);
});

it('offers the milestones of a visible project that has no rows in the view, and still ignores a foreign one', function () {
    $empty = makeProject($this->operator, 'Empty But Visible');
    $empty->members()->attach($this->member->id, ['role' => 'member']);
    $own = $empty->milestones()->create(['name' => 'Empty own', 'due_date' => today()]);
    $foreign = $this->visible->milestones()->create(['name' => 'Other project', 'due_date' => today()]);

    $props = listProps($this->actingAs($this->member)->get(route('tasks.index', ['project' => $empty->id, 'milestone' => $own->id])));
    expect($props['filters']['milestone'])->toBe($own->id)
        ->and($props['filterOptions']['milestones'])->toBe([['id' => $own->id, 'name' => 'Empty own']]);

    $props = listProps($this->actingAs($this->member)->get(route('tasks.index', ['project' => $empty->id, 'milestone' => $foreign->id])));
    expect($props['filters']['milestone'])->toBeNull();
});

it('offers an assignee filter only in All Tasks, drawn from assignees of visible rows, names only', function () {
    $hiddenAssignee = makeUser('user', ['name' => 'Hilda HiddenOnly']);
    makeTask($this->hidden->columns[1], ['title' => 'Hidden other', 'assignee_id' => $hiddenAssignee->id, 'position' => 1]);
    $this->member->givePermissionTo('tasks.view_all');
    $member = $this->member->fresh();

    $mine = listProps($this->actingAs($member)->get(route('tasks.index')));
    expect($mine['filterOptions']['assignees'])->toBe([]);

    $all = listProps($this->actingAs($member)->get(route('tasks.index', ['view' => 'all'])));
    expect($all['filterOptions']['assignees'])->toBe([
        ['id' => $this->member->id, 'name' => 'Max Member'],
        ['id' => $this->outsider->id, 'name' => 'Otto Outsider'],
    ]);

    // An assignee who exists only behind the hidden project is not offered a label, but the id is
    // still applied: zero rows, and the name is nowhere in the response.
    $forged = listProps($this->actingAs($member)->get(route('tasks.index', ['view' => 'all', 'assignee' => $hiddenAssignee->id])));
    expect($forged['filters']['assignee'])->toBe($hiddenAssignee->id)
        ->and($forged['tasks']['total'])->toBe(0)
        ->and(json_encode($forged))->not->toContain('Hilda')->not->toContain('Hidden other');

    // My Tasks has no assignee filter, so the same id is simply off there.
    $mineForged = listProps($this->actingAs($member)->get(route('tasks.index', ['assignee' => $this->outsider->id])));
    expect($mineForged['filters']['assignee'])->toBeNull();

    $response = $this->actingAs($member)->get(route('tasks.index', ['view' => 'all', 'assignee' => $this->outsider->id]));
    expect(listProps($response)['filters']['assignee'])->toBe($this->outsider->id)
        ->and(listTitles($response))->toBe(['Visible theirs']);

    $none = listProps($this->actingAs($member)->get(route('tasks.index', ['view' => 'all', 'assignee' => 'none'])));
    expect($none['filters']['assignee'])->toBe('none');
});

it('offers projects that have rows in the current view, never an invisible one', function () {
    makeProject($this->operator, 'Empty But Visible')->members()->attach($this->member->id, ['role' => 'member']);
    $this->member->givePermissionTo('tasks.view_all');

    $props = listProps($this->actingAs($this->member->fresh())->get(route('tasks.index', ['view' => 'all'])));

    expect($props['filterOptions']['projects'])->toBe([['id' => $this->visible->id, 'name' => 'Visible Venture']]);

    $operatorProps = listProps($this->actingAs($this->operator)->get(route('tasks.index', ['view' => 'all'])));
    expect(collect($operatorProps['filterOptions']['projects'])->pluck('name')->all())->toBe(['Hidden Harbor', 'Visible Venture']);
});

it('offers organizations only through visible projects and the actor\'s own CRM scope, and filters by any well-formed id', function () {
    $org = Organization::factory()->create(['owner_id' => $this->operator->id]);
    $org->members()->attach($this->member, ['role' => 'member']);
    $visibleCompany = CrmCompany::factory()->create(['created_by' => $this->operator->id, 'organization_id' => $org->id, 'name' => 'Seen Co']);
    $hiddenCompany = CrmCompany::factory()->create(['created_by' => $this->operator->id, 'organization_id' => $org->id, 'name' => 'Behind Hidden Co']);
    $otherTenant = CrmCompany::factory()->create(['created_by' => $this->operator->id, 'name' => 'Other Tenant Co']);
    $this->visible->companies()->attach([$visibleCompany->id, $otherTenant->id]);
    $this->hidden->companies()->attach($hiddenCompany);
    Task::factory()->standalone()->create(['title' => 'My standalone', 'created_by' => $this->member->id, 'assignee_id' => $this->member->id]);

    $props = listProps($this->actingAs($this->member)->get(route('tasks.index')));
    expect($props['filterOptions']['organizations'])->toBe([['id' => $visibleCompany->id, 'name' => 'Seen Co']]);

    // Standalone rows never match an organization; forged companies are dropped.
    $response = $this->actingAs($this->member)->get(route('tasks.index', ['organization' => $visibleCompany->id]));
    expect(listTitles($response))->toBe(['Visible mine'])
        ->and(listProps($response)['filters']['organization'])->toBe($visibleCompany->id);

    // Companies outside the offered labels (the CRM scope hides one; the other sits behind a
    // project the member cannot see) stay applied and match nothing, and are never named.
    foreach ([$hiddenCompany, $otherTenant] as $forged) {
        $response = $this->actingAs($this->member)->get(route('tasks.index', ['organization' => $forged->id]));
        expect(listProps($response)['filters']['organization'])->toBe($forged->id)
            ->and(listProps($response)['tasks']['total'])->toBe(0)
            ->and(json_encode(listProps($response)))->not->toContain($forged->name);
    }

    $operatorProps = listProps($this->actingAs($this->operator)->get(route('tasks.index', ['view' => 'all'])));
    expect(collect($operatorProps['filterOptions']['organizations'])->pluck('name')->all())
        ->toBe(['Behind Hidden Co', 'Other Tenant Co', 'Seen Co']);
});

it('finds nothing when the search text matches only rows the actor cannot see', function () {
    $response = $this->actingAs($this->member)->get(route('tasks.index', ['q' => 'secret', 'completion' => 'any']));

    expect(listProps($response)['tasks']['total'])->toBe(0);

    $response = $this->actingAs($this->member)->get(route('tasks.index', ['q' => 'Outsider private', 'completion' => 'any']));
    expect(listProps($response)['tasks']['total'])->toBe(0);
});
