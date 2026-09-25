<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimeEntryService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class TimeReportController extends Controller
{
    public function __construct(private TimeEntryService $service) {}

    public function index(Request $request): InertiaResponse
    {
        $filters = $this->filters($request);

        $entries = TimeEntry::with(['user:id,name', 'project:id,name', 'task:id,title'])
            ->whereNull('timer_started_at')
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['project_id'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v))
            ->when(isset($filters['billable']), fn ($q) => $q->where('billable', (bool) $filters['billable']))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (TimeEntry $entry) => [
                'id' => $entry->id,
                'date' => $entry->date->format('Y-m-d'),
                'userName' => $entry->user?->name ?? 'Unknown',
                'projectName' => $entry->project?->name,
                'description' => $entry->description,
                'durationMinutes' => $entry->duration_minutes,
                'billable' => $entry->billable,
                'billed' => $entry->billed,
                'locked' => $entry->isLockedForBilling(),
            ]);

        $byProject = $this->service->summaryByProject($filters)->map(fn (TimeEntry $row) => [
            'id' => $row->project_id,
            'name' => $row->project?->name ?? 'No project',
            'totalMinutes' => (int) $row->total_minutes,
            'billableMinutes' => (int) $row->billable_minutes,
        ]);
        $byUser = $this->service->summaryByUser($filters)->map(fn (TimeEntry $row) => [
            'id' => $row->user_id,
            'name' => $row->user?->name ?? 'Unknown',
            'totalMinutes' => (int) $row->total_minutes,
            'billableMinutes' => (int) $row->billable_minutes,
        ]);

        $totalMinutes = $this->service->totalMinutes($filters);

        $users = User::orderBy('name')->get(['id', 'name'])->map->only(['id', 'name'])->values();
        $projects = Project::orderBy('name')->get(['id', 'name'])->map->only(['id', 'name'])->values();

        return Inertia::render('operator/time/index', [
            'entries' => $entries,
            'byProject' => $byProject,
            'byUser' => $byUser,
            'totalMinutes' => $totalMinutes,
            'users' => $users,
            'projects' => $projects,
            'filters' => [
                ...$filters,
                'user_id' => isset($filters['user_id']) ? (string) $filters['user_id'] : null,
                'project_id' => isset($filters['project_id']) ? (string) $filters['project_id'] : null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'billable' => isset($filters['billable']) ? ($filters['billable'] ? '1' : '0') : null,
            ],
        ]);
    }

    public function export(Request $request): Response
    {
        $filters = $this->filters($request);
        $filename = 'time-export-'.now()->format('Y-m-d').'.csv';
        $csv = $this->service->exportCsv($filters);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'billable' => ['nullable', 'in:0,1'],
        ]);

        $filters = collect($validated)
            ->reject(fn ($value) => $value === null || $value === '')
            ->all();

        if (array_key_exists('billable', $filters)) {
            $filters['billable'] = $filters['billable'] === '1';
        }

        return $filters;
    }
}
