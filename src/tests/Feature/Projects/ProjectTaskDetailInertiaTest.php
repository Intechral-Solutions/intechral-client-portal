<?php

use App\Models\TimeEntry;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-011E WP7: the task detail page as an Inertia/React page. §5's TaskDetail and
 * TaskTimeSummary DTOs, §11's manager edit form (including I4 milestone assignment and I8's
 * ex-member assignee), §13's comments, §14's checklist (D5), and §11/D6's time panel are pinned
 * here. Route authorization itself stays pinned by the WP1 actor-by-route matrix and the
 * pinned-behavior/integrity suites (server-side validation of milestone/assignee ids is pinned
 * in ProjectIntegrityTest.php); these tests are about what the page is given and how the DTOs
 * shape that once React owns the page.
 *
 * Replaces the two Blade-specific assertions ProjectBladeRegressionTest.php pinned for
 * tasks/show.blade.php, deleted in this work package.
 */

function taskPageProps($response): array
{
    return $response->viewData('page')['props'];
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->admin = makeUser('operator', ['name' => 'Ada Admin']);
    $this->project = makeProject($this->admin, 'Alpha');
    $this->manager = projectActor('project_manager', $this->project);
    $this->todo = $this->project->columns[1];
    $this->done = $this->project->columns[4];
});

// ── Page contract ─────────────────────────────────────────────────────────────

