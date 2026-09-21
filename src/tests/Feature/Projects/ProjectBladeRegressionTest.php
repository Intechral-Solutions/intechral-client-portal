<?php

use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-011E WP1: defects fixed in the Blade pages while they still serve the routes. They are
 * deleted with the views in WP3/WP5/WP7 (their React successors carry equivalent tests).
 */

const HOSTILE_NAMES = [
    'img onerror' => '<img src=x onerror=alert(1)>',
    'select breakout' => '</select><script>alert(1)</script>',
    'quotes' => '"><svg onload=alert(1)>',
];

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->admin = makeUser('operator');
    $this->project = makeProject($this->admin);
    $this->todo = $this->project->columns[1];
});

// ── S2: nested form on the edit page ─────────────────────────────────────────

it('never nests a form inside another form on the edit page', function () {
    $html = $this->actingAs($this->admin)->get(route('projects.edit', $this->project))->assertOk()->getContent();

    expect(formsAreBalanced($html))->toBeTrue()
        ->and(formNestingDepth($html))->toBe(1);
});

it('keeps exactly one PUT _method in the update form and the DELETE form as a sibling', function () {
    $html = $this->actingAs($this->admin)->get(route('projects.edit', $this->project))->assertOk()->getContent();
    preg_match_all('#<form\b([^>]*)>(.*?)</form>#is', $html, $forms, PREG_SET_ORDER);

    // update and destroy share one URL (the verb differs), so tell the forms apart by content
    $update = collect($forms)->first(fn ($form) => str_contains($form[2], 'name="name"'))[2] ?? null;
    $delete = collect($forms)->first(fn ($form) => str_contains($form[2], 'Delete Project'))[2] ?? null;

    expect($update)->not->toBeNull()->and($delete)->not->toBeNull();
    expect(substr_count($update, 'name="_method"'))->toBe(1)
        ->and($update)->toMatch('#name="_method" value="PUT"#')
        ->and($update)->not->toContain('value="DELETE"')
        ->and($update)->not->toContain('Delete Project')
        ->and(substr_count($delete, 'name="_method"'))->toBe(1)
        ->and($delete)->toMatch('#name="_method" value="DELETE"#')
        ->and($delete)->not->toContain('name="name"')
        ->and(collect($forms)->filter(fn ($form) => str_contains($form[1], 'action="'.route('projects.update', $this->project).'"'))->count())->toBe(2);
});

// ── S1: hostile names never reach a string-built DOM ─────────────────────────

it('renders hostile user names only as escaped text in a server-rendered template', function (string $label) {
    $name = HOSTILE_NAMES[$label];
    makeUser('user', ['name' => $name, 'email' => 'hostile@example.test']);

    foreach ([route('projects.create'), route('projects.edit', $this->project)] as $url) {
        $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

        expect($html)->not->toContain($name)                    // never raw
            ->and($html)->not->toContain('innerHTML')           // no client string-built markup
            ->and($html)->not->toContain('${u.')                // no template-literal interpolation
            ->and($html)->toContain(e($name))                   // present, escaped
            ->and($html)->toContain('<template id="member-row-template">');
        // nor inside a hex-escaped JSON blob (the shape the old script consumed)
        expect($html)->not->toContain(chr(92).'u003C'.substr($name, 1, 3));
    }
})->with(array_keys(HOSTILE_NAMES));

it('builds new member rows by cloning the template and never with innerHTML', function () {
    $html = $this->actingAs($this->admin)->get(route('projects.create'))->assertOk()->getContent();

    expect($html)->toContain('template.content.cloneNode(true)')
        ->and($html)->not->toContain('createElement(\'option\')')
        ->and($html)->not->toContain('onclick=');
});

it('pre-fills member rows from old input after a validation failure using escaped output', function () {
    $name = HOSTILE_NAMES['img onerror'];
    $user = makeUser('user', ['name' => $name]);

    $html = $this->actingAs($this->admin)->from(route('projects.create'))->followingRedirects()
        ->post(route('projects.store'), ['name' => '', 'members' => [['user_id' => $user->id, 'role' => 'manager']]])
        ->assertOk()->getContent();

    expect($html)->not->toContain($name)->and($html)->toContain(e($name));
});

// ── D1: Blade offers no structural action to non-managers ────────────────────

it('hides Add task and dragging on the board from non-managers and shows them to managers and admins', function () {
    makeTask($this->todo, ['title' => 'Card']);

    foreach (['member' => false, 'manager_role' => false, 'project_manager' => true, 'admin' => true] as $actor => $sees) {
        $user = projectActor($actor, $this->project);
        $html = $this->actingAs($user)->get(route('projects.board', $this->project))->assertOk()->getContent();

        expect($html)->toContain('Card');
        foreach (['add-task-btn', 'draggable="true"', 'dragstart', 'action="'.route('projects.tasks.store', $this->project).'"'] as $marker) {
            expect(str_contains($html, $marker))->toBe($sees, "{$actor} / {$marker}");
        }
    }
});

