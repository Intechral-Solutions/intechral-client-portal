<?php

/*
 * Release 1.0 (ORG-2, CP8): two narrow rules of the Project association writers (ProjectService::create and ::syncOrganizations), at the SERVICE boundary (a controller is not
 * involved, so nothing here can be satisfied by the HTTP layer):
 *
 *   1. A SUSPENDED ACTOR is refused, from the actor's own user row, which the transaction has just locked shared, before any write. A suspended MEMBER of a new Project is not
 *      checked. A narrow tightening, for these two operations, of the in-flight-request rule of docs/architecture/account-lifecycle.md (Info-4).
 *   2. OWNER RULING B-2: a Project link is business metadata and grants no portal authority, but anyone other than an unrestricted operator may create a NEW one only to an
 *      ENABLED Organization. An operator may link any Organization. A retained link and a removal are not new associations, so disabling an Organization neither removes a link
 *      nor stops it being retained or removed. Unknown, hidden and ineligible ids all fail with one message.
 *
 * The two-connection proof of both (suspension and disabling against the writers, each in both orders) is ProjectOrganizationConcurrencyTest (C20, C21).
 */

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\OrganizationRelationships;
use App\Services\ProjectService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/ProjectTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->service = app(ProjectService::class);
    $this->operator = makeUser('operator');
    $this->enabled = Organization::factory()->create(['portal_enabled_by' => $this->operator->id, 'name' => 'Enabled Org']);
    $this->disabled = Organization::factory()->disabled()->create(['portal_enabled_by' => $this->operator->id, 'name' => 'Disabled Org']);
    $this->directory = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id, 'name' => 'Directory Only Org']);

    // A customer who manages projects and belongs to the Enabled AND the Disabled Organization.
    $this->member = makeUser('user');
    $this->member->givePermissionTo('projects.manage');
    $this->enabled->members()->attach($this->member, ['role' => 'member']);
    $this->disabled->members()->attach($this->member, ['role' => 'member']);

    $this->project = Project::factory()->create(['created_by' => $this->operator->id]);
    $this->project->members()->attach($this->member->id, ['role' => 'manager']);
});

function pla_linksOf(Project $project): array
{
    return DB::table('project_organization')->where('project_id', $project->id)->orderBy('organization_id')->pluck('organization_id')->map(fn ($id) => (int) $id)->all();
}

function pla_suspendAccount(User $user): void
{
    $user->forceFill(['disabled_at' => now()])->save();
}

// ── 1. A suspended actor ─────────────────────────────────────────────────────────────────────

it('refuses a suspended operator creating a Project, before any write', function () {
    pla_suspendAccount($this->operator);
    $projects = Project::count();

    expect(fn () => $this->service->create($this->operator, ['name' => 'Suspended Create', 'companies' => [$this->enabled->id]]))->toThrow(AuthorizationException::class);

    expect(Project::count())->toBe($projects)->and(DB::table('project_organization')->count())->toBe(0)->and(DB::table('project_columns')->where('name', 'Suspended Create')->count())->toBe(0);
});

it('refuses a suspended customer creating a Project, with no Project, column, member or link left behind', function () {
    pla_suspendAccount($this->member);
    $projects = Project::count();
    $columns = DB::table('project_columns')->count();
    $members = DB::table('project_members')->count();

    expect(fn () => $this->service->create($this->member, ['name' => 'Suspended Customer Create', 'companies' => [$this->enabled->id]]))->toThrow(AuthorizationException::class);

    expect(Project::count())->toBe($projects)->and(DB::table('project_columns')->count())->toBe($columns)->and(DB::table('project_members')->count())->toBe($members)
        ->and(DB::table('project_organization')->count())->toBe(0);
});

it('refuses a suspended operator synchronizing links, and changes none (the hidden one included)', function () {
    $this->project->organizations()->attach([$this->enabled->id, $this->directory->id]);
    pla_suspendAccount($this->operator);

    expect(fn () => $this->service->syncOrganizations($this->project, $this->operator, []))->toThrow(AuthorizationException::class);
    expect(fn () => $this->service->syncOrganizations($this->project, $this->operator, [$this->disabled->id]))->toThrow(AuthorizationException::class);

    expect(pla_linksOf($this->project))->toBe([$this->enabled->id, $this->directory->id]);
});

it('refuses a suspended customer synchronizing links, and changes none', function () {
    $this->project->organizations()->attach([$this->enabled->id]);
    pla_suspendAccount($this->member);

    expect(fn () => $this->service->syncOrganizations($this->project, $this->member, []))->toThrow(AuthorizationException::class);

    expect(pla_linksOf($this->project))->toBe([$this->enabled->id]);
});

it('is not a check of the members: a suspended MEMBER may be in the new Project, and the active creator is not refused', function () {
    $suspended = makeUser('user');
    pla_suspendAccount($suspended);

    $project = $this->service->create($this->operator, ['name' => 'Suspended Member Project', 'members' => [['user_id' => $suspended->id, 'role' => 'member']]]);

    expect($project->members()->whereKey($suspended->id)->exists())->toBeTrue();
});

it('lets an active actor through (the control for the refusals above)', function () {
    $project = $this->service->create($this->operator, ['name' => 'Active Create', 'companies' => [$this->enabled->id]]);
    $this->service->syncOrganizations($project, $this->operator, [$this->enabled->id, $this->directory->id]);

    expect(pla_linksOf($project))->toBe([$this->enabled->id, $this->directory->id]);
});

