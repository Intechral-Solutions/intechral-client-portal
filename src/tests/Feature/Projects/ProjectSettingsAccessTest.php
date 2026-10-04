<?php

use App\Http\Presenters\ProjectOverviewPresenter;
use App\Models\Project;
use App\Policies\ProjectSettingsAccess;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-015 WP1 PR B (§7, INV-P16): effective Settings/Edit access, the one shared resolver.
 *
 * Before PR B the A9 conjunction (`ProjectPolicy::manage` AND `projects.manage`) was written inline
 * in two controllers (the Board's `openSettings`, the Milestones page's `manage`), and `projects.edit`
 * enforced it through route middleware plus `authorize('manage')`. PR B extracts
 * `ProjectSettingsAccess` with NO behaviour change.
 *
 *   CHARACTERIZATION — written and run green against `5a92f74` (before the extraction) for the
 *                      route and the two page abilities; still green after it.
 *   NEW CONTRACT     — the resolver and the Overview DTO, which did not exist before.
 *
 * The matrix: for every actor shape, a real `projects.edit` request succeeds  <=>  the resolver
 * allows  <=>  the Board offers Settings  <=>  the Milestones page offers management  <=>  the
 * Overview DTO carries the `budget` key, the `members` roster and `abilities.openSettings`.
 */

function settingsAccessCases(): array
{
    $cases = [];
    foreach (SETTINGS_ACCESS_ACTORS as $kind => $allowed) {
        $cases[$kind] = [$kind, $allowed];
    }

    return $cases;
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->project = makeProject(makeUser('operator', ['name' => 'Owner Olive']), 'Settings parity');
});

it('CHARACTERIZATION: projects.edit, the Board openSettings and the Milestones manage ability agree for every actor', function (string $kind, bool $allowed) {
    $actor = settingsAccessActor($kind, $this->project);

    $edit = $this->actingAs($actor)->get(route('projects.edit', $this->project));
    expect($edit->status())->toBe($allowed ? 200 : 403, "projects.edit as {$kind}");

    $board = $this->actingAs($actor)->get(route('projects.board', $this->project));
    $milestones = $this->actingAs($actor)->get(route('projects.milestones.index', $this->project));

    if ($kind === 'outsider') {
        // Cannot view the project, so neither page (nor its abilities) is reachable.
        $board->assertForbidden();
        $milestones->assertForbidden();

        return;
    }

    $board->assertInertia(fn (Assert $page) => $page->where('abilities.openSettings', $allowed));
    $milestones->assertInertia(fn (Assert $page) => $page->where('abilities.manage', $allowed));
})->with(settingsAccessCases());

it('NEW CONTRACT: ProjectSettingsAccess equals a real projects.edit request for every actor (INV-P16)', function (string $kind, bool $allowed) {
    $actor = settingsAccessActor($kind, $this->project);

    $editSucceeds = $this->actingAs($actor)->get(route('projects.edit', $this->project))->status() === 200;

    expect(ProjectSettingsAccess::allows($actor, $this->project))->toBe($editSucceeds)
        ->and($editSucceeds)->toBe($allowed);
})->with(settingsAccessCases());

it('NEW CONTRACT: the Overview carries budget, roster and the Settings ability if and only if projects.edit succeeds', function (string $kind, bool $allowed) {
    $actor = settingsAccessActor($kind, $this->project);
    $editSucceeds = $this->actingAs($actor)->get(route('projects.edit', $this->project))->status() === 200;

    if ($kind === 'outsider') {
        // The presenter is only ever called after `view`; the outsider never reaches it.
        $this->actingAs($actor)->get(route('projects.show', $this->project))->assertForbidden();
        expect($editSucceeds)->toBeFalse();

        return;
    }

    $dto = ProjectOverviewPresenter::overview($this->project->fresh(), $actor->fresh());

    // Key presence, not value equality: the budget here is NULL, so only the key tells them apart.
    expect($this->project->fresh()->budget)->toBeNull()
        ->and(array_key_exists('budget', $dto))->toBe($editSucceeds)
        ->and(array_key_exists('members', $dto))->toBe($editSucceeds)
        ->and(array_key_exists('openSettings', $dto['abilities']))->toBe($editSucceeds)
        ->and($editSucceeds)->toBe($allowed);
})->with(settingsAccessCases());

it('NEW CONTRACT: the Board and Milestones abilities equal the resolver, actor by actor', function (string $kind, bool $allowed) {
    if ($kind === 'outsider') {
        expect(ProjectSettingsAccess::allows(settingsAccessActor($kind, $this->project), $this->project))->toBeFalse();

        return;
    }
    $actor = settingsAccessActor($kind, $this->project);
    $resolver = ProjectSettingsAccess::allows($actor, $this->project);

    $this->actingAs($actor)->get(route('projects.board', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('abilities.openSettings', $resolver));
    $this->actingAs($actor)->get(route('projects.milestones.index', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('abilities.manage', $resolver));
    expect($resolver)->toBe($allowed);
})->with(settingsAccessCases());

it('NEW CONTRACT: the resolver answers for the actor it is given, not the authenticated user', function () {
    $admin = settingsAccessActor('admin', $this->project);
    $customer = settingsAccessActor('customer_member', $this->project);

    $this->actingAs($admin);
    expect(ProjectSettingsAccess::allows($customer, $this->project))->toBeFalse()
        ->and(ProjectSettingsAccess::allows($admin, $this->project))->toBeTrue();

    $this->actingAs($customer);
    expect(ProjectSettingsAccess::allows($admin, $this->project))->toBeTrue();
});

it('NEW CONTRACT: a manager of one project has no Settings access on another', function () {
    $other = makeProject(null, 'Other');
    $manager = settingsAccessActor('project_manager', $this->project);

    expect(ProjectSettingsAccess::allows($manager, $this->project))->toBeTrue()
        ->and(ProjectSettingsAccess::allows($manager, $other))->toBeFalse();
    $this->actingAs($manager)->get(route('projects.edit', $other))->assertForbidden();
});
