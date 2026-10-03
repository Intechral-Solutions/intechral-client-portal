<?php

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-014 §7.3, §13.3, R6 (WP5): the candidate source for single-row assignment from the Tasks list.
 *
 * The list sends one page-level prop, `assigneeOptions`:
 *   - `self`: the actor, the only person a standalone task may be handed to;
 *   - `projects`: for each project that has a row the actor may assign on this page, that
 *     project's CURRENT members: exactly ProjectTaskAssignee's candidate set, from ONE query.
 *
 * It is security-sensitive. Nobody is named merely to populate a select: no `User::all()`, no
 * members of a project the actor cannot manage a row in, no departed assignee as a new candidate,
 * no email. Authority stays with TaskPolicy / ProjectTaskAssignee on the assignee endpoint.
 */

function optionProps($response): array
{
    return $response->viewData('page')['props']['assigneeOptions'];
}

function projectMembers(array $options, int $projectId): ?array
{
    $entry = collect($options['projects'])->firstWhere('projectId', $projectId);

    return $entry === null ? null : collect($entry['members'])->pluck('name')->all();
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = makeUser('operator', ['name' => 'Olive Operator']);
    $this->manager = makeUser('user', ['name' => 'Mona Manager']);
    $this->manager->givePermissionTo('projects.manage');
    $this->project = makeProject($this->operator, 'Managed Venture');
    $this->project->members()->attach($this->manager->id, ['role' => 'manager']);
    $this->ann = makeUser('user', ['name' => 'Ann Member']);
    $this->bob = makeUser('user', ['name' => 'Bob Member']);
    $this->project->members()->attach([$this->ann->id => ['role' => 'member'], $this->bob->id => ['role' => 'member']]);
    $this->outsider = makeUser('user', ['name' => 'Otto Outsider']);
    $this->mine = makeTask($this->project->columns[1], ['title' => 'Managed row', 'assignee_id' => $this->manager->id]);
});

it('names the actor as the standalone candidate and nobody else', function () {
    Task::factory()->standalone()->create(['created_by' => $this->manager->id, 'assignee_id' => $this->manager->id, 'title' => 'Loose']);

    $options = optionProps($this->actingAs($this->manager)->get(route('tasks.index'))->assertOk());

    expect($options['self'])->toBe(['id' => $this->manager->id, 'name' => 'Mona Manager']);
});

it('lists the current members of a project the actor may assign in, by name and id only', function () {
    $options = optionProps($this->actingAs($this->manager)->get(route('tasks.index'))->assertOk());

    expect(projectMembers($options, $this->project->id))->toBe(['Ann Member', 'Bob Member', 'Mona Manager', 'Olive Operator'])
        ->and(json_encode($options))->not->toContain('@')->not->toContain('Otto Outsider');

    foreach ($options['projects'][0]['members'] as $member) {
        expect(array_keys($member))->toBe(['id', 'name']);
    }
});

it('offers no project entry for a row the actor may not assign: a plain member\'s own task', function () {
    $this->mine->update(['assignee_id' => $this->ann->id]);

    $options = optionProps($this->actingAs($this->ann)->get(route('tasks.index'))->assertOk());

    // Ann sees the row (member-assignee may Complete/Reopen) but may not assign it (Q1), so the page
    // discloses no member list for that project to her.
    expect($options['projects'])->toBe([])->and(json_encode($options))->not->toContain('Bob Member');
});

it('never discloses members of a project whose rows the actor sees but cannot manage', function () {
    $other = makeProject($this->operator, 'Other Venture');
    $other->members()->attach([$this->manager->id => ['role' => 'member']]);
    $other->members()->attach($this->outsider->id, ['role' => 'member']);
    makeTask($other->columns[1], ['title' => 'Other row', 'assignee_id' => $this->manager->id]);

    $options = optionProps($this->actingAs($this->manager)->get(route('tasks.index'))->assertOk());

    expect(projectMembers($options, $other->id))->toBeNull()
        ->and(projectMembers($options, $this->project->id))->not->toBeNull()
        ->and(json_encode($options))->not->toContain('Otto Outsider');
});

