<?php

/*
 * Release 1.0 (ORG-2, CP3/CP5): the Project to Organization link (`project_organization`), written only by ProjectService (create with initial Organizations, and
 * syncOrganizations). Successor of the CP0 characterization of Project link synchronization (finding A5, recorded in the ORG-2 record): a scoped actor's
 * synchronization used to DETACH a link to an Organization they cannot see, because the submitted list could not name it and the sync treated omission as removal.
 *
 * This file proves the behaviour and the lock SEQUENCE (the user row, then the Organization rows shared in ascending id order, then the project row). The
 * two-connection proof that concurrent writers serialize is CP6; nothing here claims deadlock freedom.
 */

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/ProjectTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = makeUser('operator');
    $this->mine = Organization::factory()->create(['portal_enabled_by' => $this->operator->id, 'name' => 'Mine']);
    $this->theirs = Organization::factory()->create(['portal_enabled_by' => $this->operator->id, 'name' => 'Theirs']);
    $this->directoryOnly = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id, 'name' => 'Directory Only']);

    $this->manager = makeUser('user');
    $this->manager->givePermissionTo('projects.manage');
    $this->mine->members()->attach($this->manager, ['role' => 'member']);
    $this->project = Project::factory()->create(['created_by' => $this->operator->id]);
    $this->project->members()->attach($this->manager->id, ['role' => 'manager']);
});

function linkedTo(Project $project): array
{
    return DB::table('project_organization')->where('project_id', $project->id)->orderBy('organization_id')->pluck('organization_id')->map(fn ($id) => (int) $id)->all();
}

// ── A5, replaced ─────────────────────────────────────────────────────────────────────────────

it('never detaches a link the actor cannot see when a scoped actor saves their own', function () {
    $this->project->organizations()->attach([$this->mine->id, $this->theirs->id, $this->directoryOnly->id]);

    $this->actingAs($this->manager)->put(route('projects.companies.sync', $this->project), ['companies' => [$this->mine->id]])
        ->assertRedirect(route('projects.edit', $this->project));

    expect(linkedTo($this->project))->toBe([$this->mine->id, $this->theirs->id, $this->directoryOnly->id]);
});

it('lets a scoped actor remove and add only the links they own, keeping the hidden ones', function () {
    $other = Organization::factory()->create(['portal_enabled_by' => $this->operator->id]);
    $other->members()->attach($this->manager, ['role' => 'member']);
    $this->project->organizations()->attach([$this->mine->id, $this->theirs->id]);

    $this->actingAs($this->manager)->put(route('projects.companies.sync', $this->project), ['companies' => [$other->id]])->assertRedirect();
    expect(linkedTo($this->project))->toEqualCanonicalizing([$other->id, $this->theirs->id]);

    $this->actingAs($this->manager)->put(route('projects.companies.sync', $this->project), ['companies' => []])->assertRedirect();
    expect(linkedTo($this->project))->toBe([$this->theirs->id], 'clearing the visible set removes only the visible links');
});

it('lets an operator synchronize every eligible link, Directory-only Organizations included', function () {
    $this->project->organizations()->attach([$this->mine->id]);

    $this->actingAs($this->operator)->put(route('projects.companies.sync', $this->project), ['companies' => [$this->theirs->id, $this->directoryOnly->id]])->assertRedirect();

    expect(linkedTo($this->project))->toBe([$this->theirs->id, $this->directoryOnly->id]);
});

it('rejects a hidden Organization exactly as it rejects an unknown id, and writes nothing', function () {
    $this->project->organizations()->attach([$this->mine->id]);
    $before = linkedTo($this->project);

    $hidden = $this->actingAs($this->manager)->put(route('projects.companies.sync', $this->project), ['companies' => [$this->theirs->id]]);
    $unknown = $this->actingAs($this->manager)->put(route('projects.companies.sync', $this->project), ['companies' => [987654]]);

    expect($hidden->getStatusCode())->toBe($unknown->getStatusCode())
        ->and(session()->getOldInput())->toBeArray();
    $hidden->assertSessionHasErrors(['companies.0' => 'The selected organization is invalid.']);
    $unknown->assertSessionHasErrors(['companies.0' => 'The selected organization is invalid.']);
    expect(linkedTo($this->project))->toBe($before);

    // And through the service, which decides it again under the locks: the same failure, the same message.
    foreach ([$this->theirs->id, 987654, 'abc', 0, -3] as $bad) {
        try {
            app(ProjectService::class)->syncOrganizations($this->project, $this->manager, [$bad]);
            $this->fail('an invalid Organization must be refused');
        } catch (ValidationException $e) {
            expect($e->errors())->toBe(['companies' => ['The selected organization is invalid.']]);
        }
    }

    expect(linkedTo($this->project))->toBe($before);
});

it('does not reveal how many hidden links a project has: the edit page offers and lists only the actor\'s own', function () {
    $this->project->organizations()->attach([$this->mine->id, $this->theirs->id, $this->directoryOnly->id]);

    $page = $this->actingAs($this->manager)->get(route('projects.edit', $this->project))->assertOk();

    $props = $page->viewData('page')['props'] ?? json_decode(html_entity_decode($page->getContent()), true);
    expect($page->getContent())->not->toContain('Theirs')->not->toContain('Directory Only');
});

// ── Project creation ─────────────────────────────────────────────────────────────────────────

