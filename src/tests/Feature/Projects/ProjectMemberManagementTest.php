<?php

use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-011E D7-B (Amendment 2): only projects.admin may add or remove project members, change
 * member roles, or provide extra initial members. A non-admin project manager keeps every other
 * manage capability but receives no candidate-user directory and cannot mutate membership.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->admin = makeUser('operator', ['name' => 'Ada Admin']);
    $this->project = makeProject($this->admin);
    $this->manager = projectActor('project_manager', $this->project);
    $this->candidate = makeUser('user', ['name' => 'Candy Date', 'email' => 'candy.date@example.test']);
    $this->bystander = makeUser('user', ['name' => 'Bystander Bee', 'email' => 'bystander.bee@example.test']);
});

// ── Member sync ──────────────────────────────────────────────────────────────

it('lets projects.admin sync members', function () {
    $this->actingAs($this->admin)->put(route('projects.members.sync', $this->project), [
        'members' => [
            ['user_id' => $this->admin->id, 'role' => 'manager'],
            ['user_id' => $this->candidate->id, 'role' => 'member'],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($this->project->members()->where('users.id', $this->candidate->id)->value('project_members.role'))->toBe('member');
});

it('lets projects.admin without projects.manage sync members (not coupled to the old route gate)', function () {
    $adminOnly = projectActor('admin_only', $this->project);

    $this->actingAs($adminOnly)->put(route('projects.members.sync', $this->project), [
        'members' => [['user_id' => $this->candidate->id, 'role' => 'manager']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($this->project->members()->whereKey($this->candidate->id)->exists())->toBeTrue();
});

it('refuses member sync to a non-admin project manager and changes nothing', function (string $actor) {
    $user = projectActor($actor, $this->project);
    $before = $this->project->members()->pluck('project_members.role', 'users.id')->all();

    $this->actingAs($user)->put(route('projects.members.sync', $this->project), [
        'members' => [['user_id' => $this->candidate->id, 'role' => 'manager']],
    ])->assertForbidden();

    expect($this->project->members()->pluck('project_members.role', 'users.id')->all())->toBe($before);
})->with(['project_manager', 'manager_role', 'member', 'outsider', 'assignee']);

it('validates member ids, duplicates and roles on sync', function (array $members, string $errorKey) {
    $members = array_map(fn (array $m) => [
        ...$m, 'user_id' => $m['user_id'] === '@candidate' ? $this->candidate->id : $m['user_id'],
    ], $members);

    $this->actingAs($this->admin)->put(route('projects.members.sync', $this->project), ['members' => $members])
        ->assertSessionHasErrors($errorKey);
    expect($this->project->members()->whereKey($this->candidate->id)->exists())->toBeFalse();
})->with([
    'unknown user' => [[['user_id' => 999999, 'role' => 'member']], 'members.0.user_id'],
    'duplicate user' => [[['user_id' => '@candidate', 'role' => 'member'], ['user_id' => '@candidate', 'role' => 'manager']], 'members.0.user_id'],
    'invalid role' => [[['user_id' => '@candidate', 'role' => 'owner']], 'members.0.role'],
    'missing role' => [[['user_id' => '@candidate']], 'members.0.role'],
    'non-numeric id' => [[['user_id' => 'abc', 'role' => 'member']], 'members.0.user_id'],
]);

it('requires the members key to be present', function () {
    $this->actingAs($this->admin)->put(route('projects.members.sync', $this->project), [])
        ->assertSessionHasErrors('members');
});

it('always keeps the creator as a manager', function () {
    $this->actingAs($this->admin)->put(route('projects.members.sync', $this->project), [
        'members' => [['user_id' => $this->candidate->id, 'role' => 'member']],
    ])->assertRedirect();
    expect($this->project->members()->where('users.id', $this->admin->id)->value('project_members.role'))->toBe('manager');

    $this->actingAs($this->admin)->put(route('projects.members.sync', $this->project), [
        'members' => [['user_id' => $this->admin->id, 'role' => 'member']],
    ])->assertRedirect();
    expect($this->project->members()->where('users.id', $this->admin->id)->value('project_members.role'))->toBe('manager');
});

it('does not let a project update smuggle membership changes', function () {
    $this->actingAs($this->manager)->put(route('projects.update', $this->project), [
        'name' => 'Renamed', 'status' => 'active',
        'members' => [['user_id' => $this->candidate->id, 'role' => 'manager']],
    ])->assertRedirect();

    expect($this->project->fresh()->name)->toBe('Renamed')
        ->and($this->project->members()->whereKey($this->candidate->id)->exists())->toBeFalse();
});

// ── Create ───────────────────────────────────────────────────────────────────

it('lets projects.admin create a project with extra initial members', function () {
    $this->actingAs($this->admin)->post(route('projects.store'), [
        'name' => 'With Team', 'status' => 'active',
        'members' => [['user_id' => $this->candidate->id, 'role' => 'member']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $project = Project::where('name', 'With Team')->first();
    expect($project->members()->pluck('project_members.role', 'users.id')->all())
        ->toBe([$this->admin->id => 'manager', $this->candidate->id => 'member']);
});

it('refuses extra initial members from a non-admin manager and creates nothing', function () {
    $this->actingAs($this->manager)->post(route('projects.store'), [
        'name' => 'Forged Members', 'status' => 'active',
        'members' => [['user_id' => $this->candidate->id, 'role' => 'manager']],
    ])->assertForbidden();

    expect(Project::where('name', 'Forged Members')->exists())->toBeFalse();
});

it('still lets a non-admin manager create a project when no members are supplied', function (array $extra) {
    $this->actingAs($this->manager)->post(route('projects.store'), [
        'name' => 'Solo Project', 'status' => 'active', ...$extra,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $project = Project::where('name', 'Solo Project')->first();
    expect($project->members()->pluck('project_members.role', 'users.id')->all())->toBe([$this->manager->id => 'manager']);
})->with([[[]], [['members' => []]]]);

it('validates initial members from an administrator', function () {
    $this->actingAs($this->admin)->post(route('projects.store'), [
        'name' => 'Bad Members', 'members' => [['user_id' => 999999, 'role' => 'member']],
    ])->assertSessionHasErrors('members.0.user_id');
    $this->actingAs($this->admin)->post(route('projects.store'), [
        'name' => 'Bad Members', 'members' => [
            ['user_id' => $this->candidate->id, 'role' => 'member'],
            ['user_id' => $this->candidate->id, 'role' => 'member'],
        ],
    ])->assertSessionHasErrors('members.0.user_id');
    $this->actingAs($this->admin)->post(route('projects.store'), [
        'name' => 'Bad Members', 'members' => [['user_id' => $this->candidate->id, 'role' => 'boss']],
    ])->assertSessionHasErrors('members.0.role');

    expect(Project::where('name', 'Bad Members')->exists())->toBeFalse();
});

// ── Data minimization ────────────────────────────────────────────────────────

function candidateProps($response): ?array
{
    return $response->viewData('page')['props']['memberCandidates'] ?? null;
}

it('gives projects.admin the candidate directory on create and edit', function () {
    $create = $this->actingAs($this->admin)->get(route('projects.create'))->assertOk();
    $edit = $this->actingAs($this->admin)->get(route('projects.edit', $this->project))->assertOk();

    foreach ([$create, $edit] as $response) {
        $candidates = collect(candidateProps($response));
        expect($candidates->pluck('id')->all())->toContain($this->candidate->id, $this->bystander->id)
            ->and($candidates->first())->toHaveKeys(['id', 'name', 'email'])
            ->and($candidates->firstWhere('id', $this->candidate->id)['email'])->toBe('candy.date@example.test');
    }
});

it('gives a non-admin manager no candidate directory, no emails and no member controls', function () {
    $create = $this->actingAs($this->manager)->get(route('projects.create'))->assertOk();
    $edit = $this->actingAs($this->manager)->get(route('projects.edit', $this->project))->assertOk();

    foreach ([$create, $edit] as $response) {
        $props = $response->viewData('page')['props'];
        // Absent, not empty: the prop key itself does not exist.
        expect($props)->not->toHaveKey('memberCandidates')
            ->and($props['abilities']['editMembers'])->toBeFalse();

        // The whole serialized page, including the shared props, names no other user.
        $json = json_encode($props);
        foreach (['candy.date@example.test', 'bystander.bee@example.test', 'Candy Date', 'Bystander Bee'] as $leak) {
            expect($json)->not->toContain($leak);
        }
    }
    expect($create->viewData('page')['props'])->not->toHaveKey('members');
});

it('shows a non-admin manager the current members read-only with name, role and owner marker only', function () {
    $this->project->members()->attach($this->candidate->id, ['role' => 'member']);

    $edit = $this->actingAs($this->manager)->get(route('projects.edit', $this->project))->assertOk();

    $members = collect($edit->viewData('page')['props']['members']);
    expect($members->map(fn ($m) => collect($m)->only(['id', 'name', 'role', 'isOwner'])->all())->sortBy('id')->values()->all())
        ->toBe(collect([
            ['id' => $this->admin->id, 'name' => 'Ada Admin', 'role' => 'manager', 'isOwner' => true],
            ['id' => $this->manager->id, 'name' => $this->manager->name, 'role' => 'manager', 'isOwner' => false],
            ['id' => $this->candidate->id, 'name' => 'Candy Date', 'role' => 'member', 'isOwner' => false],
        ])->sortBy('id')->values()->all())
        ->and($members->every(fn ($m) => array_keys((array) $m) === ['id', 'name', 'role', 'isOwner']))->toBeTrue()
        ->and($members->first()['isOwner'])->toBeTrue();   // the owner is listed first
});

it('keeps the rest of the edit page working for a non-admin manager', function () {
    $this->actingAs($this->manager)->get(route('projects.edit', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/edit')
            ->where('project.id', $this->project->id)
            ->where('abilities.delete', true));

    $this->actingAs($this->manager)->put(route('projects.update', $this->project), ['name' => 'Manager Rename', 'status' => 'on_hold'])
        ->assertRedirect();
    $this->actingAs($this->manager)->put(route('projects.companies.sync', $this->project), ['companies' => []])->assertRedirect();
    expect($this->project->fresh()->name)->toBe('Manager Rename');
});

it('refuses a forged member sync from the manager who can otherwise edit the page', function () {
    $this->actingAs($this->manager)->withHeaders(['X-Inertia' => 'true'])
        ->put(route('projects.members.sync', $this->project), [
            'members' => [['user_id' => $this->bystander->id, 'role' => 'manager']],
        ])->assertForbidden();

    expect($this->project->members()->whereKey($this->bystander->id)->exists())->toBeFalse();
});
