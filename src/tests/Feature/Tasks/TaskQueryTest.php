<?php

use App\Models\CrmCompany;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Queries\TaskListState;
use App\Queries\TaskQuery;
use Illuminate\Support\Facades\Gate;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-014 WP3 (§9): the Tasks-workspace query, exercised below HTTP. Composition order is the
 * contract (§9.1): the maximum authorized surfaced set first, then the view, then filters, search,
 * sort and pagination, each only ever AND-ed onto what came before.
 *
 * The fixture is one world shared by every test, so each assertion names exact rows:
 *
 *   P1 (member, manager, viewAllMember are members)   P2 (no member but its operator creator)
 *     b1 assigned member                                 b4 assigned member (not a P2 member)
 *     b2 assigned departed (left P1)
 *     b3 unassigned, created by member
 *     b5 in Done, assigned member
 *   standalone
 *     s1 member's, unassigned (F1)        s4 stranger's, unassigned
 *     s2 member's, assigned member        s5 member's, assigned to the operator (legacy)
 *     s3 operator's, assigned member
 *   t1 ticket task assigned to member, on member's own ticket
 *   d1 malformed: P1 + ticket, assigned member
 */

function taskQueryWorld(): array
{
    $operator = makeUser('operator', ['name' => 'Olive Operator']);
    $manager = projectActorless('Mona Manager');
    $member = projectActorless('Max Member');
    $departed = projectActorless('Dora Departed');
    $stranger = projectActorless('Stan Stranger');
    $viewAllMember = projectActorless('Vera ViewAll');
    $viewAllMember->givePermissionTo('tasks.view_all');

    $p1 = makeProject($operator, 'Apollo');
    $p2 = makeProject($operator, 'Borealis');
    $p1->members()->attach($manager->id, ['role' => 'manager']);
    $manager->givePermissionTo('projects.manage');
    $p1->members()->attach($member->id, ['role' => 'member']);
    $p1->members()->attach($viewAllMember->id, ['role' => 'member']);

    $open = $p1->columns[1];
    $done = $p1->columns->firstWhere('is_done_column', true);

    $t = [];
    $t['b1'] = makeTask($open, ['title' => 'b1 alpha', 'assignee_id' => $member->id, 'priority' => 'high', 'due_date' => today()->addDays(2)]);
    $t['b2'] = makeTask($open, ['title' => 'b2 bravo', 'assignee_id' => $departed->id, 'priority' => 'low', 'position' => 1]);
    $t['b3'] = makeTask($open, ['title' => 'b3 charlie', 'created_by' => $member->id, 'priority' => 'critical', 'position' => 2]);
    $t['b4'] = makeTask($p2->columns[1], ['title' => 'b4 delta', 'assignee_id' => $member->id]);
    $t['b5'] = makeTask($done, ['title' => 'b5 echo', 'assignee_id' => $member->id, 'due_date' => today()->subDays(3)]);

    $t['s1'] = standaloneTask($member, null, ['title' => 's1 foxtrot', 'priority' => 'medium']);
    $t['s2'] = standaloneTask($member, $member, ['title' => 's2 golf', 'status' => 'done', 'due_date' => today()]);
    $t['s3'] = standaloneTask($operator, $member, ['title' => 's3 hotel', 'priority' => 'critical', 'due_date' => today()->subDay()]);
    $t['s4'] = standaloneTask($stranger, null, ['title' => 's4 india']);
    $t['s5'] = standaloneTask($member, $operator, ['title' => 's5 juliet']);

    $ownTicket = Ticket::factory()->create(['user_id' => $member->id]);
    $t['t1'] = standaloneTask($member, $member, ['title' => 't1 kilo', 'ticket_id' => $ownTicket->id]);
    $t['d1'] = makeTask($open, ['title' => 'd1 lima', 'assignee_id' => $member->id, 'ticket_id' => Ticket::factory()->create()->id, 'position' => 3]);

    return [
        'actors' => compact('operator', 'manager', 'member', 'departed', 'stranger', 'viewAllMember'),
        'projects' => compact('p1', 'p2'),
        'tasks' => $t,
    ];
}

function projectActorless(string $name): User
{
    return makeUser('user', ['name' => $name]);
}

function standaloneTask(User $creator, ?User $assignee, array $attributes = []): Task
{
    return Task::factory()->standalone()->create([
        'created_by' => $creator->id,
        'assignee_id' => $assignee?->id,
        'priority' => 'medium',
        'due_date' => null,
        'status' => 'todo',
        ...$attributes,
    ]);
}