it('never offers a departed assignee as a new candidate', function () {
    $former = makeUser('user', ['name' => 'Fay Former']);
    makeTask($this->project->columns[1], ['title' => 'Held by a leaver', 'assignee_id' => $former->id, 'position' => 1]);

    $options = optionProps($this->actingAs($this->manager)->get(route('tasks.index', ['view' => 'all']))->assertOk());

    expect(projectMembers($options, $this->project->id))->not->toContain('Fay Former');
});

it('includes each project once even when many of its rows are assignable', function () {
    foreach (range(1, 5) as $i) {
        makeTask($this->project->columns[1], ['title' => "Row {$i}", 'assignee_id' => $this->manager->id, 'position' => $i]);
    }

    $options = optionProps($this->actingAs($this->manager)->get(route('tasks.index'))->assertOk());

    expect(collect($options['projects'])->where('projectId', $this->project->id))->toHaveCount(1);
});

it('lets an operator assign among the members of any project on the page', function () {
    $options = optionProps($this->actingAs($this->operator)->get(route('tasks.index', ['view' => 'all']))->assertOk());

    expect(projectMembers($options, $this->project->id))->toContain('Ann Member', 'Bob Member');
});

it('does not grow its query count with the number of projects or rows on the page', function () {
    $grow = function (int $n) {
        for ($i = 0; $i < $n; $i++) {
            $project = makeProject($this->operator, "Budget {$i}");
            $project->members()->attach($this->manager->id, ['role' => 'manager']);
            $project->members()->attach(makeUser()->id, ['role' => 'member']);
            makeTask($project->columns[1], ['assignee_id' => $this->manager->id]);
            makeTask($project->columns[1], ['assignee_id' => $this->manager->id, 'position' => 1]);
        }
    };
    $measure = function () {
        $request = fn () => $this->actingAs($this->manager)->get(route('tasks.index'))->assertOk();
        $request();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $grow(3);
    $small = $measure();
    $grow(10);
    $large = $measure();

    // The same one-query tolerance ProjectQueryBudgetTest allows for warm-cache variance between two
    // measurements; growth per project or row would add at least one query for each of the 10 added.
    expect($large)->toBeLessThanOrEqual($small + 1);
});

/** Queries on the page that are the candidate source's project-members-joined-to-users read. */
function memberQueryCount(): int
{
    return collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $sql) => str_contains($sql, 'project_members') && str_contains($sql, 'join `users`'))
        ->count();
}

it('runs exactly one member query when the page has a row the actor may assign (positive control)', function () {
    // The same matcher the +0 case below uses: it must be live, or that case proves nothing.
    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->actingAs($this->manager)->get(route('tasks.index'))->assertOk();
    $matched = memberQueryCount();
    DB::disableQueryLog();

    expect(projectMembers(optionProps($response), $this->project->id))->not->toBeNull()
        ->and($matched)->toBe(1);
});

it('runs no member query at all when the page has no row the actor may assign (+0)', function () {
    // Ann sees the board row as its member-assignee but may not assign it; her standalone row needs
    // no member list either. The candidate query (project_members joined to users) must not run.
    $this->mine->update(['assignee_id' => $this->ann->id]);
    Task::factory()->standalone()->create(['created_by' => $this->ann->id, 'assignee_id' => $this->ann->id]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->actingAs($this->ann)->get(route('tasks.index'))->assertOk();
    $matched = memberQueryCount();
    DB::disableQueryLog();

    expect($response->viewData('page')['props']['tasks']['data'])->toHaveCount(2)
        ->and(optionProps($response)['projects'])->toBe([])
        ->and($matched)->toBe(0);
});

it('sends the options only on the list, never inside a row', function () {
    $props = $this->actingAs($this->manager)->get(route('tasks.index'))->assertOk()->viewData('page')['props'];

    expect($props)->toHaveKey('assigneeOptions')
        // `->not->toContain('assigneeOptions', 'options')` passed unless BOTH keys were present, so
        // one leaked key could not fail it (EPIC-015 review F5): the intersection must be empty.
        ->and(array_values(array_intersect(array_keys($props['tasks']['data'][0]), ['assigneeOptions', 'options'])))->toBe([]);
});