it('renders the task page as the projects/tasks/show component with a minimal DTO', function () {
    $milestone = $this->project->milestones()->create(['name' => 'Beta', 'due_date' => '2030-01-01']);
    $assignee = projectActor('member', $this->project);
    $task = makeTask($this->todo, [
        'title' => 'Ship it', 'description' => 'Do the thing.', 'priority' => 'high',
        'due_date' => today()->addWeek(), 'assignee_id' => $assignee->id, 'milestone_id' => $milestone->id,
    ]);

    $response = $this->actingAs($this->manager)
        ->get(route('projects.tasks.show', [$this->project, $task]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/tasks/show')
            ->where('project', ['id' => $this->project->id, 'name' => 'Alpha'])
            ->where('task.id', $task->id)
            ->where('task.title', 'Ship it')
            ->where('task.assignee', ['id' => $assignee->id, 'name' => $assignee->name])
            ->where('task.assigneeIsMember', true)
            ->where('task.milestone', ['id' => $milestone->id, 'name' => 'Beta'])
            ->where('task.status', ['label' => 'To Do', 'done' => false, 'source' => 'column'])
            ->where('task.column', ['id' => $this->todo->id, 'name' => 'To Do', 'isDone' => false])
            ->where('abilities.manage', true)
            ->where('abilities.comment', true)
            ->where('abilities.toggleChecklist', true)
            ->has('options.members')
            ->has('options.milestones')
            ->has('options.priorities', 4));

    $props = taskPageProps($response);
    expect(array_keys($props['task']))->toBe([
        'id', 'title', 'description', 'priority', 'dueDate', 'overdue',
        'status', 'column', 'assignee', 'assigneeIsMember', 'milestone',
    ]);
});

it('omits edit options for a member and keeps the collaborative abilities on', function () {
    $task = makeTask($this->todo);
    $member = projectActor('member', $this->project);

    $this->actingAs($member)->get(route('projects.tasks.show', [$this->project, $task]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('abilities.manage', false)
            ->where('abilities.comment', true)
            ->where('abilities.toggleChecklist', true)
            ->where('options', null));
});

it('denies an outsider the task page', function () {
    $task = makeTask($this->todo);

    $this->actingAs(makeUser())
        ->get(route('projects.tasks.show', [$this->project, $task]))
        ->assertForbidden();
});

it('derives abilities.manage (and whether edit options exist) from the manage policy per actor (D1)', function (string $actor, bool $expected) {
    $task = makeTask($this->todo);
    $user = projectActor($actor, $this->project, $task);

    $this->actingAs($user)->get(route('projects.tasks.show', [$this->project, $task]))
        ->assertInertia(function (Assert $page) use ($expected) {
            $page->where('abilities.manage', $expected);
            $expected ? $page->has('options') : $page->where('options', null);
        });
})->with([
    'plain member' => ['member', false],
    'manager pivot role without permission' => ['manager_role', false],
    'project manager' => ['project_manager', true],
    'administrator (all permissions)' => ['admin', true],
    // Structural task routes authorize on the policy alone, no projects.manage route
    // middleware (D1), so projects.admin alone must be offered the edit form too.
    'projects.admin alone' => ['admin_only', true],
]);

it('derives the effective status from the column, never a raw status string (§15)', function () {
    $task = makeTask($this->done, ['status' => 'todo']);

    $this->actingAs($this->manager)->get(route('projects.tasks.show', [$this->project, $task]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.status', ['label' => 'Done', 'done' => true, 'source' => 'column'])
            ->where('task.column', ['id' => $this->done->id, 'name' => 'Done', 'isDone' => true]));
});

it('never exposes an email, raw model attribute, or unrelated id on the task page', function () {
    $assignee = projectActor('member', $this->project);
    $task = makeTask($this->todo, ['assignee_id' => $assignee->id]);
    $task->comments()->create(['user_id' => $assignee->id, 'body' => 'hi']);
    $task->checklistItems()->create(['title' => 'Step', 'completed' => false, 'position' => 0]);

    // Only this page's own props: the globally shared `auth` prop legitimately carries the
    // viewer's own email for the account menu, which is not this DTO's concern.
    $props = taskPageProps(
        $this->actingAs($this->admin)->get(route('projects.tasks.show', [$this->project, $task]))
    );
    $json = json_encode(array_intersect_key($props, array_flip([
        'project', 'task', 'checklist', 'comments', 'options', 'abilities', 'timeSummary',
    ])));

    foreach (['email', 'created_at', 'updated_at', 'created_by', 'project_id', 'column_id', 'password'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }
});

// ── Assignee and milestone options (I4, I8) ──────────────────────────────────

it('keeps a departed assignee visible without adding them to the reusable candidate pool (I8)', function () {
    $former = projectActor('member', $this->project);
    $task = makeTask($this->todo, ['assignee_id' => $former->id]);
    $this->project->members()->detach($former->id);

    $response = $this->actingAs($this->manager)
        ->get(route('projects.tasks.show', [$this->project, $task]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.assignee', ['id' => $former->id, 'name' => $former->name])
            ->where('task.assigneeIsMember', false));

    $memberIds = collect(taskPageProps($response)['options']['members'])->pluck('id');
    expect($memberIds)->not->toContain($former->id);
});

it("offers only this project's milestones and members as edit options (A2, A3)", function () {
    $foreign = makeProject(null, 'Other');
    $foreign->milestones()->create(['name' => 'Foreign MS', 'due_date' => '2030-01-01']);
    $this->project->milestones()->create(['name' => 'Own MS', 'due_date' => '2030-01-01']);
    $member = projectActor('member', $this->project);
    $task = makeTask($this->todo);

    $props = taskPageProps(
        $this->actingAs($this->manager)->get(route('projects.tasks.show', [$this->project, $task]))
    );

    expect(collect($props['options']['milestones'])->pluck('name')->all())->toBe(['Own MS']);

    $memberIds = collect($props['options']['members'])->pluck('id')->sort()->values()->all();
    $expectedIds = collect([$this->admin->id, $this->manager->id, $member->id])->sort()->values()->all();
    expect($memberIds)->toBe($expectedIds);
});

// ── Checklist and comments DTOs (D5, §13) ────────────────────────────────────

it('exposes checklist items with id, title, and completed only (D5)', function () {
    $task = makeTask($this->todo);
    $task->checklistItems()->create(['title' => 'Step one', 'completed' => true, 'position' => 0]);

    $props = taskPageProps(
        $this->actingAs($this->manager)->get(route('projects.tasks.show', [$this->project, $task]))
    );

    expect($props['checklist'])->toHaveCount(1);
    expect(array_keys($props['checklist'][0]))->toBe(['id', 'title', 'completed']);
    expect($props['checklist'][0])->toMatchArray(['title' => 'Step one', 'completed' => true]);
});

it('exposes comments as plain text with author id/name only, oldest first (§13)', function () {
    $member = projectActor('member', $this->project);
    $task = makeTask($this->todo);
    $task->comments()->create(['user_id' => $member->id, 'body' => '<script>alert(1)</script>']);
    $this->travel(1)->minutes();
    $task->comments()->create(['user_id' => $this->manager->id, 'body' => 'second']);

    $props = taskPageProps(
        $this->actingAs($this->manager)->get(route('projects.tasks.show', [$this->project, $task]))
    );

    expect($props['comments'])->toHaveCount(2);
    // Stored and served raw: React's own escaping, not the server, is the XSS boundary.
    expect($props['comments'][0]['body'])->toBe('<script>alert(1)</script>');
    expect($props['comments'][1]['body'])->toBe('second');
    expect(array_keys($props['comments'][0]))->toBe(['id', 'body', 'createdAt', 'author']);
    expect($props['comments'][0]['author'])->toBe(['id' => $member->id, 'name' => $member->name]);
});

// ── Task time panel (D6) ──────────────────────────────────────────────────────

it('shows the viewer only their own time on the task panel unless they hold time.view_all', function () {
    $task = makeTask($this->todo, ['title' => 'Timed']);
    $member = projectActor('member', $this->project);
    $other = makeUser('user', ['name' => 'Zed Otherperson']);

    TimeEntry::factory()->create(['user_id' => $member->id, 'task_id' => $task->id, 'duration_minutes' => 90, 'date' => today()]);
    TimeEntry::factory()->create(['user_id' => $other->id, 'task_id' => $task->id, 'duration_minutes' => 45, 'date' => today()]);
    // A running entry is never final; it must not count toward either total.
    TimeEntry::factory()->running()->create(['user_id' => $other->id, 'task_id' => $task->id]);
    $url = route('projects.tasks.show', [$this->project, $task]);

    $ownProps = taskPageProps($this->actingAs($member)->get($url)->assertOk());
    expect($ownProps['timeSummary']['scope'])->toBe('own');
    expect($ownProps['timeSummary']['totalMinutes'])->toBe(90);
    expect($ownProps['timeSummary']['entries'])->toHaveCount(1);
    expect($ownProps['timeSummary']['entries'][0])->not->toHaveKey('userName');

    // Project-manager status grants nothing extra (D6).
    $managerProps = taskPageProps($this->actingAs($this->manager)->get($url)->assertOk());
    expect($managerProps['timeSummary']['scope'])->toBe('own');
    expect($managerProps['timeSummary']['totalMinutes'])->toBe(0);

    $allProps = taskPageProps($this->actingAs($this->admin)->get($url)->assertOk());
    expect($allProps['timeSummary']['scope'])->toBe('all');
    expect($allProps['timeSummary']['totalMinutes'])->toBe(135);
    $names = collect($allProps['timeSummary']['entries'])->pluck('userName')->sort()->values()->all();
    expect($names)->toBe(collect([$member->name, 'Zed Otherperson'])->sort()->values()->all());
});

it('shows the all-users summary without a logTime ability to a time.view_all holder lacking time.log', function () {
    $task = makeTask($this->todo);
    TimeEntry::factory()->create(['user_id' => $this->manager->id, 'task_id' => $task->id, 'duration_minutes' => 30, 'date' => today()]);

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('time.view_all');
    $this->project->members()->attach($viewer->id, ['role' => 'member']);

    $props = taskPageProps(
        $this->actingAs($viewer)->get(route('projects.tasks.show', [$this->project, $task]))->assertOk()
    );

    expect($props['abilities']['logTime'])->toBeFalse();
    expect($props['timeSummary']['scope'])->toBe('all');
    expect($props['timeSummary']['totalMinutes'])->toBe(30);
});

it('gives a viewer with neither time.log nor time.view_all no time summary', function () {
    $task = makeTask($this->todo);
    $viewer = User::factory()->create();
    $this->project->members()->attach($viewer->id, ['role' => 'member']);

    $props = taskPageProps(
        $this->actingAs($viewer)->get(route('projects.tasks.show', [$this->project, $task]))->assertOk()
    );

    expect($props['abilities']['logTime'])->toBeFalse();
    expect($props['timeSummary'])->toBeNull();
});

it('never exposes billing, invoice, description, or email fields in the time summary', function () {
    $task = makeTask($this->todo);
    TimeEntry::factory()->create([
        'user_id' => $this->manager->id, 'task_id' => $task->id, 'duration_minutes' => 60, 'date' => today(),
        'description' => 'Confidential notes', 'billable' => true, 'billed' => true,
    ]);

    $json = json_encode(taskPageProps(
        $this->actingAs($this->admin)->get(route('projects.tasks.show', [$this->project, $task]))
    )['timeSummary']);

    foreach (['Confidential', 'billed', 'billable', 'invoice', 'email'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }
});