/** @return array<int, string> the fixture labels of the given task ids, sorted */
function labelsOf(array $world, iterable $ids): array
{
    $byId = collect($world['tasks'])->mapWithKeys(fn (Task $task, string $label) => [$task->id => $label]);

    return collect($ids)->map(fn (int $id) => $byId[$id] ?? "#{$id}")->sort()->values()->all();
}

function idsOf($builder): array
{
    return $builder->pluck('tasks.id')->all();
}

/** Every-completion state, so a set test is not narrowed by the open default (P2). */
function anyState(string $view, array $overrides = []): TaskListState
{
    return new TaskListState(...['view' => $view, 'completion' => 'any', ...$overrides]);
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->world = taskQueryWorld();
    ['actors' => $this->actors, 'tasks' => $this->t] = $this->world;
});

// ── Step 1: the maximum authorized surfaced set (§9.1.1) ─────────────────────

it('equals the TaskPolicy view-able surfaced rows for every actor', function () {
    foreach ($this->actors as $name => $actor) {
        $expected = Task::all()
            ->filter(fn (Task $task) => $task->ticket_id === null && Gate::forUser($actor)->allows('view', $task))
            ->pluck('id');

        expect(labelsOf($this->world, idsOf(TaskQuery::authorizedFor($actor))))
            ->toBe(labelsOf($this->world, $expected), "authorized set for {$name}");
    }
});

it('admits board rows only through project visibility and standalone rows only through creator or assignee', function () {
    expect(labelsOf($this->world, idsOf(TaskQuery::authorizedFor($this->actors['member']))))
        ->toBe(['b1', 'b2', 'b3', 'b5', 's1', 's2', 's3', 's5']);
    expect(labelsOf($this->world, idsOf(TaskQuery::authorizedFor($this->actors['operator']))))
        ->toBe(['b1', 'b2', 'b3', 'b4', 'b5', 's3', 's5']);
    // A stored assignment grants nothing once the project is out of sight (INV-7, §7.2).
    expect(labelsOf($this->world, idsOf(TaskQuery::authorizedFor($this->actors['departed']))))->toBe([]);
    expect(labelsOf($this->world, idsOf(TaskQuery::authorizedFor($this->actors['stranger']))))->toBe(['s4']);
});

it('never surfaces a ticket-kind or dual-linked row, whoever asks (Q6, INV-13)', function () {
    foreach ($this->actors as $name => $actor) {
        foreach ([TaskQuery::VIEW_MINE, TaskQuery::VIEW_ALL] as $view) {
            $labels = labelsOf($this->world, idsOf((new TaskQuery($actor, $view))->results(anyState($view))));
            // Each needle is checked on its own and the diagnostic is the assertion message. The
            // earlier `->not->toContain('t1', "{$name} {$view}")` passed unless BOTH needles were
            // present, and the second never is, so it could not fail (EPIC-015 review F4).
            $leaked = array_values(array_intersect($labels, ['t1', 'd1']));
            $this->assertSame([], $leaked, "{$name} {$view} must not surface a ticket-kind or dual-linked row");
        }
    }

    // The rows still exist, untouched.
    expect($this->t['t1']->fresh()->ticket_id)->not->toBeNull()
        ->and($this->t['d1']->fresh()->only(['project_id', 'ticket_id']))->each->not->toBeNull();
});

// ── Step 2: My Tasks (Q4) ────────────────────────────────────────────────────

it('lists in My Tasks what is assigned to me plus the unassigned standalone tasks I created', function () {
    $mine = (new TaskQuery($this->actors['member'], TaskQuery::VIEW_MINE))->inView();

    // b3: created a board task, not assigned → nothing. s5: created, handed elsewhere → nothing.
    // b4: assigned, but P2 is not visible → nothing. t1/d1: never surfaced.
    expect(labelsOf($this->world, idsOf($mine)))->toBe(['b1', 'b5', 's1', 's2', 's3']);
});

it('keeps an unassigned standalone task discoverable to its creator and to nobody else (F1)', function () {
    foreach ($this->actors as $name => $actor) {
        foreach ([TaskQuery::VIEW_MINE, TaskQuery::VIEW_ALL] as $view) {
            $labels = labelsOf($this->world, idsOf((new TaskQuery($actor, $view))->inView()));
            expect(in_array('s1', $labels, true))->toBe($name === 'member', "{$name} {$view}");
        }
    }
});

it('gives a departed assignee neither My Tasks nor All Tasks rows for the project', function () {
    $departed = $this->actors['departed'];
    $departed->givePermissionTo('tasks.view_all');

    foreach ([TaskQuery::VIEW_MINE, TaskQuery::VIEW_ALL] as $view) {
        expect(idsOf((new TaskQuery($departed, $view))->inView()))->toBe([]);
    }
});

