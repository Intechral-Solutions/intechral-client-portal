<?php

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-015 WP5 (S2; Direction D §5.3 "Drawer defaults and persistence"): per-surface drawer
 * defaults in the Projects workspace, through real HTTP requests.
 *
 * The server half of the contract: the navigation payload's `presentation.operational` names the
 * current SURFACE (a stable semantic key, never a URL or a record id) and that surface's default,
 * and both HTML roots stamp the same values for the pre-paint bootstrap. The client half (the
 * remembered choice, keyed by surface or workspace) is pinned in Vitest (`use-panel-state`,
 * `shell/bootstrap`) and in the browser (`projects-drawer.spec.ts`).
 *
 *   Projects list, Overview, Milestones   workspace default Open, no surface
 *   Board, Tasks tab, Time tab            Collapsed, surfaces projects.board / .tasks / .time
 *   task detail, Settings, create         not named by §5.3: workspace default, no surface
 */

/** The navigation prop the shell receives on a GET, as the browser decodes it. */
function drawerNavigation($response): array
{
    return json_decode(json_encode($response->viewData('page')['props']['navigation']), true);
}

/** The root `<html …>` tag only: the inlined bootstrap script also names the attributes it reads. */
function drawerRootTag(string $html): string
{
    preg_match('/<html\b[^>]*>/', $html, $match);

    return $match[0] ?? '';
}

function drawerHint(array $navigation, string $workspace): array
{
    return collect($navigation['workspaces'])->firstWhere('key', $workspace)['presentation']['operational'];
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = makeUser('operator');
    $this->project = makeProject($this->operator, 'Drawer project');
    $this->todo = $this->project->columns()->where('is_done_column', false)->orderBy('position')->first();
});

dataset('project surfaces', [
    'projects list' => ['projects.index', false, 'open', null],
    'overview' => ['projects.show', true, 'open', null],
    'milestones' => ['projects.milestones.index', true, 'open', null],
    'board' => ['projects.board', true, 'collapsed', 'projects.board'],
    'tasks tab' => ['projects.tasks.index', true, 'collapsed', 'projects.tasks'],
    'time tab' => ['projects.time.index', true, 'collapsed', 'projects.time'],
    'settings' => ['projects.edit', true, 'open', null],
    'create' => ['projects.create', false, 'open', null],
]);

it('gives each project surface its Direction D default and semantic key', function (string $route, bool $withProject, string $panel, ?string $surface) {
    $navigation = drawerNavigation($this->actingAs($this->operator)
        ->get(route($route, $withProject ? $this->project : []))->assertOk());

    expect($navigation['currentWorkspace'])->toBe('projects')
        ->and(drawerHint($navigation, 'projects'))->toBe(['panel' => $panel, 'surface' => $surface]);
})->with('project surfaces');

it('keeps task detail on the workspace default', function () {
    $task = makeTask($this->todo, ['title' => 'Detail']);

    $navigation = drawerNavigation($this->actingAs($this->operator)
        ->get(route('projects.tasks.show', [$this->project, $task]))->assertOk());

    expect(drawerHint($navigation, 'projects'))->toBe(['panel' => 'open', 'surface' => null]);
});

it('names the surface, never the project: one Board key for every project', function () {
    $other = makeProject($this->operator, 'Another project');

    $first = drawerNavigation($this->actingAs($this->operator)->get(route('projects.board', $this->project)));
    $second = drawerNavigation($this->actingAs($this->operator)->get(route('projects.board', $other)));

    expect(drawerHint($first, 'projects'))->toBe(drawerHint($second, 'projects'))
        ->and(json_encode(drawerHint($first, 'projects')))->not->toContain((string) $this->project->id)
        ->and(json_encode(drawerHint($second, 'projects')))->not->toContain((string) $other->id);
});

