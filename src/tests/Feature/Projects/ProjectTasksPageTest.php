<?php

use App\Http\Presenters\ProjectOverviewPresenter;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\ProjectSettingsAccess;
use App\Queries\TaskListState;
use App\Queries\TaskQuery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-015 WP3 (§7, §11, §13, INV-P2, INV-P8, INV-P12, INV-P15): the project Tasks tab,
 * `GET /projects/{project}/tasks` (`projects.tasks.index`), through real HTTP requests.
 *
 * The query itself (base set, every filter, sort, URL normalization) is pinned below HTTP in
 * Tasks/ProjectTaskQueryTest. These tests pin the route: authorization first, the exact prop
 * contract, the row DTO (the canonical row plus the milestone), the batched abilities' parity with
 * TaskPolicy, the Settings ability through the shared resolver, canonical pagination links, the
 * read-only route surface, and that the list agrees with the Overview's counts.
 */

const PROJECT_TASKS_SHARED_PROPS = ['app', 'auth', 'shell', 'navigation', 'flash', 'errors'];

/** The page's own props, without the shell's shared ones, as the browser receives them. */
function projectTasksPageProps($response): array
{
    $props = array_diff_key($response->viewData('page')['props'], array_flip(PROJECT_TASKS_SHARED_PROPS));

    return json_decode(json_encode($props), true);
}

function projectTasksTitles(array $props): array
{
    return array_column($props['tasks']['data'], 'title');
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->owner = makeUser('operator', ['name' => 'Owner Olive', 'email' => 'olive@example.test']);
    $this->project = makeProject($this->owner, 'Apollo');
    [$this->todo, $this->doing, , , $this->done] = $this->project->columns->all();
    $this->milestone = $this->project->milestones()->create(['name' => 'Go-live', 'due_date' => today()->addWeek()]);

    $this->member = makeUser('user', ['name' => 'Max Member', 'email' => 'max@example.test']);
    $this->project->members()->attach($this->member->id, ['role' => 'member']);
    $this->customer = makeUser('user', ['name' => 'Cara Customer', 'email' => 'cara@example.test']);
    $this->project->members()->attach($this->customer->id, ['role' => 'member']);

    $this->mine = makeTask($this->doing, ['title' => 'Mine open', 'assignee_id' => $this->member->id, 'milestone_id' => $this->milestone->id, 'due_date' => today()->subDay()]);
    $this->theirs = makeTask($this->doing, ['title' => 'Owner open', 'assignee_id' => $this->owner->id, 'position' => 1]);
    $this->finished = makeTask($this->done, ['title' => 'Finished', 'assignee_id' => $this->member->id]);

    $other = makeProject($this->owner, 'Borealis');
    $other->members()->attach($this->member->id, ['role' => 'member']);
    makeTask($other->columns[1], ['title' => 'Other project']);
    makeTask($this->doing, ['title' => 'Malformed', 'ticket_id' => Ticket::factory()->create()->id, 'position' => 2]);
    Task::factory()->standalone()->create(['title' => 'Standalone', 'created_by' => $this->member->id, 'assignee_id' => $this->member->id]);
    Task::factory()->standalone()->create([
        'title' => 'Ticket task', 'assignee_id' => $this->member->id,
        'ticket_id' => Ticket::factory()->create(['user_id' => $this->member->id])->id,
    ]);
});

// ── Route and authorization ──────────────────────────────────────────────────