// ── Step 2: All Tasks (Q3) ───────────────────────────────────────────────────

it('makes All Tasks exactly the authorized set, a superset of My Tasks', function () {
    foreach ($this->actors as $name => $actor) {
        $all = idsOf((new TaskQuery($actor, TaskQuery::VIEW_ALL))->inView());
        $mine = idsOf((new TaskQuery($actor, TaskQuery::VIEW_MINE))->inView());

        expect(labelsOf($this->world, $all))->toBe(labelsOf($this->world, idsOf(TaskQuery::authorizedFor($actor))), $name)
            ->and(array_diff($mine, $all))->toBe([], "All ⊇ Mine for {$name}");
    }
});

it('does not show an operator other users\' standalone tasks in All Tasks', function () {
    $labels = labelsOf($this->world, idsOf((new TaskQuery($this->actors['operator'], TaskQuery::VIEW_ALL))->inView()));

    expect($labels)->toBe(['b1', 'b2', 'b3', 'b4', 'b5', 's3', 's5']);
});

it('resolves the view on the server: all only with tasks.view_all, anything else is mine', function () {
    $operator = $this->actors['operator'];
    $member = $this->actors['member'];

    expect(TaskQuery::resolveView($operator, 'all'))->toBe('all')
        ->and(TaskQuery::resolveView($viewAll = $this->actors['viewAllMember'], 'all'))->toBe('all')
        ->and(TaskQuery::resolveView($member, 'all'))->toBe('mine');

    foreach ([null, '', 'mine', 'org', 'ALL', 'garbage', ['all']] as $raw) {
        expect(TaskQuery::resolveView($operator, $raw))->toBe('mine');
    }

    expect(fn () => new TaskQuery($member, 'org'))->toThrow(InvalidArgumentException::class);
});

// ── Filters never widen (INV-16) ─────────────────────────────────────────────

it('never widens the view, whatever the filter, search or sort (property)', function () {
    $p1 = $this->world['projects']['p1'];
    $p2 = $this->world['projects']['p2'];
    $milestone = $p1->milestones()->create(['name' => 'M1', 'due_date' => today()]);
    $foreignMilestone = $p2->milestones()->create(['name' => 'M2', 'due_date' => today()]);
    $this->t['b1']->update(['milestone_id' => $milestone->id]);
    $this->t['b4']->update(['milestone_id' => $foreignMilestone->id]);
    $company = CrmCompany::factory()->create(['created_by' => $this->actors['operator']->id]);
    $p2->companies()->attach($company);

    $variants = [
        ['completion' => 'open'], ['completion' => 'done'], ['completion' => 'any'],
        ['priorities' => ['critical']], ['priorities' => ['low', 'high']],
        ['due' => 'overdue'], ['due' => 'today'], ['due' => 'next7'], ['due' => 'none'],
        ['kind' => 'project'], ['kind' => 'standalone'],
        ['project' => $p1->id], ['project' => $p2->id], ['project' => 999999],
        ['project' => $p1->id, 'milestone' => $milestone->id],
        ['project' => $p1->id, 'milestone' => $foreignMilestone->id],
        ['project' => $p2->id, 'milestone' => $foreignMilestone->id],
        ['assignee' => $this->actors['member']->id], ['assignee' => $this->actors['departed']->id], ['assignee' => 'none'],
        ['organization' => $company->id], ['organization' => 999999],
        ['search' => 'a'], ['search' => '%'], ['search' => '_'], ['search' => 'delta'], ['search' => 'kilo'],
        ['sort' => 'priority', 'direction' => 'desc'], ['sort' => 'title'], ['sort' => 'updated'], ['sort' => 'created'],
        ['priorities' => ['critical'], 'kind' => 'project', 'search' => 'charlie', 'sort' => 'title'],
    ];

    foreach ($this->actors as $name => $actor) {
        foreach ([TaskQuery::VIEW_MINE, TaskQuery::VIEW_ALL] as $view) {
            $query = new TaskQuery($actor, $view);
            $unfiltered = idsOf($query->inView());

            foreach ($variants as $i => $variant) {
                $filtered = idsOf($query->results(new TaskListState(...['view' => $view, 'completion' => 'any', ...$variant])));

                expect(array_diff($filtered, $unfiltered))->toBe([], "{$name} {$view} variant {$i}");
            }
        }
    }
});