it('sets a surface on the active workspace only', function () {
    $navigation = drawerNavigation($this->actingAs($this->operator)->get(route('projects.board', $this->project)));

    foreach ($navigation['workspaces'] as $workspace) {
        $hint = $workspace['presentation']['operational'];

        if ($workspace['key'] === 'projects') {
            expect($hint['surface'])->toBe('projects.board');
        } else {
            // Every other workspace keeps its own default and names no surface.
            expect($hint['surface'])->toBeNull("{$workspace['key']} surface");
        }
    }

    expect(drawerHint($navigation, 'tasks'))->toBe(['panel' => 'collapsed', 'surface' => null])
        ->and(drawerHint($navigation, 'helpdesk'))->toBe(['panel' => 'open', 'surface' => null]);
});

it('leaves every other workspace exactly as before', function (string $route, string $workspace, string $panel) {
    $navigation = drawerNavigation($this->actingAs($this->operator)->get(route($route))->assertOk());

    expect($navigation['currentWorkspace'])->toBe($workspace)
        ->and(drawerHint($navigation, $workspace))->toBe(['panel' => $panel, 'surface' => null]);
})->with([
    'global tasks' => ['tasks.index', 'tasks', 'collapsed'],
    'my time' => ['time.index', 'time', 'collapsed'],
]);

it('gives the hint no authority: a surface never changes the authorized model', function () {
    $customer = makeUser();
    $this->project->members()->attach($customer->id, ['role' => 'member']);

    foreach ([$this->operator, $customer] as $actor) {
        $navigation = drawerNavigation($this->actingAs($actor)->get(route('projects.board', $this->project))->assertOk());
        $overview = drawerNavigation($this->actingAs($actor)->get(route('projects.show', $this->project))->assertOk());

        $strip = fn (array $nav) => collect($nav['workspaces'])
            ->map(fn (array $w) => [$w['key'], $w['href'], $w['visit'], $w['isActive'], $w['context']])->all();

        // Board and Overview differ only in the hint: same workspaces, items and active view.
        expect($strip($navigation))->toBe($strip($overview))
            ->and(drawerHint($navigation, 'projects'))->not->toBe(drawerHint($overview, 'projects'));
    }
});

it('keeps projects.all the one active view on the Time tab', function () {
    $navigation = drawerNavigation($this->actingAs($this->operator)->get(route('projects.time.index', $this->project))->assertOk());

    $active = collect($navigation['workspaces'])
        ->flatMap(fn (array $w) => collect($w['context'])->flatMap(fn (array $s) => $s['items']))
        ->where('isActive', true)->pluck('key')->all();

    expect($active)->toBe(['projects.all'])
        ->and(collect($navigation['workspaces'])->where('isActive', true)->pluck('key')->all())->toBe(['projects']);
});

// ── Pre-paint stamping (Direction D §5.3 rule 4: available before first paint) ─────────────────

it('stamps the surface on the Inertia root before any script runs', function () {
    $board = drawerRootTag($this->actingAs($this->operator)->get(route('projects.board', $this->project))->assertOk()->getContent());
    $overview = drawerRootTag($this->actingAs($this->operator)->get(route('projects.show', $this->project))->assertOk()->getContent());

    expect($board)->toContain('data-workspace="projects"')
        ->and($board)->toContain('data-drawer-default="collapsed"')
        ->and($board)->toContain('data-drawer-surface="projects.board"')
        ->and($overview)->toContain('data-drawer-default="open"')
        ->and($overview)->not->toContain('data-drawer-surface');
});

it('stamps no surface on a Blade page', function () {
    // The Blade root shares the attribute contract; no Blade page is a declared surface.
    $html = drawerRootTag($this->actingAs($this->operator)->get(route('tickets.index'))->assertOk()->getContent());

    expect($html)->toContain('data-workspace="helpdesk"')
        ->and($html)->not->toContain('data-drawer-surface');
});

it('stamps the surface for a viewer whose navigation differs, without granting anything', function () {
    $customer = makeUser();
    $this->project->members()->attach($customer->id, ['role' => 'member']);

    $html = drawerRootTag($this->actingAs($customer)->get(route('projects.tasks.index', $this->project))->assertOk()->getContent());

    expect($html)->toContain('data-drawer-surface="projects.tasks"');
    // The customer still cannot reach the create surface the operator's drawer offers.
    $this->actingAs($customer)->get(route('projects.create'))->assertForbidden();
});
