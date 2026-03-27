<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaskController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user       = auth()->user();
        $view       = $request->get('view', 'mine');
        $companyIds = $user->can('tasks.view_org') ? $user->orgCompanyIds() : [];

        $query = Task::with(['assignee', 'project', 'ticket', 'column'])
            ->where(function ($q) use ($user, $companyIds, $view) {
                // "mine" tab — always includes tasks assigned directly to the user
                $q->where('assignee_id', $user->id);

                // "org" tab / view_org — also show tasks in the org's projects/tickets
                if ($view === 'org' && ! empty($companyIds)) {
                    $q->orWhereHas('project', fn ($p) =>
                        $p->whereHas('companies', fn ($c) =>
                            $c->whereIn('crm_companies.id', $companyIds)
                        )
                    )->orWhereHas('ticket', fn ($t) =>
                        $t->whereIn('company_id', $companyIds)
                    );
                }
            })
            ->orderByRaw("CASE WHEN due_date IS NULL THEN 1 ELSE 0 END")
            ->orderBy('due_date')
            ->orderByDesc('created_at');

        $tasks = $query->paginate(30)->withQueryString();

        return view('tasks.index', compact('tasks', 'view', 'companyIds'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'assignee_id' => 'nullable|exists:users,id',
            'priority'    => 'required|in:low,medium,high,critical',
            'due_date'    => 'nullable|date',
            'status'      => 'required|in:todo,in_progress,done',
        ]);

        Task::create([
            ...$data,
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', 'Task created.');
    }
}