it('returns nothing for a project, milestone or organization outside the authorized set', function () {
    $member = $this->actors['member'];
    $p2 = $this->world['projects']['p2'];
    $foreign = $p2->milestones()->create(['name' => 'Hidden', 'due_date' => today()]);
    $this->t['b4']->update(['milestone_id' => $foreign->id]);
    $company = CrmCompany::factory()->create(['created_by' => $this->actors['operator']->id]);
    $p2->companies()->attach($company);

    $query = new TaskQuery($member, TaskQuery::VIEW_ALL);

    expect(idsOf($query->results(anyState('all', ['project' => $p2->id]))))->toBe([])
        ->and(idsOf($query->results(anyState('all', ['project' => $p2->id, 'milestone' => $foreign->id]))))->toBe([])
        ->and(idsOf($query->results(anyState('all', ['organization' => $company->id]))))->toBe([])
        ->and(idsOf($query->results(anyState('all', ['search' => 'delta']))))->toBe([]);
});

// ── Filter semantics (§9.5) ──────────────────────────────────────────────────

it('derives completion per kind: the column for a board task, the status for a standalone one', function () {
    $this->t['b1']->update(['status' => 'done']);   // raw status ignored on a column-backed task
    $this->t['b5']->update(['status' => 'todo']);   // in the Done column: done regardless
    $query = new TaskQuery($this->actors['member'], TaskQuery::VIEW_MINE);

    expect(labelsOf($this->world, idsOf($query->results(new TaskListState(view: 'mine')))))->toBe(['b1', 's1', 's3'])
        ->and(labelsOf($this->world, idsOf($query->results(new TaskListState(view: 'mine', completion: 'done')))))->toBe(['b5', 's2'])
        ->and(labelsOf($this->world, idsOf($query->results(new TaskListState(view: 'mine', completion: 'any')))))->toBe(['b1', 'b5', 's1', 's2', 's3']);
});

it('filters by priority set, due preset and kind', function () {
    $query = new TaskQuery($this->actors['member'], TaskQuery::VIEW_ALL);
    $labels = fn (array $state) => labelsOf($this->world, idsOf($query->results(anyState('all', $state))));

    expect($labels(['priorities' => ['critical']]))->toBe(['b3', 's3'])
        ->and($labels(['priorities' => ['critical', 'high']]))->toBe(['b1', 'b3', 's3'])
        // Overdue is open-only (scopeOverdue): b5 is past due but done.
        ->and($labels(['due' => 'overdue']))->toBe(['s3'])
        ->and($labels(['due' => 'today']))->toBe(['s2'])
        // next7: today through six days ahead.
        ->and($labels(['due' => 'next7']))->toBe(['b1', 's2'])
        ->and($labels(['due' => 'none']))->toBe(['b2', 'b3', 's1', 's5'])
        ->and($labels(['kind' => 'project']))->toBe(['b1', 'b2', 'b3', 'b5'])
        ->and($labels(['kind' => 'standalone']))->toBe(['s1', 's2', 's3', 's5']);
});

it('bounds next7 at six days ahead, today included', function () {
    $member = $this->actors['member'];
    standaloneTask($member, $member, ['title' => 'edge six', 'due_date' => today()->addDays(6)]);
    standaloneTask($member, $member, ['title' => 'edge seven', 'due_date' => today()->addDays(7)]);

    $titles = (new TaskQuery($member, 'mine'))->results(anyState('mine', ['due' => 'next7']))->pluck('title')->sort()->values()->all();

    expect($titles)->toBe(['b1 alpha', 'edge six', 's2 golf']);
});

it('filters by project, then by a milestone of that project only', function () {
    $p1 = $this->world['projects']['p1'];
    $milestone = $p1->milestones()->create(['name' => 'Launch', 'due_date' => today()]);
    $this->t['b3']->update(['milestone_id' => $milestone->id]);
    $query = new TaskQuery($this->actors['operator'], TaskQuery::VIEW_ALL);

    expect(labelsOf($this->world, idsOf($query->results(anyState('all', ['project' => $p1->id])))))->toBe(['b1', 'b2', 'b3', 'b5'])
        ->and(labelsOf($this->world, idsOf($query->results(anyState('all', ['project' => $p1->id, 'milestone' => $milestone->id])))))->toBe(['b3'])
        // A milestone never applies without its project: the query refuses to guess one.
        ->and(labelsOf($this->world, idsOf($query->results(anyState('all', ['milestone' => $milestone->id])))))->toBe(['b1', 'b2', 'b3', 'b4', 'b5', 's3', 's5']);
});

