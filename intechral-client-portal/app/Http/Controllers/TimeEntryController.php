<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\TimeEntryBlock;
use App\Services\TimeEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TimeEntryController extends Controller
{
    public function __construct(private TimeEntryService $service) {}

    public function index(Request $request): View
    {
        $user = auth()->user();

        $entries = TimeEntry::forUser($user->id)
            ->with(['project', 'task', 'ticket'])
            ->when($request->project_id, fn ($q, $id) => $q->where('project_id', $id))
            ->when($request->ticket_id, fn ($q, $id) => $q->where('ticket_id', $id))
            ->when($request->from, fn ($q, $d) => $q->where('date', '>=', $d))
            ->when($request->to, fn ($q, $d) => $q->where('date', '<=', $d))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $projects = Project::whereHas('members', fn ($q) => $q->where('user_id', $user->id))
            ->orWhere('created_by', $user->id)
            ->orderBy('name')->get(['id', 'name']);

        $activeTimers = $this->service->activeTimers($user);

        $totalMinutes = TimeEntry::forUser($user->id)
            ->whereNull('timer_started_at')
            ->when($request->from, fn ($q, $d) => $q->where('date', '>=', $d))
            ->when($request->to, fn ($q, $d) => $q->where('date', '<=', $d))
            ->sum('duration_minutes');

        return view('time.index', compact('entries', 'projects', 'activeTimers', 'totalMinutes'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'date' => 'required|date|before_or_equal:today',
            'hours' => 'required|numeric|min:0.25|max:24',
            'project_id' => 'nullable|exists:projects,id',
            'task_id' => 'nullable|exists:tasks,id',
            'ticket_id' => 'nullable|exists:tickets,id',
            'description' => 'nullable|string|max:500',
            'billable' => 'nullable|boolean',
        ]);

        $this->service->log(auth()->user(), [
            ...$request->only(['date', 'hours', 'project_id', 'task_id', 'ticket_id', 'description']),
            'billable' => $request->boolean('billable', true),
        ]);

        return back()->with('success', 'Time entry logged.');
    }

    public function update(Request $request, TimeEntry $entry): RedirectResponse
    {
        abort_unless($entry->user_id === auth()->id() && ! $entry->billed, 403);

        $request->validate([
            'date' => 'required|date|before_or_equal:today',
            'hours' => 'required|numeric|min:0.25|max:24',
            'project_id' => 'nullable|exists:projects,id',
            'task_id' => 'nullable|exists:tasks,id',
            'ticket_id' => 'nullable|exists:tickets,id',
            'description' => 'nullable|string|max:500',
            'billable' => 'nullable|boolean',
        ]);

        $this->service->update($entry, [
            ...$request->only(['date', 'hours', 'project_id', 'task_id', 'ticket_id', 'description']),
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
            'project_id' => 'nullable|exists:projects,id',
            'task_id' => 'nullable|exists:tasks,id',
            'ticket_id' => 'nullable|exists:tickets,id',
            'description' => 'nullable|string|max:500',
            'billable' => 'nullable|boolean',
        ]);

        $entry = $this->service->startTimer(
            auth()->user(),
            $request->only(['project_id', 'task_id', 'ticket_id', 'description', 'billable'])
        );

        $entry->load(['project', 'task', 'ticket']);

        return response()->json([
            'id' => $entry->id,
            'started_at' => $entry->timer_started_at->toISOString(),
            'description' => $entry->description,
            'context' => $this->buildContextPayload($entry),
        ]);
    }

    public function timerStop(TimeEntry $entry): JsonResponse
    {
        abort_unless($entry->user_id === auth()->id(), 403);

        $entry = $this->service->stopTimer($entry);

        return response()->json([
            'duration_minutes' => $entry->duration_minutes,
            'duration_human' => $entry->durationForHumans(),
        ]);
    }

    public function updateTimerDescription(Request $request, TimeEntry $entry): JsonResponse
    {
        abort_unless($entry->user_id === auth()->id() && $entry->isRunning(), 403);

        $request->validate(['description' => 'nullable|string|max:500']);

        $entry->update(['description' => $request->description]);

        return response()->json(['ok' => true]);
    }

    // ── Active timers JSON (for global overlay) ──────────────

    public function activeTimersJson(): JsonResponse
    {
        $timers = $this->service->activeTimers(auth()->user());

        return response()->json(
            $timers->map(fn ($e) => [
                'id' => $e->id,
                'started_at' => $e->timer_started_at->toISOString(),
                'description' => $e->description,
                'context' => $this->buildContextPayload($e),
            ])
        );
    }

    // ── Context options for cascading selector ───────────────

    public function contextOptions(Request $request): JsonResponse
    {
        $user = auth()->user();
        $type = $request->input('type');

        $options = match ($type) {
            'project' => Project::where(fn ($q) => $q->whereHas('members', fn ($m) => $m->where('user_id', $user->id))
                ->orWhere('created_by', $user->id)
            )
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'label' => $p->name]),

            'task' => Task::where('assignee_id', $user->id)
                ->whereNotIn('status', ['done'])
                ->orderBy('title')
                ->limit(50)
                ->get(['id', 'title'])
                ->map(fn ($t) => ['id' => $t->id, 'label' => $t->title]),

            'ticket' => Ticket::where('assignee_id', $user->id)
                ->whereIn('status', ['open', 'in_progress'])
                ->orderBy('ticket_number')
                ->limit(50)
                ->get(['id', 'ticket_number', 'title'])
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'label' => $t->ticket_number.' — '.Str::limit($t->title, 40),
                ]),

            default => collect(),
        };

        return response()->json($options->values());
    }

    // ── Allocation chart ─────────────────────────────────────

    public function allocationView(Request $request): View
    {
        $user = auth()->user();
        $date = $request->input('date', today()->toDateString());

        $blocks = TimeEntryBlock::where('user_id', $user->id)
            ->where('block_date', $date)
            ->with(['timeEntry.project', 'timeEntry.task', 'timeEntry.ticket'])
            ->get()
            ->groupBy('time_entry_id');

        $activeTimers = $this->service->activeTimers($user);

        return view('time.allocation', compact('blocks', 'activeTimers', 'date'));
    }

    public function updateBlockAllocation(Request $request, TimeEntryBlock $block): JsonResponse
    {
        abort_unless($block->timeEntry->user_id === auth()->id(), 403);

        $request->validate(['allocation_pct' => 'required|numeric|min:0|max:100']);

        $newPct = (float) $request->allocation_pct;
        $remainder = 100.0 - $newPct;

        $siblings = TimeEntryBlock::where('user_id', auth()->id())
            ->where('block_date', $block->block_date)
            ->where('block_number', $block->block_number)
            ->where('id', '!=', $block->id)
            ->get();

        $siblingTotal = $siblings->sum('allocation_pct');

        foreach ($siblings as $sibling) {
            $adjusted = $siblingTotal > 0
                ? round(($sibling->allocation_pct / $siblingTotal) * $remainder, 2)
                : round($remainder / max(1, $siblings->count()), 2);

            $sibling->update(['allocation_pct' => $adjusted, 'is_overridden' => true]);
        }

        $block->update(['allocation_pct' => $newPct, 'is_overridden' => true]);

        return response()->json(['ok' => true]);
    }

    // ── Private ──────────────────────────────────────────────

    private function buildContextPayload(TimeEntry $entry): ?array
    {
        if ($entry->ticket_id && $entry->ticket) {
            return [
                'type' => 'Ticket',
                'label' => $entry->ticket->ticket_number.' — '.Str::limit($entry->ticket->title, 40),
                'url' => route('tickets.show', $entry->ticket),
            ];
        }

        if ($entry->task_id && $entry->task) {
            return [
                'type' => 'Task',
                'label' => Str::limit($entry->task->title, 50),
                'url' => $entry->task->project_id
                    ? route('projects.tasks.show', [$entry->task->project_id, $entry->task])
                    : null,
            ];
        }

        if ($entry->project_id && $entry->project) {
            return [
                'type' => 'Project',
                'label' => $entry->project->name,
                'url' => route('projects.board', $entry->project),
            ];
        }

        return null;
    }
}