it('creates a project with its initial Organizations atomically, and refuses one the creator may not link, with no project left behind', function () {
    $projects = Project::count();

    $created = app(ProjectService::class)->create($this->operator, ['name' => 'Linked', 'companies' => [$this->theirs->id, $this->mine->id]]);
    expect(linkedTo($created))->toBe([$this->mine->id, $this->theirs->id]);

    foreach ([[$this->theirs->id], [$this->mine->id, 987654]] as $bad) {
        expect(fn () => app(ProjectService::class)->create($this->manager, ['name' => 'Refused', 'companies' => $bad]))->toThrow(ValidationException::class);
    }

    expect(Project::count())->toBe($projects + 1)->and(Project::where('name', 'Refused')->exists())->toBeFalse();
});

// ── Lock sequence ────────────────────────────────────────────────────────────────────────────

/** The locking statements a callback issues inside its transaction, in order. @return list<string> */
function lockSequence(Closure $run): array
{
    $locks = [];
    DB::listen(function ($query) use (&$locks) {
        $sql = preg_replace('/\s+/', ' ', $query->sql);

        if (DB::transactionLevel() > 1 && (str_contains($sql, 'lock in share mode') || str_contains($sql, 'for update'))) {
            $locks[] = $sql.' ['.implode(',', $query->bindings).']';
        }
    });
    $run();

    return $locks;
}

it('creation: the creator\'s user row, then the Organization rows shared in ASCENDING id order, then the project writes', function () {
    $ids = [$this->theirs->id, $this->directoryOnly->id, $this->mine->id];   // submitted out of order on purpose
    $locks = lockSequence(fn () => app(ProjectService::class)->create($this->operator, ['name' => 'Ordered', 'companies' => $ids]));

    sort($ids);

    expect($locks[0])->toContain('from `users`')->toContain('lock in share mode')
        ->and($locks[1])->toContain('from `organizations`')->toContain('lock in share mode')->toContain('order by `id` asc')
        ->and(substr($locks[1], strrpos($locks[1], '[')))->toBe('['.implode(',', $ids).']')
        ->and(count(array_filter($locks, fn ($l) => str_contains($l, 'from `organizations`'))))->toBe(1, 'one ordered statement, never one per Organization');
});

it('synchronization: the actor\'s user row, then every Organization it may add or remove shared in ascending order, then the PROJECT row, then the links', function () {
    $this->project->organizations()->attach([$this->theirs->id]);
    $locks = lockSequence(fn () => app(ProjectService::class)->syncOrganizations($this->project, $this->operator, [$this->mine->id, $this->directoryOnly->id]));

    $positions = [];
    foreach ($locks as $i => $lock) {
        foreach (['users' => 'from `users`', 'organizations' => 'from `organizations`', 'projects' => 'from `projects`', 'links' => 'from `project_organization`'] as $name => $needle) {
            if (str_contains($lock, $needle) && ! isset($positions[$name])) {
                $positions[$name] = $i;
            }
        }
    }

    expect($positions['users'])->toBeLessThan($positions['organizations'])
        ->and($positions['organizations'])->toBeLessThan($positions['projects'])
        ->and($positions['projects'])->toBeLessThan($positions['links'])
        ->and($locks[$positions['organizations']])->toContain('lock in share mode')->toContain('order by `id` asc')
        ->and($locks[$positions['projects']])->toContain('for update');

    $organizationLock = $locks[$positions['organizations']];
    $locked = array_map('intval', explode(',', trim(substr($organizationLock, strrpos($organizationLock, '[')), '[]')));
    $sorted = $locked;
    sort($sorted);

    expect($locked)->toBe($sorted)
        ->and($locked)->toEqualCanonicalizing([$this->mine->id, $this->theirs->id, $this->directoryOnly->id], 'the submitted ids AND the currently linked one, which may be removed');
});

it('takes no Gate call and no permission-cache read under any of those locks', function () {
    $this->actingAs($this->operator);
    $gate = 0;
    Gate::after(function () use (&$gate) {
        if (DB::transactionLevel() > 1) {
            $gate++;
        }
    });
    $inside = [];
    DB::listen(function ($query) use (&$inside) {
        if (DB::transactionLevel() > 1) {
            $inside[] = preg_replace('/\s+/', ' ', $query->sql);
        }
    });

    app(ProjectService::class)->create($this->operator, ['name' => 'Quiet', 'companies' => [$this->mine->id]]);
    app(ProjectService::class)->syncOrganizations($this->project, $this->operator, [$this->theirs->id]);

    // The permission CACHE is what must not be loaded (its rows are `cache`, `roles`, and the catalogue `select * from permissions`). The operator test is
    // asked of the tables, on purpose (CP6 F-2): one `exists` query per transaction, which names the model_has_* tables and is NOT a cache load.
    $cache = array_filter($inside, fn ($sql) => str_contains($sql, '`cache`') || str_contains($sql, 'from `roles`') || preg_match('/^select \* from `permissions`/', $sql) === 1);
    $tableChecks = array_filter($inside, fn ($sql) => str_starts_with($sql, 'select exists(select * from `users` where `users`.`id` = ? and (exists (select 1 from `model_has_permissions`'));

    expect($gate)->toBe(0)->and($cache)->toBe([])->and($tableChecks)->toHaveCount(2, 'one table-backed eligibility read per transaction (create, sync)');
});

it('keeps the link when the project is deleted from under it: CASCADE removes the link, RESTRICT keeps the Organization', function () {
    $this->project->organizations()->attach([$this->mine->id]);
    DB::table('projects')->where('id', $this->project->id)->delete();

    expect(DB::table('project_organization')->where('project_id', $this->project->id)->count())->toBe(0)
        ->and(Organization::withoutGlobalScopes()->whereKey($this->mine->id)->exists())->toBeTrue();
});
