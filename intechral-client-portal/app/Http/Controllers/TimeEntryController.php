<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\TimeEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TimeEntryController extends Controller
{
    public function __construct(private TimeEntryService $service) {}

    public function index(Request $request): View
    {
        $user = auth()->user();

        $entries = TimeEntry::forUser($user->id)
            ->with(['project', 'task'])
            ->when($request->project_id, fn ($q, $id) => $q->where('project_id', $id))
            ->when($request->from, fn ($q, $d) => $q->where('date', '>=', $d))
            ->when($request->to,   fn ($q, $d) => $q->where('date', '<=', $d))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $projects = Project::whereHas('members', fn ($q) => $q->where('user_id', $user->id))
            ->orWhere('created_by', $user->id)
            ->orderBy('name')->get(['id', 'name']);

        $activeTimer = $this->service->activeTimer($user);

        $totalMinutes = TimeEntry::forUser($user->id)
            ->whereNull('timer_started_at')
            ->when($request->from, fn ($q, $d) => $q->where('date', '>=', $d))
            ->when($request->to,   fn ($q, $d) => $q->where('date', '<=', $d))
            ->sum('duration_minutes');

        return view('time.index', compact('entries', 'projects', 'activeTimer', 'totalMinutes'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'date'        => 'required|date|before_or_equal:today',
            'hours'       => 'required|numeric|min:0.01|max:24',
            'project_id'  => 'nullable|exists:projects,id',
            'task_id'     => 'nullable|exists:project_tasks,id',
            'description' => 'nullable|string|max:500',
            'billable'    => 'nullable|boolean',
        ]);

        $this->service->log(auth()->user(), [
            ...$request->only(['date', 'hours', 'project_id', 'task_id', 'description']),
            'billable' => $request->boolean('billable', true),
        ]);

        return back()->with('success', 'Time entry logged.');
    }

    public function update(Request $request, TimeEntry $entry): RedirectResponse
    {
        abort_unless($entry->user_id === auth()->id() && ! $entry->billed, 403);

        $request->validate([
            'date'        => 'required|date|before_or_equal:today',
            'hours'       => 'required|numeric|min:0.01|max:24',
            'project_id'  => 'nullable|exists:projects,id',
            'task_id'     => 'nullable|exists:project_tasks,id',
            'description' => 'nullable|string|max:500',
            'billable'    => 'nullable|boolean',
        ]);

        $this->service->update($entry, [
            ...$request->only(['date', 'hours', 'project_id', 'task_id', 'description']),
            'billable' => $request->boolean('billable', true),
        ]);

        return back()->with('success', 'Entry updated.');
    }

    public function destroy(TimeEntry $entry): RedirectResponse
    {
        abort_unless($entry->user_id === auth()->id() && ! $entry->billed, 403);

        $entry->delete();

        return back()->with('success', 'Entry deleted.');
    }

    // ── Timer ────────────────────────────────────────────────

    public function timerStart(Request $request): JsonResponse
    {
        $request->validate([
            'project_id'  => 'nullable|exists:projects,id',
            'task_id'     => 'nullable|exists:project_tasks,id',
            'description' => 'nullable|string|max:500',
        ]);

        $entry = $this->service->startTimer(auth()->user(), $request->only(['project_id', 'task_id', 'description']));

        return response()->json([
            'id'         => $entry->id,
            'started_at' => $entry->timer_started_at->toISOString(),
        ]);
    }

    public function timerStop(TimeEntry $entry): JsonResponse
    {
        abort_unless($entry->user_id === auth()->id(), 403);

        $entry = $this->service->stopTimer($entry);

        return response()->json([
            'duration_minutes' => $entry->duration_minutes,
            'duration_human'   => $entry->durationForHumans(),
        ]);
    }
}