it('renders the projects/tasks/index component at /projects/{project}/tasks', function () {
    expect(route('projects.tasks.index', $this->project, false))->toBe("/projects/{$this->project->id}/tasks");

    $this->actingAs($this->member)->get(route('projects.tasks.index', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('projects/tasks/index'));
});

it('authorizes ProjectPolicy::view before building anything', function (string $actor, int $status) {
    $user = projectActor($actor, $this->project, $this->mine);

    $this->actingAs($user)->get(route('projects.tasks.index', $this->project))->assertStatus($status);
})->with([
    'outsider' => ['outsider', 403],
    'stale assignee who is not a member' => ['assignee', 403],
    'member' => ['member', 200],
    'manager pivot without projects.manage' => ['manager_role', 200],
    'project manager' => ['project_manager', 200],
    'operator' => ['admin', 200],
    'projects.admin alone (A9)' => ['admin_only', 200],
]);

it('sends a guest to login', function () {
    $this->get(route('projects.tasks.index', $this->project))->assertRedirect('/login');
});

it('needs no tasks.view_all: a customer member sees the same project rows as the owner', function () {
    expect($this->customer->can('tasks.view_all'))->toBeFalse();

    $customer = projectTasksPageProps($this->actingAs($this->customer)->get(route('projects.tasks.index', $this->project)));
    $owner = projectTasksPageProps($this->actingAs($this->owner)->get(route('projects.tasks.index', $this->project)));

    expect(projectTasksTitles($customer))->toBe(['Mine open', 'Owner open'])
        ->and(projectTasksTitles($owner))->toBe(projectTasksTitles($customer));
});

it('ignores a view parameter: there is no Mine/All in project scope', function () {
    $mine = projectTasksPageProps($this->actingAs($this->member)->get(route('projects.tasks.index', [$this->project, 'view' => 'mine'])));

    expect(projectTasksTitles($mine))->toBe(['Mine open', 'Owner open'])
        ->and($mine)->not->toHaveKey('view');
});

// ── Props contract (§13, INV-P8, INV-P15) ───────────────────────────────────

// FLIPPED IN EPIC-015 WP5: the page gains `tabs` (the workspace navigation's optional Time tab,
// `ProjectPresenter::workspaceTabs`). Was: the same list without `tabs`.
it('carries exactly the project tab contract', function () {
    $props = projectTasksPageProps($this->actingAs($this->member)->get(route('projects.tasks.index', $this->project)));

    expect(array_keys($props))->toBe(['project', 'tasks', 'filters', 'filterOptions', 'sort', 'projectHasTasks', 'assigneeOptions', 'tabs', 'abilities'])
        ->and($props['tabs'])->toBe(['time' => true])
        ->and($props['project'])->toBe(['id' => $this->project->id, 'name' => 'Apollo', 'status' => 'active'])
        ->and(array_keys($props['filters']))->toBe(['completion', 'priority', 'due', 'milestone', 'assignee', 'q'])
        ->and(array_keys($props['filterOptions']))->toBe(['completion', 'priorities', 'due', 'sorts', 'milestones', 'assignees'])
        ->and(array_column($props['filterOptions']['sorts'], 'value'))->toBe(['due', 'priority', 'title', 'updated', 'created', 'board'])
        ->and($props['sort'])->toBe(['by' => 'due', 'dir' => 'asc'])
        ->and($props['projectHasTasks'])->toBeTrue();

    // No create path on this page (P8): no create options and no project/kind/organization filter.
    foreach (['createOptions', 'canViewAll', 'view'] as $absent) {
        expect($props)->not->toHaveKey($absent);
    }
    foreach (['projects', 'kinds', 'organizations'] as $absent) {
        expect($props['filterOptions'])->not->toHaveKey($absent);
    }
});

it('builds each row as the canonical TaskRow plus the milestone {id, name}', function () {
    $props = projectTasksPageProps($this->actingAs($this->member)->get(route('projects.tasks.index', $this->project)));
    [$mine, $theirs] = $props['tasks']['data'];

    expect(array_keys($mine))->toBe(['id', 'title', 'kind', 'projectId', 'priority', 'status', 'dueDate', 'overdue', 'assignee', 'context', 'url', 'abilities', 'milestone'])
        ->and($mine['milestone'])->toBe(['id' => $this->milestone->id, 'name' => 'Go-live'])
        ->and($theirs['milestone'])->toBeNull()
        ->and($mine['kind'])->toBe('board')
        ->and($mine['url'])->toBe(route('projects.tasks.show', [$this->project, $this->mine]))
        ->and($mine['assignee'])->toBe(['id' => $this->member->id, 'name' => 'Max Member']);
});

it('leaks no email, no other project and no unsurfaced row', function () {
    $response = $this->actingAs($this->customer)->get(route('projects.tasks.index', [$this->project, 'completion' => 'any']));
    $json = json_encode(projectTasksPageProps($response));

    foreach (['@example.test', 'Borealis', 'Other project', 'Malformed', 'Standalone', 'Ticket task'] as $needle) {
        expect(str_contains($json, $needle))->toBeFalse("leaked: {$needle}");
    }
});

it('mirrors TaskPolicy in every row’s abilities, per actor shape', function (string $who) {
    $user = match ($who) {
        'assignee member' => $this->member,
        'customer member' => $this->customer,
        'operator' => $this->owner,
        'project manager' => projectActor('project_manager', $this->project),
        'projects.admin alone' => projectActor('admin_only', $this->project),
    };
    $props = projectTasksPageProps($this->actingAs($user)->get(route('projects.tasks.index', [$this->project, 'completion' => 'any'])));

    foreach ($props['tasks']['data'] as $row) {
        $task = Task::find($row['id']);
        expect($row['abilities'])->toBe([
            'complete' => Gate::forUser($user)->allows('complete', $task),
            'reopen' => Gate::forUser($user)->allows('reopen', $task),
            'assign' => Gate::forUser($user)->allows('assign', $task),
        ], "{$who} on {$row['title']}");
    }
})->with(['assignee member', 'customer member', 'operator', 'project manager', 'projects.admin alone']);

it('names assignment candidates only to an actor who may assign (TaskPolicy::assign), names only', function () {
    $member = projectTasksPageProps($this->actingAs($this->member)->get(route('projects.tasks.index', $this->project)));
    $manager = projectActor('project_manager', $this->project);
    $managed = projectTasksPageProps($this->actingAs($manager)->get(route('projects.tasks.index', $this->project)));

    expect($member['assigneeOptions']['projects'])->toBe([])
        ->and($managed['assigneeOptions']['projects'])->toHaveCount(1)
        ->and($managed['assigneeOptions']['projects'][0]['projectId'])->toBe($this->project->id)
        ->and(array_keys($managed['assigneeOptions']['projects'][0]['members'][0]))->toBe(['id', 'name']);
});

it('sends openSettings exactly when projects.edit admits the actor (A9, shared resolver)', function (string $kind, bool $expected) {
    $actor = settingsAccessActor($kind, $this->project);
    $response = $this->actingAs($actor)->get(route('projects.tasks.index', $this->project));

    if ($kind === 'outsider') {
        $response->assertForbidden();

        return;
    }

    $abilities = projectTasksPageProps($response)['abilities'];
    expect(array_key_exists('openSettings', $abilities))->toBe($expected)
        ->and(ProjectSettingsAccess::allows($actor, $this->project))->toBe($expected)
        ->and($this->actingAs($actor)->get(route('projects.edit', $this->project))->isOk())->toBe($expected);
})->with(array_map(fn ($kind, $expected) => [$kind, $expected], array_keys(SETTINGS_ACCESS_ACTORS), SETTINGS_ACCESS_ACTORS));

// ── Filters and URL state (§13.2) ───────────────────────────────────────────

it('echoes a valid milestone, and narrows to it', function () {
    $props = projectTasksPageProps($this->actingAs($this->member)->get(route('projects.tasks.index', [$this->project, 'milestone' => $this->milestone->id])));

    expect($props['filters']['milestone'])->toBe($this->milestone->id)
        ->and(projectTasksTitles($props))->toBe(['Mine open']);
});

it('drops a foreign, nonexistent or malformed milestone without labelling it or narrowing', function (string $which) {
    $foreign = makeProject($this->owner, 'Hidden')->milestones()->create(['name' => 'Secret milestone', 'due_date' => today()]);
    $value = match ($which) {
        'foreign' => (string) $foreign->id,
        'nonexistent' => '999999',
        'malformed' => '1;DROP',
    };

    $response = $this->actingAs($this->member)->get(route('projects.tasks.index', [$this->project, 'milestone' => $value]));
    $props = projectTasksPageProps($response);

    expect($props['filters']['milestone'])->toBeNull()
        ->and(projectTasksTitles($props))->toBe(['Mine open', 'Owner open'])
        ->and(str_contains(json_encode($props), 'Secret milestone'))->toBeFalse();
})->with(['foreign', 'nonexistent', 'malformed']);

it('keeps a well-formed assignee it did not offer as a narrowing filter, with no label', function () {
    $stranger = makeUser('user', ['name' => 'Stranger Name']);
    $props = projectTasksPageProps($this->actingAs($this->member)->get(route('projects.tasks.index', [$this->project, 'assignee' => $stranger->id])));

    expect($props['filters']['assignee'])->toBe($stranger->id)
        ->and($props['tasks']['data'])->toBe([])
        ->and($props['projectHasTasks'])->toBeTrue()
        ->and(str_contains(json_encode($props), 'Stranger Name'))->toBeFalse();
});

it('offers only the project’s milestones and the assignees of its visible rows', function () {
    $props = projectTasksPageProps($this->actingAs($this->customer)->get(route('projects.tasks.index', $this->project)));

    expect($props['filterOptions']['milestones'])->toBe([['id' => $this->milestone->id, 'name' => 'Go-live']])
        ->and(array_column($props['filterOptions']['assignees'], 'name'))->toBe(['Max Member', 'Owner Olive']);
});

it('ignores project, kind, organization and junk parameters', function () {
    $other = Project::where('name', 'Borealis')->sole();
    // Built by hand: `route()` would bind a `project` key to the {project} route parameter itself.
    $url = route('projects.tasks.index', $this->project).'?'.http_build_query([
        'project' => $other->id, 'kind' => 'standalone', 'organization' => 1, 'junk' => 'x',
    ]);
    $props = projectTasksPageProps($this->actingAs($this->member)->get($url));

    expect(projectTasksTitles($props))->toBe(['Mine open', 'Owner open'])
        ->and(array_keys($props['filters']))->toBe(['completion', 'priority', 'due', 'milestone', 'assignee', 'q']);
});

it('carries the normalized state, and only it, in pagination links', function () {
    foreach (range(1, 32) as $i) {
        makeTask($this->todo, ['title' => "Bulk {$i}", 'priority' => 'high', 'position' => $i]);
    }

    $props = projectTasksPageProps($this->actingAs($this->member)->get(route('projects.tasks.index', [
        $this->project, 'priority' => ['high', 'bogus'], 'q' => 'Bulk', 'sort' => 'board', 'view' => 'all', 'junk' => '1',
    ])));

    expect($props['tasks']['total'])->toBe(32)
        ->and($props['tasks']['last_page'])->toBe(2);

    parse_str(parse_url($props['tasks']['next_page_url'], PHP_URL_QUERY), $next);
    expect($next)->toBe(['priority' => ['high'], 'q' => 'Bulk', 'sort' => 'board', 'dir' => 'asc', 'page' => '2'])
        ->and(parse_url($props['tasks']['next_page_url'], PHP_URL_PATH))->toBe("/projects/{$this->project->id}/tasks");
});

// ── Empty states (§13) ──────────────────────────────────────────────────────

it('tells an empty project from an empty result', function () {
    $empty = makeProject($this->owner, 'Empty');
    $none = projectTasksPageProps($this->actingAs($this->owner)->get(route('projects.tasks.index', $empty)));
    $filtered = projectTasksPageProps($this->actingAs($this->owner)->get(route('projects.tasks.index', [$this->project, 'q' => 'zzz'])));

    expect($none['projectHasTasks'])->toBeFalse()
        ->and($none['tasks']['data'])->toBe([])
        ->and($filtered['projectHasTasks'])->toBeTrue()
        ->and($filtered['tasks']['data'])->toBe([]);
});

it('does not count the malformed row as a task when deciding the project is empty', function () {
    $project = makeProject($this->owner, 'Only malformed');
    makeTask($project->columns[1], ['title' => 'Malformed only', 'ticket_id' => Ticket::factory()->create()->id]);

    expect(projectTasksPageProps($this->actingAs($this->owner)->get(route('projects.tasks.index', $project)))['projectHasTasks'])->toBeFalse();
});

// ── Agreement with the Overview (§8.4, §11.3.1) ─────────────────────────────

it('lists exactly what the Overview counted behind its open and overdue links', function (string $who) {
    $user = $who === 'operator' ? $this->owner : $this->customer;
    $overview = ProjectOverviewPresenter::overview(Project::find($this->project->id), $user);

    $open = projectTasksPageProps($this->actingAs($user)->get(route('projects.tasks.index', [$this->project, 'completion' => 'open'])));
    $overdue = projectTasksPageProps($this->actingAs($user)->get(route('projects.tasks.index', [$this->project, 'completion' => 'open', 'due' => 'overdue'])));
    $any = projectTasksPageProps($this->actingAs($user)->get(route('projects.tasks.index', [$this->project, 'completion' => 'any'])));

    expect($open['tasks']['total'])->toBe($overview['tasks']['open'])
        ->and($overdue['tasks']['total'])->toBe($overview['tasks']['overdue'])
        ->and($any['tasks']['total'])->toBe($overview['tasks']['total'])
        ->and(projectTasksTitles($overdue))->toBe(['Mine open']);
})->with(['operator', 'customer member']);

// ── Read-only route surface (INV-P2, P7, P8) ────────────────────────────────

it('PINNED (INV-P2): the project task route surface gains only the read-only GET list', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => preg_match('#^projects/\{project\}/tasks#', $route->uri()))
        ->mapWithKeys(fn ($route) => [$route->getName() => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri()])
        ->sortKeys()->all();

    expect($routes)->toBe([
        'projects.tasks.checklist.destroy' => 'DELETE projects/{project}/tasks/{task}/checklist/{item}',
        'projects.tasks.checklist.store' => 'POST projects/{project}/tasks/{task}/checklist',
        'projects.tasks.checklist.toggle' => 'PUT projects/{project}/tasks/{task}/checklist/{item}/toggle',
        'projects.tasks.comments.store' => 'POST projects/{project}/tasks/{task}/comments',
        'projects.tasks.destroy' => 'DELETE projects/{project}/tasks/{task}',
        'projects.tasks.index' => 'GET projects/{project}/tasks',
        'projects.tasks.move' => 'PUT projects/{project}/tasks/{task}/move',
        'projects.tasks.show' => 'GET projects/{project}/tasks/{task}',
        'projects.tasks.store' => 'POST projects/{project}/tasks',
        'projects.tasks.update' => 'PUT projects/{project}/tasks/{task}',
    ]);
});

