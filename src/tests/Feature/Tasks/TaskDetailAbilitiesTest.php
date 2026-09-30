<?php

use App\Models\User;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-014 §13.3 (WP2): board task detail gains abilities.complete / abilities.reopen, answered by
 * TaskPolicy (Q1). The Complete/Reopen controls themselves are WP5; this pins the backend
 * contract they will consume. Nobody who cannot open the page gets here at all (ProjectPolicy::view).
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->project = makeProject();
    $this->task = makeTask($this->project->columns[1]);
});

it('exposes Complete/Reopen abilities per TaskPolicy on board task detail', function (string $actor, bool $expected) {
    $user = match ($actor) {
        'project_manager' => projectActor('project_manager', $this->project),
        'operator' => makeUser('operator'),
        'member_assignee' => tap(projectActor('member', $this->project), fn (User $u) => $this->task->update(['assignee_id' => $u->id])),
        'plain_member' => projectActor('member', $this->project),
    };

    $this->actingAs($user)->get(route('projects.tasks.show', [$this->project, $this->task]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('abilities.complete', $expected)
            ->where('abilities.reopen', $expected)
            // Q1 grants the semantic operation only, never structural management.
            ->where('abilities.manage', in_array($actor, ['project_manager', 'operator'], true)));
})->with([
    'project manager' => ['project_manager', true],
    'operator (projects.admin)' => ['operator', true],
    'member who is the current assignee' => ['member_assignee', true],
    'plain member' => ['plain_member', false],
]);
