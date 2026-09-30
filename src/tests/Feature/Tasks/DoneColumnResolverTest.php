<?php

use App\Exceptions\DoneColumnConfigurationException;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-014 Q2 / INV-8 (WP1): a board task can only be completed into the project's single
 * designated Done column. The resolver returns it when exactly one exists and otherwise fails
 * with a configuration error — it never guesses, never falls back to the last column, and never
 * consults tasks.status. Complete/Reopen themselves are WP2.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->service = app(ProjectService::class);
    $this->project = makeProject();
});

it('returns the single designated Done column of a project created by the application', function () {
    $column = $this->service->doneColumn($this->project);

    expect($column->project_id)->toBe($this->project->id)
        ->and($column->is_done_column)->toBeTrue()
        ->and($column->name)->toBe('Done');
});

it('finds the Done column by its flag, not by name or position', function () {
    $columns = $this->project->columns()->orderBy('position')->get();
    // Move the flag to the second column and put the old Done column last-but-not-flagged.
    $columns->last()->update(['is_done_column' => false]);
    $columns[1]->update(['is_done_column' => true]);

    expect($this->service->doneColumn($this->project->fresh())->id)->toBe($columns[1]->id);
});

it('fails clearly, and never guesses, when a project has no Done column', function () {
    $this->project->columns()->update(['is_done_column' => false]);

    try {
        $this->service->doneColumn($this->project);
        $this->fail('Expected a configuration error.');
    } catch (DoneColumnConfigurationException $e) {
        expect($e->projectId)->toBe($this->project->id)
            ->and($e->doneColumnCount)->toBe(0)
            ->and($e->getMessage())->toContain('no Done column');
    }
});

it('fails clearly when a project has several Done columns', function () {
    $this->project->columns()->whereIn('position', [3, 4])->update(['is_done_column' => true]);

    try {
        $this->service->doneColumn($this->project);
        $this->fail('Expected a configuration error.');
    } catch (DoneColumnConfigurationException $e) {
        expect($e->doneColumnCount)->toBe(2)
            ->and($e->getMessage())->toContain('2 Done columns');
    }
});

it('fails for a project with no columns at all', function () {
    $bare = Project::factory()->create();

    expect(fn () => $this->service->doneColumn($bare))->toThrow(DoneColumnConfigurationException::class);
});

it('only reads: resolving never writes a column or a task', function () {
    $misconfigured = makeProject(null, 'Misconfigured');
    $misconfigured->columns()->update(['is_done_column' => false]);
    makeTask($this->project->columns[0]);
    $snapshot = fn () => [DB::table('project_columns')->get()->toJson(), DB::table('tasks')->get()->toJson()];
    $before = $snapshot();

    $this->service->doneColumn($this->project);
    expect(fn () => $this->service->doneColumn($misconfigured))->toThrow(DoneColumnConfigurationException::class);

    expect($snapshot())->toBe($before);
});