it('does not read the actor\'s status from the model the caller passed: a stale in-memory User is still refused', function () {
    $stale = User::find($this->operator->id);   // loaded while active
    User::whereKey($this->operator->id)->update(['disabled_at' => now()]);

    expect($stale->disabled_at)->toBeNull();
    expect(fn () => $this->service->create($stale, ['name' => 'Stale']))->toThrow(AuthorizationException::class);
    expect(fn () => $this->service->syncOrganizations($this->project, $stale, []))->toThrow(AuthorizationException::class);
});

// ── 2. Owner ruling B-2: Disabled and Directory-only Organizations ───────────────────────────

it('lets an operator link an Enabled, a Disabled and a Directory-only Organization to a Project, creating and synchronizing', function () {
    $created = $this->service->create($this->operator, ['name' => 'Operator Links', 'companies' => [$this->enabled->id, $this->disabled->id, $this->directory->id]]);
    $this->service->syncOrganizations($this->project, $this->operator, [$this->disabled->id, $this->directory->id]);

    expect(pla_linksOf($created))->toBe([$this->enabled->id, $this->disabled->id, $this->directory->id])
        ->and(pla_linksOf($this->project))->toBe([$this->disabled->id, $this->directory->id]);
});

it('changes nothing else when an operator links a Directory-only or Disabled Organization: no portal state, no membership, no partner designation, no visibility', function () {
    $before = DB::table('organizations')->whereIn('id', [$this->directory->id, $this->disabled->id])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    $members = DB::table('organization_members')->count();
    $visible = Project::visibleTo($this->member)->pluck('id')->all();

    $this->service->syncOrganizations($this->project, $this->operator, [$this->directory->id, $this->disabled->id]);

    expect(DB::table('organizations')->whereIn('id', [$this->directory->id, $this->disabled->id])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($before)
        ->and(DB::table('organization_members')->count())->toBe($members)
        ->and(Project::visibleTo($this->member)->pluck('id')->all())->toBe($visible);
});

it('lets a non-operator create a NEW link to an Enabled Organization they belong to, creating and synchronizing', function () {
    $created = $this->service->create($this->member, ['name' => 'Member Enabled', 'companies' => [$this->enabled->id]]);
    $this->service->syncOrganizations($this->project, $this->member, [$this->enabled->id]);

    expect(pla_linksOf($created))->toBe([$this->enabled->id])->and(pla_linksOf($this->project))->toBe([$this->enabled->id]);
});

it('refuses a non-operator\'s NEW link to a Disabled Organization they belong to, creating and synchronizing, with the message of an unknown id and nothing written', function () {
    $messages = [];
    $projects = Project::count();

    foreach ([
        fn () => $this->service->create($this->member, ['name' => 'Member Disabled', 'companies' => [$this->disabled->id]]),
        fn () => $this->service->syncOrganizations($this->project, $this->member, [$this->disabled->id]),
        fn () => $this->service->syncOrganizations($this->project, $this->member, [$this->enabled->id, $this->disabled->id]),
        fn () => $this->service->syncOrganizations($this->project, $this->member, [999999]),
        fn () => $this->service->syncOrganizations($this->project, $this->member, [$this->directory->id]),
    ] as $attempt) {
        try {
            $attempt();
            $messages[] = 'no refusal';
        } catch (ValidationException $e) {
            $messages[] = $e->errors();
        }
    }

    // One message for a Disabled Organization, an unknown id and a Directory-only one: the state is not a channel.
    expect(collect($messages)->unique()->all())->toBe([['companies' => ['The selected organization is invalid.']]])
        ->and(Project::count())->toBe($projects)->and(pla_linksOf($this->project))->toBe([]);
});

it('does not treat a retained link as a new association: a non-operator keeps an existing link to an Organization that is now Disabled, and may add an Enabled one beside it', function () {
    $this->project->organizations()->attach([$this->disabled->id]);

    $this->service->syncOrganizations($this->project, $this->member, [$this->disabled->id]);
    expect(pla_linksOf($this->project))->toBe([$this->disabled->id]);

    $this->service->syncOrganizations($this->project, $this->member, [$this->disabled->id, $this->enabled->id]);
    expect(pla_linksOf($this->project))->toBe([$this->enabled->id, $this->disabled->id]);
});

it('lets a non-operator REMOVE their own existing link to a Disabled Organization, and leaves a hidden one alone', function () {
    $this->project->organizations()->attach([$this->disabled->id, $this->directory->id]);

    $this->service->syncOrganizations($this->project, $this->member, []);

    expect(pla_linksOf($this->project))->toBe([$this->directory->id]);
});

it('keeps every existing link when the Organization is disabled', function () {
    $this->project->organizations()->attach([$this->enabled->id]);

    app(OrganizationRelationships::class)->disable($this->enabled, $this->operator);

    expect(pla_linksOf($this->project))->toBe([$this->enabled->id]);
    // ...and the same member who made it may keep it, but can no longer ADD it anew once removed.
    $this->service->syncOrganizations($this->project, $this->member, [$this->enabled->id]);
    expect(pla_linksOf($this->project))->toBe([$this->enabled->id]);
    $this->service->syncOrganizations($this->project, $this->member, []);
    expect(fn () => $this->service->syncOrganizations($this->project, $this->member, [$this->enabled->id]))->toThrow(ValidationException::class);
});

it('answers the HTTP form the same way: a member\'s new link to a Disabled Organization is an error under `companies`, not a success', function () {
    $this->actingAs($this->member)->from(route('projects.edit', $this->project))
        ->put(route('projects.companies.sync', $this->project), ['companies' => [$this->disabled->id]])
        ->assertSessionHasErrors(['companies'])
        ->assertSessionMissing('success');

    expect(pla_linksOf($this->project))->toBe([]);
});
