<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Queries\TaskListState;
use App\Queries\TaskQuery;
use Illuminate\Support\Facades\Gate;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-015 WP3 (§13.1, §13.2, Q6, INV-P12): `TaskQuery::forProject`, below HTTP. It is the Tasks
 * workspace pipeline with the project as step 2: step 1 (the actor's authorized surfaced set) still
 * runs, there is no Mine/All, and every filter, the search and the sort are the shared ones, each only
 * ever AND-ed onto the project set.
 *
 * One world shared by every test, so each assertion names exact rows:
 *
 *   P "Apollo"  (manager, member, customer are members; departed left)
 *     col 0 To Do      p4 "delta"            departed     critical  due -1 (overdue)
 *     col 1 In Prog.   p1 "alpha report"     member       high      due +2   milestone mA   pos 0
 *                      p2 "bravo 100%_done"  unassigned   low       no date               pos 1
 *     col 4 Done       p3 "charlie"          manager      medium    due -3   milestone mA
 *                      d1 "alpha malformed"  malformed (P + ticket), assigned "Mo Malformed", milestone mA
 *   Q "Borealis": q1 "alpha borealis" assigned "Quinn Q", milestone mQ
 *   standalone s1 "alpha standalone" (member's, assigned "Sam S"); ticket task t1 "alpha ticket"
 *   milestones: mA (P, due +5), mB (P, due +1, no tasks), mQ (Q)
 */

function projectTaskWorld(): array
{
    $operator = makeUser('operator', ['name' => 'Olive Operator']);
    $manager = makeUser('user', ['name' => 'Mona Manager']);
    $manager->givePermissionTo('projects.manage');
    $member = makeUser('user', ['name' => 'Max Member']);
    $customer = makeUser('user', ['name' => 'Cara Customer']);
    $departed = makeUser('user', ['name' => 'Dora Departed']);
    $outsider = makeUser('user', ['name' => 'Otto Outsider']);
    $adminOnly = tap(makeUser('user', ['name' => 'Ada AdminOnly']))->givePermissionTo('projects.admin');
    $malformedAssignee = makeUser('user', ['name' => 'Mo Malformed']);
    $quinn = makeUser('user', ['name' => 'Quinn Q']);
    $sam = makeUser('user', ['name' => 'Sam S']);

    $p = makeProject($operator, 'Apollo');
    $q = makeProject($operator, 'Borealis');
    $p->members()->attach($manager->id, ['role' => 'manager']);
    $p->members()->attach($member->id, ['role' => 'member']);
    $p->members()->attach($customer->id, ['role' => 'member']);
    $q->members()->attach($member->id, ['role' => 'member']);
    // Departed: a former member, still the stored assignee of p4.
    $p->members()->attach($departed->id, ['role' => 'member']);
    $p->members()->detach($departed->id);

    $mA = $p->milestones()->create(['name' => 'Alpha launch', 'due_date' => today()->addDays(5)]);
    $mB = $p->milestones()->create(['name' => 'Beta (empty)', 'due_date' => today()->addDay()]);
    $mQ = $q->milestones()->create(['name' => 'Borealis secret', 'due_date' => today()->addDays(2)]);

    [$todo, $doing, $done] = [$p->columns[0], $p->columns[1], $p->columns->firstWhere('is_done_column', true)];

    $t = [];
    $t['p1'] = makeTask($doing, ['title' => 'alpha report', 'assignee_id' => $member->id, 'priority' => 'high', 'due_date' => today()->addDays(2), 'milestone_id' => $mA->id, 'position' => 0]);
    $t['p2'] = makeTask($doing, ['title' => 'bravo 100%_done', 'assignee_id' => null, 'priority' => 'low', 'due_date' => null, 'position' => 1]);
    $t['p3'] = makeTask($done, ['title' => 'charlie', 'assignee_id' => $manager->id, 'priority' => 'medium', 'due_date' => today()->subDays(3), 'milestone_id' => $mA->id, 'status' => 'todo']);
    $t['p4'] = makeTask($todo, ['title' => 'delta', 'assignee_id' => $departed->id, 'priority' => 'critical', 'due_date' => today()->subDay(), 'status' => 'done']);
    $t['d1'] = makeTask($doing, ['title' => 'alpha malformed', 'assignee_id' => $malformedAssignee->id, 'milestone_id' => $mA->id, 'position' => 2, 'ticket_id' => Ticket::factory()->create()->id]);
    $t['q1'] = makeTask($q->columns[1], ['title' => 'alpha borealis', 'assignee_id' => $quinn->id, 'milestone_id' => $mQ->id]);
    $t['s1'] = Task::factory()->standalone()->create(['title' => 'alpha standalone', 'created_by' => $member->id, 'assignee_id' => $sam->id, 'priority' => 'high']);
    $t['t1'] = Task::factory()->standalone()->create([
        'title' => 'alpha ticket', 'created_by' => $member->id, 'assignee_id' => $member->id,
        'ticket_id' => Ticket::factory()->create(['user_id' => $member->id])->id,
    ]);

    return [
        'actors' => compact('operator', 'manager', 'member', 'customer', 'departed', 'outsider', 'adminOnly', 'quinn', 'sam', 'malformedAssignee'),
        'p' => $p, 'q' => $q,
        'milestones' => compact('mA', 'mB', 'mQ'),
        'tasks' => $t,
    ];
}

/** @return array<int, string> fixture labels of the ids, in the order given */
function projectTaskLabels(array $world, iterable $ids): array
{
    $byId = collect($world['tasks'])->mapWithKeys(fn (Task $task, string $label) => [$task->id => $label]);

    return collect($ids)->map(fn (int $id) => $byId[$id] ?? "#{$id}")->values()->all();
}

/** Every-completion project state, so a set test is not narrowed by the open default. */
function projectState(array $overrides = []): TaskListState
{
    return new TaskListState(...['view' => TaskQuery::VIEW_PROJECT, 'completion' => 'any', ...$overrides]);
}

function projectRows(User $actor, Project $project, TaskListState $state): array
{
    return TaskQuery::forProject($actor, $project)->results($state)->pluck('tasks.id')->all();
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->w = projectTaskWorld();
    $this->labels = fn (iterable $ids) => projectTaskLabels($this->w, $ids);
    $this->sorted = fn (iterable $ids) => collect(($this->labels)($ids))->sort()->values()->all();
});

// ── Base set (§13.1, INV-P12) ──────────────────────────────────────────────

it('is every valid board task of the project, the same for every viewer: no Mine/All', function (string $who) {
    $actor = $this->w['actors'][$who];

    expect(($this->sorted)(projectRows($actor, $this->w['p'], projectState())))
        ->toBe(['p1', 'p2', 'p3', 'p4']);
})->with(['operator', 'manager', 'member', 'customer', 'adminOnly']);

it('never surfaces a malformed project+ticket row, another project, a standalone or a ticket task', function () {
    $ids = projectRows($this->w['actors']['operator'], $this->w['p'], projectState());

    foreach (['d1', 'q1', 's1', 't1'] as $excluded) {
        // One needle per assertion, the diagnostic as the message: `->not->toContain($id, $message)`
        // treats the message as a second needle and so could never fail (EPIC-015 WP6 vacuity sweep).
        $this->assertNotContains($this->w['tasks'][$excluded]->id, $ids, "{$excluded} leaked into project scope");
    }
});

it('equals TaskPolicy::view-able tasks of the project for every actor shape (INV-P12)', function (string $who) {
    $actor = $this->w['actors'][$who];
    $project = $this->w['p'];

    $ids = projectRows($actor, $project, projectState());
    $viewable = Task::where('project_id', $project->id)->get()
        ->filter(fn (Task $task) => Gate::forUser($actor)->allows('view', $task))
        ->pluck('id')->sort()->values()->all();

    expect(collect($ids)->sort()->values()->all())->toBe($viewable)
        ->and(collect($ids)->sort()->values()->all())->toBe(
            TaskQuery::authorizedFor($actor)->where('tasks.project_id', $project->id)->orderBy('tasks.id')->pluck('tasks.id')->all()
        );
})->with(['operator', 'manager', 'member', 'customer', 'departed', 'outsider', 'adminOnly']);

it('still returns nothing for an actor who may not view the project, below the controller', function (string $who) {
    expect(projectRows($this->w['actors'][$who], $this->w['p'], projectState()))->toBe([]);
})->with(['outsider', 'departed', 'quinn']);

it('defaults to open tasks by the board column, never by tasks.status', function () {
    // p3 sits in Done with status "todo"; p4 sits in To Do with status "done".
    $actor = $this->w['actors']['member'];

    expect(($this->sorted)(projectRows($actor, $this->w['p'], projectState(['completion' => 'open']))))->toBe(['p1', 'p2', 'p4'])
        ->and(($this->sorted)(projectRows($actor, $this->w['p'], projectState(['completion' => 'done']))))->toBe(['p3']);
});

// ── Filters only narrow (§13.2) ────────────────────────────────────────────

it('applies each filter as a narrowing of the project set', function (array $state, array $expected) {
    $actor = $this->w['actors']['customer'];
    $state = array_map(fn ($value) => is_string($value) && str_starts_with($value, '@')
        ? match ($value) {
            '@mA' => $this->w['milestones']['mA']->id,
            '@mB' => $this->w['milestones']['mB']->id,
            '@member' => $this->w['actors']['member']->id,
            '@departed' => $this->w['actors']['departed']->id,
            '@quinn' => $this->w['actors']['quinn']->id,
            '@sam' => $this->w['actors']['sam']->id,
            '@malformed' => $this->w['actors']['malformedAssignee']->id,
        }
        : $value, $state);

    $ids = projectRows($actor, $this->w['p'], projectState($state));
    $base = projectRows($actor, $this->w['p'], projectState());

    expect(array_diff($ids, $base))->toBe([])
        ->and(($this->sorted)($ids))->toBe($expected);
})->with([
    'milestone with tasks' => [['milestone' => '@mA'], ['p1', 'p3']],
    'milestone with no tasks' => [['milestone' => '@mB'], []],
    'assignee: a member' => [['assignee' => '@member'], ['p1']],
    'assignee: a departed assignee still on a task' => [['assignee' => '@departed'], ['p4']],
    'assignee: unassigned' => [['assignee' => 'none'], ['p2']],
    'assignee: only on another project' => [['assignee' => '@quinn'], []],
    'assignee: only on a standalone task' => [['assignee' => '@sam'], []],
    'assignee: only on the malformed row' => [['assignee' => '@malformed'], []],
    'assignee: nobody at all' => [['assignee' => 999999], []],
    'priority' => [['priorities' => ['critical', 'high']], ['p1', 'p4']],
    'due overdue' => [['due' => 'overdue'], ['p4']],
    'due next 7' => [['due' => 'next7'], ['p1']],
    'due none' => [['due' => 'none'], ['p2']],
    'search: title fragment' => [['search' => 'alpha'], ['p1']],
    'search: % is literal' => [['search' => '%'], ['p2']],
    'search: _ is literal' => [['search' => '_'], ['p2']],
    'search: backslash is literal' => [['search' => '\\'], []],
    'milestone + assignee' => [['milestone' => '@mA', 'assignee' => '@member'], ['p1']],
    'milestone + open' => [['milestone' => '@mA', 'completion' => 'open'], ['p1']],
]);

it('never widens: every combination of filters is a subset of the project set', function () {
    $actor = $this->w['actors']['member'];
    $base = projectRows($actor, $this->w['p'], projectState());
    $mA = $this->w['milestones']['mA']->id;
    $quinn = $this->w['actors']['quinn']->id;

    foreach (['open', 'done', 'any'] as $completion) {
        foreach ([null, $mA] as $milestone) {
            foreach ([null, 'none', $quinn] as $assignee) {
                foreach ([null, 'overdue', 'none'] as $due) {
                    foreach (['', 'alpha', '%'] as $search) {
                        $ids = projectRows($actor, $this->w['p'], new TaskListState(
                            view: TaskQuery::VIEW_PROJECT, completion: $completion, milestone: $milestone,
                            assignee: $assignee, due: $due, search: $search,
                        ));

                        expect(array_diff($ids, $base))->toBe([], json_encode(compact('completion', 'milestone', 'assignee', 'due', 'search')));
                    }
                }
            }
        }
    }
});

// ── Sort (§13.2) ───────────────────────────────────────────────────────────

it('sorts by the shared allowlist with an id tiebreak, undated last', function (string $sort, ?string $dir, array $expected) {
    $ids = projectRows($this->w['actors']['member'], $this->w['p'], projectState(['sort' => $sort, 'direction' => $dir]));

    expect(($this->labels)($ids))->toBe($expected);
})->with([
    'due asc: dated first, undated last' => ['due', null, ['p3', 'p4', 'p1', 'p2']],
    'due desc: undated still last' => ['due', 'desc', ['p1', 'p4', 'p3', 'p2']],
    'title' => ['title', null, ['p1', 'p2', 'p3', 'p4']],
    'priority (rank, critical first)' => ['priority', null, ['p4', 'p1', 'p3', 'p2']],
    'board: column position, then task position' => ['board', null, ['p4', 'p1', 'p2', 'p3']],
    'board desc' => ['board', 'desc', ['p3', 'p2', 'p1', 'p4']],
]);

it('breaks a board-order tie by id, so equal positions page deterministically', function () {
    $column = $this->w['p']->columns[2];
    $a = makeTask($column, ['title' => 'tie a', 'position' => 0]);
    $b = makeTask($column, ['title' => 'tie b', 'position' => 0]);

    $ids = projectRows($this->w['actors']['member'], $this->w['p'], projectState(['sort' => 'board']));

    expect(array_search($a->id, $ids, true))->toBeLessThan(array_search($b->id, $ids, true));
});

// ── URL state (§13.2: unknown values dropped, never a redirect) ─────────────

it('keeps a milestone only when it is one of this project’s', function (mixed $raw, string $expect) {
    $query = TaskQuery::forProject($this->w['actors']['member'], $this->w['p']);
    $raw = match ($raw) {
        '@mA' => (string) $this->w['milestones']['mA']->id,
        '@mB' => (string) $this->w['milestones']['mB']->id,
        '@mQ' => (string) $this->w['milestones']['mQ']->id,
        default => $raw,
    };

    $state = TaskListState::fromProjectInput(['milestone' => $raw], $query->projectFilterOptions());

    expect($state->milestone)->toBe(match ($expect) {
        'kept' => (int) $raw,
        'dropped' => null,
    });
})->with([
    'a project milestone' => ['@mA', 'kept'],
    'a project milestone with no tasks' => ['@mB', 'kept'],
    'another project’s milestone' => ['@mQ', 'dropped'],
    'a nonexistent id' => ['999999', 'dropped'],
    'malformed: letters' => ['abc', 'dropped'],
    'malformed: negative' => ['-1', 'dropped'],
    'malformed: decimal' => ['1.5', 'dropped'],
    'malformed: array' => [['1'], 'dropped'],
    'malformed: zero' => ['0', 'dropped'],
]);

it('ignores a foreign milestone instead of narrowing to nothing, so it reveals nothing', function () {
    $actor = $this->w['actors']['member'];
    $query = TaskQuery::forProject($actor, $this->w['p']);
    $options = $query->projectFilterOptions();

    $foreign = TaskListState::fromProjectInput(['milestone' => (string) $this->w['milestones']['mQ']->id], $options);
    $missing = TaskListState::fromProjectInput(['milestone' => '999999'], $options);
    $none = TaskListState::fromProjectInput([], $options);

    expect($query->results($foreign)->pluck('tasks.id')->all())->toBe($query->results($none)->pluck('tasks.id')->all())
        ->and($query->results($missing)->pluck('tasks.id')->all())->toBe($query->results($none)->pluck('tasks.id')->all());
});

it('drops the global-only parameters, so none can widen or retarget the scope', function () {
    $actor = $this->w['actors']['member'];
    $query = TaskQuery::forProject($actor, $this->w['p']);

    $state = TaskListState::fromProjectInput([
        'view' => 'all',
        'project' => (string) $this->w['q']->id,
        'kind' => 'standalone',
        'organization' => '1',
        'completion' => 'any',
    ], $query->projectFilterOptions());

    expect($state->project)->toBeNull()
        ->and($state->kind)->toBeNull()
        ->and($state->organization)->toBeNull()
        ->and($state->projectFilters())->toBe([
            'completion' => 'any', 'priority' => [], 'due' => null, 'milestone' => null, 'assignee' => null, 'q' => '',
        ])
        ->and(($this->sorted)($query->results($state)->pluck('tasks.id')))->toBe(['p1', 'p2', 'p3', 'p4']);
});

it('normalizes the shared parameters exactly as the Tasks workspace does', function () {
    $options = ['milestones' => []];

    $state = TaskListState::fromProjectInput([
        'completion' => 'bogus', 'priority' => ['high', 'nope', 'high'], 'due' => 'yesterday',
        'assignee' => 'someone', 'q' => '  '.str_repeat('x', 150).'  ', 'sort' => 'board', 'dir' => 'sideways',
    ], $options);

    expect($state->completion)->toBe('open')
        ->and($state->priorities)->toBe(['high'])
        ->and($state->due)->toBeNull()
        ->and($state->assignee)->toBeNull()
        ->and(mb_strlen($state->search))->toBe(TaskListState::SEARCH_LIMIT)
        ->and($state->sort())->toBe(['by' => 'board', 'dir' => 'asc'])
        ->and(TaskListState::fromProjectInput(['assignee' => 'none'], $options)->assignee)->toBe('none')
        ->and(TaskListState::fromProjectInput(['assignee' => '42'], $options)->assignee)->toBe(42);
});

it('offers the board sort in project scope only', function () {
    $global = TaskListState::fromInput(['sort' => 'board'], TaskQuery::VIEW_ALL, ['milestones' => []]);

    expect($global->sort)->toBe('due')
        ->and(fn () => new TaskListState(view: TaskQuery::VIEW_ALL, sort: 'board'))->toThrow(InvalidArgumentException::class)
        ->and(TaskListState::fromProjectInput(['sort' => 'board'], ['milestones' => []])->sort)->toBe('board');
});

it('refuses a project state carrying a global-only filter, and a project view without a project', function () {
    $member = $this->w['actors']['member'];

    expect(fn () => new TaskListState(view: TaskQuery::VIEW_PROJECT, kind: 'project'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TaskListState(view: TaskQuery::VIEW_PROJECT, project: $this->w['q']->id))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TaskListState(view: TaskQuery::VIEW_PROJECT, organization: 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TaskQuery($member, TaskQuery::VIEW_PROJECT))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TaskQuery($member, TaskQuery::VIEW_ALL, $this->w['p']))->toThrow(InvalidArgumentException::class)
        ->and(TaskQuery::resolveView($member, TaskQuery::VIEW_PROJECT))->toBe(TaskQuery::VIEW_MINE);
});

it('echoes canonical pagination state with no project, kind, organization or view', function () {
    $state = TaskListState::fromProjectInput([
        'completion' => 'open', 'milestone' => (string) $this->w['milestones']['mA']->id, 'assignee' => 'none',
        'q' => 'x', 'sort' => 'board', 'view' => 'all', 'junk' => '1',
    ], TaskQuery::forProject($this->w['actors']['member'], $this->w['p'])->projectFilterOptions());

    expect($state->query())->toBe([
        'milestone' => $this->w['milestones']['mA']->id, 'assignee' => 'none', 'q' => 'x', 'sort' => 'board', 'dir' => 'asc',
    ]);
});

// ── Options (§13.2, INV-17) ────────────────────────────────────────────────

it('offers this project’s milestones and the distinct assignees of its valid tasks only', function () {
    $options = TaskQuery::forProject($this->w['actors']['customer'], $this->w['p'])->projectFilterOptions();

    expect($options['milestones'])->toBe([
        ['id' => $this->w['milestones']['mB']->id, 'name' => 'Beta (empty)'],
        ['id' => $this->w['milestones']['mA']->id, 'name' => 'Alpha launch'],
    ])->and(array_column($options['assignees'], 'name'))->toBe(['Dora Departed', 'Max Member', 'Mona Manager'])
        ->and(array_keys($options['assignees'][0]))->toBe(['id', 'name']);
});

it('has no project options outside project scope', function () {
    expect(fn () => (new TaskQuery($this->w['actors']['member'], TaskQuery::VIEW_ALL))->projectFilterOptions())
        ->toThrow(InvalidArgumentException::class);
});

// ── Rows (§13.3, P4) ───────────────────────────────────────────────────────

it('loads each row’s milestone in project scope only, and the project from the instance in hand', function () {
    $actor = $this->w['actors']['member'];
    $page = TaskQuery::forProject($actor, $this->w['p'])->paginate(projectState());

    foreach ($page->getCollection() as $task) {
        expect($task->relationLoaded('milestone'))->toBeTrue()
            ->and($task->getRelation('project'))->toBe($this->w['p']);
    }

    $global = (new TaskQuery($actor, TaskQuery::VIEW_ALL))->paginate(new TaskListState(view: TaskQuery::VIEW_ALL, completion: 'any'));
    expect($global->getCollection()->contains(fn (Task $task) => $task->relationLoaded('milestone')))->toBeFalse();
});

it('keeps the milestone at {id, name}: no lifecycle or provenance', function () {
    $task = TaskQuery::forProject($this->w['actors']['member'], $this->w['p'])
        ->paginate(projectState(['search' => 'alpha']))->getCollection()->sole();

    expect(array_keys($task->milestone->getAttributes()))->toBe(['id', 'name']);
});
