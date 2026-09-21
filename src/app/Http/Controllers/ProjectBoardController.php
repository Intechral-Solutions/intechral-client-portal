<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\View\View;

class ProjectBoardController extends Controller
{
    public function show(Project $project): View
    {
        $this->authorize('view', $project);

        // Everything a card renders is loaded up front: the assignee and milestone names, the
        // task's own column (for the overdue rule), and checklist totals as aggregates, so the
        // query count does not grow with the number of cards.
        $project->load([
            'columns.tasks' => fn ($q) => $q
                ->with(['assignee:id,name', 'milestone:id,name', 'column'])
                ->withCount([
                    'checklistItems',
                    'checklistItems as done_checklist_items_count' => fn ($items) => $items->where('completed', true),
                ])
                ->orderBy('position')
                ->orderBy('id'),
        ]);

        return view('projects.board', compact('project'));
    }
}
