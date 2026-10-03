<?php

use App\Models\CrmCompany;
use App\Models\Project;
use App\Models\ProjectColumn;
use App\Services\ProjectService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-015 WP1 PR A (§5.1 R1, INV-P10, EPIC-014 A1.3.1(2)): project creation is atomic.
 *
 * Written against the code as it stood at `58c58f1`, where creation spanned two un-transacted
 * steps: ProjectService::create (project row, five columns, creator membership) and then, in
 * ProjectController::store, syncMembers and companies()->sync. A failure anywhere after the
 * project insert left whatever had been written. Failures are injected at the database boundary,
 * so the real controller, service and models run: a listener throws when the Nth INSERT into a
 * named table is issued (after the statement has executed, so a transaction must undo it).
 *
 * Each failure test was first written asserting that residue (KNOWN DEFECT / FLIPS IN WP1) and
 * then flipped in place to the contract below; its comment keeps what was left behind before. The
 * residue was real: the project row was committed with only some columns (no Done column), or with
 * no members, or with a half-run membership sync (sync() detaches the creator first).
 *
 *   FLIPPED IN WP1 — asserts nothing is left behind.
 *   PRESERVED      — behaviour EPIC-015 keeps.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->withoutExceptionHandling();
    $this->operator = makeUser('operator');
    $this->extra = makeUser();
    $this->company = CrmCompany::factory()->create();
});

/** Throw when the $nth INSERT into $table is issued during this test. */
function failOnInsertInto(string $table, int $nth = 1): void
{
    $seen = 0;
    DB::listen(function ($query) use ($table, $nth, &$seen) {
        if (preg_match('/^\s*insert\s+into\s+`?'.preg_quote($table, '/').'`?[\s(]/i', $query->sql) && ++$seen === $nth) {
            throw new RuntimeException("Injected failure on insert #{$nth} into {$table}");
        }
    });
}

/** What a create attempt named "Atomic" left behind, table by table. */
function createResidue(string $name = 'Atomic'): array
{
    $ids = Project::where('name', $name)->pluck('id');

    return [
        'projects' => $ids->count(),
        'columns' => ProjectColumn::whereIn('project_id', $ids)->count(),
        'done_columns' => ProjectColumn::whereIn('project_id', $ids)->where('is_done_column', true)->count(),
        'members' => DB::table('project_members')->whereIn('project_id', $ids)->count(),
        'companies' => DB::table('project_company')->whereIn('project_id', $ids)->count(),
    ];
}

function postCreate($test, array $overrides = []): void
{
    $test->actingAs($test->operator)->post(route('projects.store'), [
        'name' => 'Atomic',
        'status' => 'active',
        'members' => [['user_id' => $test->extra->id, 'role' => 'member']],
        'companies' => [$test->company->id],
        ...$overrides,
    ]);
}

// ── Preserved: a successful create ────────────────────────────────────────────

it('PRESERVED: a successful create writes the project, five columns with exactly one Done, the creator as manager, the extra members and the companies', function () {
    postCreate($this);

    $project = Project::where('name', 'Atomic')->firstOrFail();

    expect(createResidue())->toBe(['projects' => 1, 'columns' => 5, 'done_columns' => 1, 'members' => 2, 'companies' => 1])
        ->and($project->members()->where('user_id', $this->operator->id)->value('role'))->toBe('manager')
        ->and($project->members()->where('user_id', $this->extra->id)->value('role'))->toBe('member')
        ->and($project->columns()->orderBy('position')->pluck('name')->all())
        ->toBe(['Backlog', 'To Do', 'In Progress', 'In Review', 'Done']);
});

it('PRESERVED: an extra-member entry for the creator never demotes them (the creator stays manager)', function () {
    postCreate($this, ['members' => [['user_id' => $this->operator->id, 'role' => 'member']]]);

    $project = Project::where('name', 'Atomic')->firstOrFail();

    expect($project->members()->where('user_id', $this->operator->id)->value('role'))->toBe('manager')
        ->and($project->members()->count())->toBe(1);
});

// ── Atomic: a failure anywhere leaves nothing behind ──────────────────────────

