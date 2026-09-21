<?php

use App\Models\CrmCompany;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-011E WP1 (D2): the dashboard "Active Projects" figure counts exactly the active projects
 * ProjectPolicy::view lets the viewer open, the same set the projects index lists. It used to
 * count every active project for anyone holding projects.manage, which disagreed with the index
 * and disclosed how many projects exist that the viewer cannot see.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->owner = makeUser('operator');
});

function dashboardProjectCount(User $user): int
{
    $count = null;

    test()->actingAs($user)->get(route('dashboard'))
        ->assertInertia(function (Assert $page) use (&$count) {
            $page->where('metrics', function ($metrics) use (&$count) {
                $count = collect($metrics)->firstWhere('key', 'projects')['value'] ?? null;

                return true;
            });
        });

    return $count;
}

it('counts only the active projects a project manager can open', function () {
    $visible = Project::factory()->active()->create(['created_by' => $this->owner->id]);
    Project::factory()->active()->create(['created_by' => $this->owner->id]); // not theirs
    $manager = projectActor('project_manager', $visible);

    expect(dashboardProjectCount($manager))->toBe(1);
});

it('counts zero for a project manager who belongs to no project', function () {
    Project::factory()->active()->count(3)->create(['created_by' => $this->owner->id]);
    $manager = makeUser();
    $manager->givePermissionTo('projects.manage');

    expect(dashboardProjectCount($manager))->toBe(0);
});

it('counts only the active projects a plain member belongs to', function () {
    $mine = Project::factory()->active()->create(['created_by' => $this->owner->id]);
    Project::factory()->active()->create(['created_by' => $this->owner->id]);
    Project::factory()->archived()->create(['created_by' => $this->owner->id])
        ->members()->attach($member = projectActor('member', $mine), ['role' => 'member']);

    expect(dashboardProjectCount($member))->toBe(1);
});

it('ignores a company link when counting', function () {
    $user = makeUser('user');
    $org = Organization::factory()->create(['owner_id' => $this->owner->id]);
    $org->members()->attach($user, ['role' => 'member']);
    $company = CrmCompany::factory()->create(['created_by' => $this->owner->id, 'organization_id' => $org->id]);
    Project::factory()->active()->create(['created_by' => $this->owner->id])->companies()->attach($company);
    $user->givePermissionTo('projects.manage');

    expect(dashboardProjectCount($user))->toBe(0);
});

it('keeps administrative visibility for projects.admin, with or without projects.manage', function () {
    Project::factory()->active()->count(2)->create(['created_by' => $this->owner->id]);
    Project::factory()->onHold()->create(['created_by' => $this->owner->id]);

    expect(dashboardProjectCount(makeUser('operator')))->toBe(2)
        ->and(dashboardProjectCount(projectActor('admin_only', Project::first())))->toBe(2);
});

it('agrees with the number of active projects on the projects index for every kind of user', function () {
    $project = Project::factory()->active()->create(['created_by' => $this->owner->id]);
    Project::factory()->active()->create(['created_by' => $this->owner->id]);
    Project::factory()->onHold()->create(['created_by' => $this->owner->id]);

    foreach (['outsider', 'member', 'manager_role', 'project_manager', 'admin', 'admin_only'] as $actor) {
        $user = projectActor($actor, $project);
        $indexActive = test()->actingAs($user)->get(route('projects.index'))
            ->viewData('projects')->where('status', 'active')->count();

        expect(dashboardProjectCount($user))->toBe($indexActive, "dashboard vs index for {$actor}");
    }
});
