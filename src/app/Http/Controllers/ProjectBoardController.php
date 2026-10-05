<?php

namespace App\Http\Controllers;

use App\Http\Presenters\ProjectBoardPresenter;
use App\Http\Presenters\ProjectPresenter;
use App\Models\Project;
use App\Policies\ProjectSettingsAccess;
use App\Queries\TaskRowAbilities;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectBoardController extends Controller
{
    public function show(Project $project): Response
    {
        $this->authorize('view', $project);

        // Everything a card renders is loaded up front: the assignee and milestone names, the
        // task's own column (for the overdue rule), and checklist totals as aggregates, so the
        // query count does not grow with the number of cards.
        $project->load([
            // The malformed project+ticket row has no valid kind and is not a board card (EPIC-015
            // §8.4); the row itself is untouched.
            'columns.tasks' => fn ($q) => $q
                ->ofValidKind()
                ->with(['assignee:id,name', 'milestone:id,name', 'column'])
                ->withCount([
                    'checklistItems',
                    'checklistItems as done_checklist_items_count' => fn ($items) => $items->where('completed', true),
                ])
                ->orderBy('position')
                ->orderBy('id'),
        ]);

        // Each card's Complete/Reopen answer is TaskPolicy's, projected for the whole board from ONE
        // membership query (the Tasks list's TaskRowAbilities, EPIC-015 WP5 S1), never a policy call
        // per card. The tasks.complete/reopen routes still authorize every request themselves.
        $abilities = TaskRowAbilities::for(auth()->user(), $project->columns->flatMap->tasks);

        return Inertia::render('projects/board', [
            'project' => ProjectBoardPresenter::project($project),
            'columns' => $project->columns->map(fn ($column) => ProjectBoardPresenter::column($column, $abilities))->values(),
            'abilities' => [
                // Structural mutation (quick-add, move, reorder): the policy alone, exactly
                // what the structural task routes authorize (D1). No route middleware gate.
                'manage' => Gate::allows('manage', $project),
                // The Settings link: what projects.edit actually admits (A9), so a projects.admin
                // holder without projects.manage is never offered a link that answers 403
                // (EPIC-011E §7, Amendment 4 W6; EPIC-015 §7 shared resolver).
                'openSettings' => ProjectSettingsAccess::allows(auth()->user(), $project),
            ],
            'tabs' => ProjectPresenter::workspaceTabs(auth()->user()),
        ]);
    }
}
