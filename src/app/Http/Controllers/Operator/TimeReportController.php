<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimeEntryService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class TimeReportController extends Controller
{
    public function __construct(private TimeEntryService $service) {}

    public function index(Request $request): View
    {
        $filters = $request->only(['user_id', 'project_id', 'from', 'to', 'billable']);

        $entries = TimeEntry::with(['user:id,name', 'project:id,name', 'task:id,title'])
            ->whereNull('timer_started_at')
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['project_id'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v))
            ->when(isset($filters['billable']), fn ($q) => $q->where('billable', (bool) $filters['billable']))
            ->orderByDesc('date')
            ->paginate(50)
            ->withQueryString();

        $byProject = $this->service->summaryByProject($filters);
        $byUser = $this->service->summaryByUser($filters);

        $totalMinutes = $entries->sum('duration_minutes');

        $users = User::orderBy('name')->get(['id', 'name']);
        $projects = Project::orderBy('name')->get(['id', 'name']);

        return view('operator.time.index', compact(
            'entries', 'byProject', 'byUser', 'totalMinutes', 'users', 'projects', 'filters'
        ));
    }

    public function export(Request $request): Response
    {
        $filters = $request->only(['user_id', 'project_id', 'from', 'to', 'billable']);
        $filename = 'time-export-'.now()->format('Y-m-d').'.csv';
        $csv = $this->service->exportCsv($filters);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
