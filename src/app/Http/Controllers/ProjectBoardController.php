<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\View\View;

class ProjectBoardController extends Controller
{
    public function show(Project $project): View
    {
        $this->authorize('view', $project);

        $project->load([
            'columns.tasks' => fn ($q) => $q->with(['assignee', 'milestone'])->orderBy('position'),
            'members',
            'milestones',
        ]);

        return view('projects.board', compact('project'));
    }
}