it('filters by assignee, or by no assignee', function () {
    $query = new TaskQuery($this->actors['operator'], TaskQuery::VIEW_ALL);

    expect(labelsOf($this->world, idsOf($query->results(anyState('all', ['assignee' => $this->actors['departed']->id])))))->toBe(['b2'])
        ->and(labelsOf($this->world, idsOf($query->results(anyState('all', ['assignee' => 'none'])))))->toBe(['b3']);
});

it('narrows by organization through the project\'s company link, never matching a standalone task', function () {
    $operator = $this->actors['operator'];
    $org = Organization::factory()->create(['owner_id' => $operator->id]);
    $company = CrmCompany::factory()->create(['created_by' => $operator->id, 'organization_id' => $org->id]);
    $this->world['projects']['p1']->companies()->attach($company);

    $labels = labelsOf($this->world, idsOf(
        (new TaskQuery($operator, TaskQuery::VIEW_ALL))->results(anyState('all', ['organization' => $company->id]))
    ));

    expect($labels)->toBe(['b1', 'b2', 'b3', 'b5']);
});

// ── Search (§9.6, P7) ────────────────────────────────────────────────────────

it('searches titles only, treating LIKE wildcards and backslashes literally', function () {
    $member = $this->actors['member'];
    standaloneTask($member, $member, ['title' => '100% done', 'description' => 'zzz-secret']);
    standaloneTask($member, $member, ['title' => 'snake_case']);
    standaloneTask($member, $member, ['title' => 'snakeXcase']);
    standaloneTask($member, $member, ['title' => 'back\\slash']);
    $query = new TaskQuery($member, 'mine');
    $titles = fn (string $q) => $query->results(anyState('mine', ['search' => $q]))->pluck('title')->sort()->values()->all();

    expect($titles('%'))->toBe(['100% done'])
        ->and($titles('_'))->toBe(['snake_case'])
        ->and($titles('\\'))->toBe(['back\\slash'])
        ->and($titles('ALPHA'))->toBe(['b1 alpha'])
        ->and($titles('zzz-secret'))->toBe([]);
});

// ── Sort and pagination (§9.6, §9.1 step 5) ──────────────────────────────────

it('sorts by due date with undated rows last, then newest', function () {
    $ordered = (new TaskQuery($this->actors['member'], 'mine'))->results(anyState('mine'))->pluck('title')->all();

    // s3 (yesterday) and b5 (3 days ago) first, then today, then +2; undated newest first.
    expect($ordered)->toBe(['b5 echo', 's3 hotel', 's2 golf', 'b1 alpha', 's1 foxtrot']);
});

it('sorts by priority by rank, not alphabetically, both ways', function () {
    $query = new TaskQuery($this->actors['operator'], 'all');
    $priorities = fn (string $dir) => $query->results(anyState('all', ['sort' => 'priority', 'direction' => $dir]))->pluck('priority')->unique()->values()->all();

    expect($priorities('desc'))->toBe(['critical', 'high', 'medium', 'low'])
        ->and($priorities('asc'))->toBe(['low', 'medium', 'high', 'critical']);
});

it('breaks every tie by id so pages never overlap or skip', function () {
    $member = $this->actors['member'];
    foreach (range(1, 40) as $i) {
        standaloneTask($member, $member, ['title' => 'Same title', 'priority' => 'low']);
    }

    foreach (['due', 'priority', 'title', 'updated', 'created'] as $sort) {
        foreach (['asc', 'desc'] as $dir) {
            $state = anyState('mine', ['sort' => $sort, 'direction' => $dir]);
            $query = new TaskQuery($member, 'mine');
            $pages = collect([1, 2])->flatMap(fn (int $page) => $query->paginate($state, $page)->getCollection()->pluck('id'));

            expect($pages->duplicates()->all())->toBe([], "{$sort} {$dir}")
                ->and($pages->count())->toBe(45, "{$sort} {$dir}")
                ->and($query->results($state)->pluck('tasks.id')->all())->toBe($query->results($state)->pluck('tasks.id')->all());
        }
    }
});

it('paginates 30 per page and counts only authorized rows', function () {
    $stranger = $this->actors['stranger'];
    foreach (range(1, 31) as $i) {
        standaloneTask($stranger, null, ['title' => "Mine {$i}"]);
    }

    $page = (new TaskQuery($stranger, 'mine'))->paginate(anyState('mine'), 1);

    expect($page->perPage())->toBe(30)
        ->and($page->total())->toBe(32)   // s4 + 31; nobody else's rows move the count
        ->and($page->lastPage())->toBe(2);
});
