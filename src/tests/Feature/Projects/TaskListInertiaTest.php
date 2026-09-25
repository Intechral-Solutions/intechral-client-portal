<?php

use App\Models\Task;
use App\Models\Ticket;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-011E WP8: the unified `/tasks` list as an Inertia/React page. TaskListPresenter's DTO
 * (§5), the kind-aware status contract (§15), and D3's unchanged standalone surface are pinned
 * here. D2 visibility and link-permission behavior (org tab scoping, no dead links) are pinned in
 * ProjectVisibilityTest.php, which already exercised this route against the query/authorization
 * contract before this work package existed; these tests are about what the page DTO looks like
 * and how each task kind is represented once React owns the page. The route-level query budget
 * (bounded against row count) is already pinned in ProjectQueryBudgetTest.php.
 */

function taskListProps($response): array
{
    return $response->viewData('page')['props'];
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->admin = makeUser('operator', ['name' => 'Ada Admin']);
    $this->project = makeProject($this->admin, 'Alpha');
    $this->todo = $this->project->columns[1];
    $this->done = $this->project->columns[4];
});

// ── Page contract ─────────────────────────────────────────────────────────────

it('renders the unified list as the tasks/index component with a minimal DTO', function () {
    $task = makeTask($this->todo, [
        'title' => 'Ship it', 'priority' => 'high', 'due_date' => today()->addWeek(), 'assignee_id' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)->get(route('tasks.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('tasks/index')
            ->where('view', 'mine')
            ->has('tasks.data', 1)
            ->has('tasks.current_page')
            ->has('tasks.last_page')
            ->has('createOptions.priorities', 4)
            ->has('createOptions.statuses', 3));

    $props = taskListProps($response);
    $row = $props['tasks']['data'][0];
    expect(array_keys($row))->toBe([
        'id', 'title', 'priority', 'status', 'dueDate', 'overdue', 'assignee', 'context', 'url',
    ]);
    expect($row['id'])->toBe($task->id);
    expect(array_keys($row['context']))->toBe(['kind', 'label', 'url']);
});

it('never exposes an email, raw model attribute, or unrelated id on the tasks page', function () {
    $ticket = Ticket::factory()->create(['user_id' => $this->admin->id, 'ticket_number' => 'TKT-9001']);
    makeTask($this->todo, ['assignee_id' => $this->admin->id]);
    Task::factory()->standalone()->create(['assignee_id' => $this->admin->id, 'description' => 'Secret notes']);
    Task::factory()->standalone()->create(['assignee_id' => $this->admin->id, 'ticket_id' => $ticket->id]);

    $props = taskListProps($this->actingAs($this->admin)->get(route('tasks.index')));
    $json = json_encode(array_intersect_key($props, array_flip(['tasks', 'view', 'canViewOrg', 'createOptions'])));

    foreach (['email', 'Secret notes', 'created_at', 'updated_at', 'created_by', 'project_id', 'column_id', 'password', 'assignee_id'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }
});

// ── Kind-aware status (§15) ───────────────────────────────────────────────────

it('derives a project-board row\'s status from its column, never its raw status field', function () {
    $doneRaw = makeTask($this->todo, ['title' => 'Board task', 'status' => 'done', 'assignee_id' => $this->admin->id]);
    $inDoneColumn = makeTask($this->done, ['title' => 'In done column', 'status' => 'todo', 'assignee_id' => $this->admin->id]);

    $rows = collect(taskListProps($this->actingAs($this->admin)->get(route('tasks.index')))['tasks']['data'])->keyBy('title');

    expect($rows['Board task']['status'])->toBe(['label' => 'To Do', 'done' => false, 'source' => 'column']);
    expect($rows['In done column']['status'])->toBe(['label' => 'Done', 'done' => true, 'source' => 'column']);
});

it('derives a standalone row\'s status from its raw status field', function () {
    Task::factory()->standalone()->create(['title' => 'Loose in progress', 'status' => 'in_progress', 'assignee_id' => $this->admin->id]);
    Task::factory()->standalone()->create(['title' => 'Loose done', 'status' => 'done', 'assignee_id' => $this->admin->id]);

    $rows = collect(taskListProps($this->actingAs($this->admin)->get(route('tasks.index')))['tasks']['data'])->keyBy('title');

    expect($rows['Loose in progress']['status'])->toBe(['label' => 'In Progress', 'done' => false, 'source' => 'status']);
    expect($rows['Loose done']['status'])->toBe(['label' => 'Done', 'done' => true, 'source' => 'status']);
});

it('derives a ticket row\'s status from its raw status field, never board completion semantics', function () {
    $ticket = Ticket::factory()->create(['user_id' => $this->admin->id, 'ticket_number' => 'TKT-9002']);
    Task::factory()->standalone()->create(['title' => 'Ticket work', 'ticket_id' => $ticket->id, 'status' => 'done', 'assignee_id' => $this->admin->id]);

    $rows = collect(taskListProps($this->actingAs($this->admin)->get(route('tasks.index')))['tasks']['data'])->keyBy('title');

    expect($rows['Ticket work']['status'])->toBe(['label' => 'Done', 'done' => true, 'source' => 'status']);
    expect($rows['Ticket work']['context']['kind'])->toBe('ticket');
});

// ── context.kind mapping ──────────────────────────────────────────────────────

it('reports context.kind matching each row\'s actual task kind', function () {
    $ticket = Ticket::factory()->create(['user_id' => $this->admin->id, 'ticket_number' => 'TKT-9003']);
    makeTask($this->todo, ['title' => 'Board row', 'assignee_id' => $this->admin->id]);
    Task::factory()->standalone()->create(['title' => 'Standalone row', 'assignee_id' => $this->admin->id]);
    Task::factory()->standalone()->create(['title' => 'Ticket row', 'ticket_id' => $ticket->id, 'assignee_id' => $this->admin->id]);

    $rows = collect(taskListProps($this->actingAs($this->admin)->get(route('tasks.index')))['tasks']['data'])->keyBy('title');

    expect($rows['Board row']['context']['kind'])->toBe('project');
    expect($rows['Standalone row']['context']['kind'])->toBe('standalone');
    expect($rows['Ticket row']['context']['kind'])->toBe('ticket');
});

// ── Vocabulary (§15: server is the source of truth) ──────────────────────────

it('exposes labelled priority and status vocabulary for the standalone create form', function () {
    $props = taskListProps($this->actingAs($this->admin)->get(route('tasks.index')));

    expect(collect($props['createOptions']['priorities'])->all())->toBe([
        ['value' => 'low', 'label' => 'Low'],
        ['value' => 'medium', 'label' => 'Medium'],
        ['value' => 'high', 'label' => 'High'],
        ['value' => 'critical', 'label' => 'Critical'],
    ]);
    expect(collect($props['createOptions']['statuses'])->all())->toBe([
        ['value' => 'todo', 'label' => 'To Do'],
        ['value' => 'in_progress', 'label' => 'In Progress'],
        ['value' => 'done', 'label' => 'Done'],
    ]);
});

// ── An unrecognized `view` value behaves as "mine" (a closed two-value enum) ─

it('treats an unrecognized view value as "mine" rather than erroring', function () {
    makeTask($this->todo, ['title' => 'Mine', 'assignee_id' => $this->admin->id]);

    $response = $this->actingAs($this->admin)->get(route('tasks.index', ['view' => 'bogus']))->assertOk();

    expect(taskListProps($response)['view'])->toBe('mine');
});