it('writes nothing, whatever the query string names', function () {
    $other = Project::where('name', 'Borealis')->sole();
    $before = Task::orderBy('id')->get(['id', 'project_id', 'column_id', 'position', 'status', 'assignee_id', 'milestone_id', 'updated_at'])->toArray();

    $this->actingAs($this->owner)->get(route('projects.tasks.index', $this->project).'?'.http_build_query([
        'project' => $other->id, 'project_id' => $other->id, 'assignee_id' => $this->member->id, 'status' => 'done',
    ]))->assertOk();

    expect(Task::orderBy('id')->get(['id', 'project_id', 'column_id', 'position', 'status', 'assignee_id', 'milestone_id', 'updated_at'])->toArray())->toBe($before);
});

it('keeps the Board as the place a project task is deleted from, and returned to (P7)', function () {
    $this->actingAs($this->owner)
        ->from(route('projects.tasks.index', $this->project))
        ->delete(route('projects.tasks.destroy', [$this->project, $this->theirs]))
        ->assertRedirect(route('projects.board', $this->project));
});

it('returns a row action to the project tab it came from', function () {
    $this->actingAs($this->member)
        ->from(route('projects.tasks.index', [$this->project, 'milestone' => $this->milestone->id]))
        ->put(route('tasks.complete', $this->mine))
        ->assertRedirect(route('projects.tasks.index', [$this->project, 'milestone' => $this->milestone->id]));

    expect($this->mine->fresh()->isDone())->toBeTrue();
});

it('uses the one canonical query: the page rows equal TaskQuery::forProject', function () {
    $props = projectTasksPageProps($this->actingAs($this->customer)->get(route('projects.tasks.index', [$this->project, 'completion' => 'any'])));
    $state = TaskListState::fromProjectInput(['completion' => 'any'], ['milestones' => []]);

    expect(array_column($props['tasks']['data'], 'id'))
        ->toBe(TaskQuery::forProject(User::find($this->customer->id), $this->project)->results($state)->pluck('tasks.id')->all());
});