it('hides the edit form and delete button on the task page from non-managers', function () {
    $task = makeTask($this->todo, ['title' => 'Detail']);

    foreach (['member' => false, 'manager_role' => false, 'project_manager' => true, 'admin' => true] as $actor => $sees) {
        $user = projectActor($actor, $this->project);
        $html = $this->actingAs($user)->get(route('projects.tasks.show', [$this->project, $task]))->assertOk()->getContent();

        // update and destroy share the task URL with the page's own links, so match the form
        expect(str_contains($html, 'action="'.route('projects.tasks.update', [$this->project, $task]).'"'))->toBe($sees, $actor)
            ->and(str_contains($html, 'Delete Task'))->toBe($sees, $actor);
        // comments stay open to every member
        expect($html)->toContain('action="'.route('projects.tasks.comments.store', [$this->project, $task]).'"');
    }
});

// ── D4: the blocked delete is shown ──────────────────────────────────────────

it('shows the recorded-time error on the task page and the project edit page', function () {
    $manager = projectActor('project_manager', $this->project);
    $task = makeTask($this->todo);
    TimeEntry::factory()->create(['user_id' => makeUser()->id, 'task_id' => $task->id]);

    $this->actingAs($manager)->from(route('projects.tasks.show', [$this->project, $task]))->followingRedirects()
        ->delete(route('projects.tasks.destroy', [$this->project, $task]))
        ->assertOk()->assertSee('This task has recorded time and cannot be deleted.');

    $this->actingAs($manager)->from(route('projects.edit', $this->project))->followingRedirects()
        ->delete(route('projects.destroy', $this->project))
        ->assertOk()->assertSee('This project has recorded time and cannot be deleted.');
});

// ── D6: the Blade task time panel ────────────────────────────────────────────

it('shows the viewer only their own time on the task panel unless they hold time.view_all', function () {
    $task = makeTask($this->todo, ['title' => 'Timed']);
    $member = projectActor('member', $this->project);
    $manager = projectActor('project_manager', $this->project);
    // Not a project member, so the name can only come from the time panel (the manager's edit
    // form lists members).
    $other = makeUser('user', ['name' => 'Zed Otherperson']);

    TimeEntry::factory()->create(['user_id' => $member->id, 'task_id' => $task->id, 'duration_minutes' => 90, 'date' => today()]);
    TimeEntry::factory()->create(['user_id' => $other->id, 'task_id' => $task->id, 'duration_minutes' => 45, 'date' => today()]);
    TimeEntry::factory()->running()->create(['user_id' => $other->id, 'task_id' => $task->id]);
    $url = route('projects.tasks.show', [$this->project, $task]);

    $own = $this->actingAs($member)->get($url)->assertOk();
    $own->assertSee('1h 30m')->assertDontSee('Zed Otherperson')->assertDontSee('2h 15m');

    // Project-manager status grants nothing extra.
    $this->actingAs($manager)->get($url)->assertOk()
        ->assertDontSee('Zed Otherperson')->assertDontSee('1h 30m')->assertDontSee('2h 15m');

    $all = $this->actingAs($this->admin)->get($url)->assertOk();
    $all->assertSee('Zed Otherperson')->assertSee('2h 15m');
});

it('leaves the ticket time panel untouched', function () {
    $owner = makeUser('user');
    $other = makeUser('user', ['name' => 'Ticket Coworker']);
    $ticket = Ticket::factory()->create(['user_id' => $owner->id, 'status' => 'open']);
    TimeEntry::factory()->create(['user_id' => $other->id, 'ticket_id' => $ticket->id, 'duration_minutes' => 60, 'date' => today()]);

    $this->actingAs($owner)->get(route('tickets.show', $ticket))->assertOk()
        ->assertSee('Ticket Coworker')->assertSee('1h');
});

// ── Non-Blade sanity: pages still render for the roles that reach them ───────

it('still renders every project page for an administrator with tasks present', function () {
    $task = makeTask($this->todo, ['title' => 'Everywhere']);
    Task::factory()->standalone()->create(['title' => 'Loose', 'assignee_id' => $this->admin->id]);

    foreach ([
        route('projects.index'), route('projects.board', $this->project), route('projects.milestones.index', $this->project),
        route('projects.tasks.show', [$this->project, $task]), route('tasks.index'),
        route('projects.create'), route('projects.edit', $this->project),
    ] as $url) {
        $this->actingAs($this->admin)->get($url)->assertOk();
    }
});