it('FLIPPED IN WP1 (INV-P10): a failure while seeding the default columns leaves no project, column or membership', function () {
    // Before: 1 project, 3 columns (the failing INSERT had already run), no Done column, no creator.
    failOnInsertInto('project_columns', 3);

    expect(fn () => postCreate($this))->toThrow(RuntimeException::class);

    expect(createResidue())->toBe(['projects' => 0, 'columns' => 0, 'done_columns' => 0, 'members' => 0, 'companies' => 0]);
});

it('FLIPPED IN WP1 (INV-P10): a failure on the very first column leaves nothing', function () {
    failOnInsertInto('project_columns', 1);

    expect(fn () => postCreate($this))->toThrow(RuntimeException::class);

    expect(createResidue())->toBe(['projects' => 0, 'columns' => 0, 'done_columns' => 0, 'members' => 0, 'companies' => 0]);
});

it('FLIPPED IN WP1 (INV-P10): a failure attaching the creator leaves no project and no columns', function () {
    // Before: a committed project with its five columns and the creator row that ran before the throw.
    failOnInsertInto('project_members', 1);

    expect(fn () => postCreate($this))->toThrow(RuntimeException::class);

    expect(createResidue())->toBe(['projects' => 0, 'columns' => 0, 'done_columns' => 0, 'members' => 0, 'companies' => 0]);
});

it('FLIPPED IN WP1 (INV-P10): a failure syncing the extra members leaves nothing — not even the creator membership sync() half-ran', function () {
    // The second INSERT into project_members is the extra-member sync. Before: a committed project
    // whose creator had been detached by sync() and only the extra member remained.
    failOnInsertInto('project_members', 2);

    expect(fn () => postCreate($this))->toThrow(RuntimeException::class);

    expect(createResidue())->toBe(['projects' => 0, 'columns' => 0, 'done_columns' => 0, 'members' => 0, 'companies' => 0])
        ->and(DB::table('project_members')->count())->toBe(0);
});

it('FLIPPED IN WP1 (INV-P10): a failure linking the companies leaves no project, members or links', function () {
    // Before: a committed project with its members and the company link that ran before the throw.
    failOnInsertInto('project_company', 1);

    expect(fn () => postCreate($this))->toThrow(RuntimeException::class);

    expect(createResidue())->toBe(['projects' => 0, 'columns' => 0, 'done_columns' => 0, 'members' => 0, 'companies' => 0])
        ->and(DB::table('project_company')->count())->toBe(0);
});

it('FLIPPED IN WP1 (INV-P10): the service itself is atomic, with no controller in the loop', function () {
    failOnInsertInto('project_company', 1);

    expect(fn () => app(ProjectService::class)->create($this->operator, [
        'name' => 'Atomic',
        'members' => [['user_id' => $this->extra->id, 'role' => 'member']],
        'companies' => [$this->company->id],
    ]))->toThrow(RuntimeException::class);

    expect(createResidue())->toBe(['projects' => 0, 'columns' => 0, 'done_columns' => 0, 'members' => 0, 'companies' => 0]);
});

it('PRESERVED: a failed create does not disturb another project, and the next attempt succeeds cleanly', function () {
    $existing = makeProject(null, 'Existing');
    failOnInsertInto('project_members', 2);
    expect(fn () => postCreate($this))->toThrow(RuntimeException::class);

    expect(createResidue())->toBe(['projects' => 0, 'columns' => 0, 'done_columns' => 0, 'members' => 0, 'companies' => 0])
        ->and(createResidue('Existing'))->toBe(['projects' => 1, 'columns' => 5, 'done_columns' => 1, 'members' => 1, 'companies' => 0]);

    // The listener only fires once (the 2nd insert into project_members), so a retry is unaffected.
    postCreate($this);
    expect(createResidue())->toBe(['projects' => 1, 'columns' => 5, 'done_columns' => 1, 'members' => 2, 'companies' => 1]);
});

it('PRESERVED (INV-P11): the integrity audit sees no project without a Done column after a failed create', function () {
    failOnInsertInto('project_columns', 3);
    expect(fn () => postCreate($this))->toThrow(RuntimeException::class);

    Artisan::call('projects:audit-integrity', ['--json' => true]);
    $counts = json_decode(Artisan::output(), true);

    expect($counts['projects_with_no_done_column'])->toBe(0);
});
