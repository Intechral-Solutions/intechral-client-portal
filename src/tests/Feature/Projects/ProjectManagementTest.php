<?php

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

// ── Guests ───────────────────────────────────────────────────────────────────

it('redirects guests from projects index to login', function () {
    $this->get(route('projects.index'))->assertRedirect('/login');
});

it('redirects guests from project create to login', function () {
    $this->get(route('projects.create'))->assertRedirect('/login');
});

// ── Access control ───────────────────────────────────────────────────────────

it('allows any authenticated user to view the project list', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)->get(route('projects.index'))->assertOk();
});

it('forbids users without projects.manage from creating projects', function () {
    $user = User::factory()->create();
    $user->assignRole('user'); // only has projects.view

    $this->actingAs($user)->get(route('projects.create'))->assertForbidden();
});

it('allows operators to access the create form', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->get(route('projects.create'))->assertOk();
});

// ── Project creation ─────────────────────────────────────────────────────────

it('creates a project with default columns', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->post(route('projects.store'), [
        'name' => 'Test Project Alpha',
        'status' => 'active',
    ])->assertRedirect();

    $project = Project::where('name', 'Test Project Alpha')->first();
    expect($project)->not->toBeNull();
    expect($project->created_by)->toBe($operator->id);

    // Default 5 columns seeded
    expect($project->columns()->count())->toBe(5);

    // Creator is added as manager
    expect(
        $project->members()
            ->where('user_id', $operator->id)
            ->where('role', 'manager')
            ->exists()
    )->toBeTrue();
});

it('validates required name on project store', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->post(route('projects.store'), [
        'status' => 'active',
    ])->assertSessionHasErrors('name');
});

it('validates target_date must be after start_date', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->post(route('projects.store'), [
        'name' => 'Date Test',
        'start_date' => '2026-06-01',
        'target_date' => '2026-05-01',
    ])->assertSessionHasErrors('target_date');
});

// ── Viewing a project ─────────────────────────────────────────────────────────

it('allows project members to view the board', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Board Test']);

    $member = User::factory()->create();
    $member->assignRole('user');
    $project->members()->attach($member->id, ['role' => 'member']);

    $this->actingAs($member)->get(route('projects.board', $project))->assertOk();
});

it('returns 403 for non-members viewing a board', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Private Project']);

    $outsider = User::factory()->create();
    $outsider->assignRole('user');

    $this->actingAs($outsider)->get(route('projects.board', $project))->assertForbidden();
});

it('allows projects.admin to view any project board', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Admin Test']);

    $admin = User::factory()->create();
    $admin->assignRole('operator');

    $this->actingAs($admin)->get(route('projects.board', $project))->assertOk();
});

// ── Editing a project ─────────────────────────────────────────────────────────

it('allows the project manager to edit the project', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Original Name']);

    $this->actingAs($operator)->put(route('projects.update', $project), [
        'name' => 'Updated Name',
        'status' => 'on_hold',
    ])->assertRedirect();

    expect($project->fresh()->name)->toBe('Updated Name');
    expect($project->fresh()->status)->toBe('on_hold');
});

it('forbids regular members from editing project details', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'My Project']);

    $member = User::factory()->create();
    $member->assignRole('user');
    $project->members()->attach($member->id, ['role' => 'member']);

    $this->actingAs($member)->put(route('projects.update', $project), [
        'name' => 'Hacked Name',
        'status' => 'active',
    ])->assertForbidden();
});

// ── Member sync ───────────────────────────────────────────────────────────────

it('syncs project members', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Member Sync Test']);

    $newMember = User::factory()->create();
    $newMember->assignRole('user');

    $this->actingAs($operator)->put(route('projects.members.sync', $project), [
        'members' => [
            ['user_id' => $operator->id, 'role' => 'manager'],
            ['user_id' => $newMember->id, 'role' => 'member'],
        ],
    ])->assertRedirect();

    expect($project->members()->count())->toBe(2);
    expect(
        $project->members()
            ->where('user_id', $newMember->id)
            ->where('role', 'member')
            ->exists()
    )->toBeTrue();
});

it('always preserves the creator as manager after member sync', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Creator Test']);

    $other = User::factory()->create();
    $other->assignRole('user');

    // Sync with creator demoted to member
    app(ProjectService::class)->syncMembers($project, [
        ['user_id' => $operator->id, 'role' => 'member'],
        ['user_id' => $other->id, 'role' => 'manager'],
    ]);

    // Creator must still be manager
    expect(
        $project->members()
            ->where('user_id', $operator->id)
            ->where('role', 'manager')
            ->exists()
    )->toBeTrue();
});

// ── Project deletion ──────────────────────────────────────────────────────────

it('allows the project manager to delete the project', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Deletable']);

    $this->actingAs($operator)->delete(route('projects.destroy', $project))
        ->assertRedirect(route('projects.index'));

    expect(Project::find($project->id))->toBeNull();
});

// ── Project index only shows member projects ──────────────────────────────────

it('shows only the projects the user is a member of (non-admin)', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $visible = app(ProjectService::class)->create($operator, ['name' => 'Visible']);
    $invisible = app(ProjectService::class)->create($operator, ['name' => 'Invisible']);

    $member = User::factory()->create();
    $member->assignRole('user');
    $visible->members()->attach($member->id, ['role' => 'member']);

    $response = $this->actingAs($member)->get(route('projects.index'));
    $response->assertOk();
    $response->assertSee('Visible');
    $response->assertDontSee('Invisible');
});
