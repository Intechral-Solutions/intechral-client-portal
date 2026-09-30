<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\TimeEntryBlock;
use App\Models\User;
use App\Rules\AccessibleTimeContext;
use App\Services\TimeEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TimeEntryController extends Controller
{
    /** An entry's context: exactly one of these, or none. */
    private const CONTEXT_KEYS = ['project_id', 'task_id', 'ticket_id'];

    public function __construct(private TimeEntryService $service) {}

    public function index(Request $request): Response
    {
        $user = auth()->user();
        $filters = [
            'project_id' => $request->filled('project_id') ? (string) $request->input('project_id') : null,
            'ticket_id' => $request->filled('ticket_id') ? (string) $request->input('ticket_id') : null,
            'from' => $request->filled('from') ? (string) $request->input('from') : null,
            'to' => $request->filled('to') ? (string) $request->input('to') : null,
        ];

        $entries = TimeEntry::forUser($user->id)
            ->with(['project', 'task', 'ticket'])
            ->when($filters['project_id'], fn ($q, $id) => $q->where('project_id', $id))
            ->when($filters['ticket_id'], fn ($q, $id) => $q->where('ticket_id', $id))
            ->when($filters['from'], fn ($q, $date) => $q->where('date', '>=', $date))
            ->when($filters['to'], fn ($q, $date) => $q->where('date', '<=', $date))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (TimeEntry $entry) => $this->buildEntryPayload($entry));

        $projects = Project::whereHas('members', fn ($q) => $q->where('user_id', $user->id))
            ->orWhere('created_by', $user->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
            ]);

        // Rows with timer_started_at set are unsettled (running, or a corrupt legacy
        // row that also has stopped_at). Their duration is only final once
        // stopTimer() normalizes them, so they stay out of the total until then.
        $totalMinutes = TimeEntry::forUser($user->id)
            ->whereNull('timer_started_at')
            ->when($filters['project_id'], fn ($q, $id) => $q->where('project_id', $id))
            ->when($filters['ticket_id'], fn ($q, $id) => $q->where('ticket_id', $id))
            ->when($filters['from'], fn ($q, $date) => $q->where('date', '>=', $date))
            ->when($filters['to'], fn ($q, $date) => $q->where('date', '<=', $date))
            ->sum('duration_minutes');

        return Inertia::render('time/index', [
            'entries' => $entries,
            'projects' => $projects,
            'filters' => $filters,
            'totalMinutes' => (int) $totalMinutes,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => 'required|date|before_or_equal:today',
            'hours' => 'required|numeric|min:0.25|max:24',
            ...$this->contextRules($request->user()),
            'description' => 'nullable|string|max:500',
            'billable' => 'nullable|boolean',
        ]);

        $this->service->log(auth()->user(), [
            ...$data,
            'billable' => $request->boolean('billable', true),
        ]);

        return back()->with('success', 'Time entry logged.');
    }

    public function update(Request $request, TimeEntry $entry): RedirectResponse
    {
        abort_unless($entry->user_id === auth()->id(), 403);

        $data = $request->validate([
            'date' => 'required|date|before_or_equal:today',
            // Manual creation keeps its 15-minute floor. An existing entry may hold any
            // whole-minute duration a timer produced, so edits only require that the
            // value still converts to at least one stored minute (0.01h = 0.6min -> 1).
            'hours' => 'required|numeric|min:0.01|max:24',
            ...$this->contextRules($request->user(), $this->keepsTask($request, $entry)),
            'description' => 'nullable|string|max:500',
            'billable' => 'nullable|boolean',
        ]);

        $this->service->update($entry, [
            ...Arr::except($data, self::CONTEXT_KEYS),
            ...$this->submittedContext($request, $entry),
            'description' => $request->input('description'),
            'billable' => $request->boolean('billable', true),
        ]);

        return back()->with('success', 'Entry updated.');
    }

    public function destroy(TimeEntry $entry): RedirectResponse
    {
        abort_unless($entry->user_id === auth()->id(), 403);

        $this->service->delete($entry);

        return back()->with('success', 'Entry deleted.');
    }

    // ── Timer ────────────────────────────────────────────────

    public function timerStart(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->contextRules($request->user()),
            'description' => 'nullable|string|max:500',
            'billable' => 'nullable|boolean',
        ]);

        $entry = $this->service->startTimer(
            auth()->user(),
            [
                ...$data,
                'billable' => $request->boolean('billable', true),
            ],
        );

        $entry->load(['project', 'task', 'ticket']);

        return response()->json($this->buildTimerPayload($entry, now()->toISOString()));
    }

    public function timerStop(TimeEntry $entry): JsonResponse
    {
        abort_unless($entry->user_id === auth()->id(), 403);

        $entry = $this->service->stopTimer($entry);

        return response()->json([
            'id' => $entry->id,
            'duration_minutes' => $entry->duration_minutes,
            'duration_human' => $entry->durationForHumans(),
        ]);
    }

    public function updateTimerDescription(Request $request, TimeEntry $entry): JsonResponse
    {
        abort_unless($entry->user_id === auth()->id(), 403);

        $request->validate(['description' => 'nullable|string|max:500']);

        $entry = $this->service->updateTimerDescription($entry, $request->description);

        return response()->json([
            'id' => $entry->id,
            'description' => $entry->description,
        ]);
    }

    // ── Active timers JSON (for global overlay) ──────────────

    public function activeTimersJson(): JsonResponse
    {
        $timers = $this->service->activeTimers(auth()->user());
        $serverNow = now()->toISOString();

        return response()->json(
            $timers->map(fn ($entry) => $this->buildTimerPayload($entry, $serverNow))
        );
    }

    // ── Context options for cascading selector ───────────────

    public function contextOptions(Request $request): JsonResponse
    {
        $user = auth()->user();
        $type = $request->input('type');

        $options = match ($type) {
            // Membership is the ProjectPolicy::view boundary that submission enforces,
            // so a project the creator has since left is not offered only to be rejected.
            'project' => Project::whereHas('members', fn ($m) => $m->where('user_id', $user->id))
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'label' => $p->name]),

            // open() is the kind-aware "not done": a board task by its column, a standalone or
            // ticket task by its status, so a Done-column task is no longer offered.
            'task' => Task::where('assignee_id', $user->id)
                ->open()
                ->with(['project', 'ticket'])
                ->orderBy('title')
                ->limit(50)
                ->get(['id', 'project_id', 'ticket_id', 'assignee_id', 'title'])
                ->filter(fn ($task) => AccessibleTimeContext::allows($user, 'task', $task->id))
                ->map(fn ($t) => ['id' => $t->id, 'label' => $t->title]),

            'ticket' => Ticket::where('assignee_id', $user->id)
                ->whereIn('status', ['open', 'in_progress'])
                ->orderBy('ticket_number')
                ->limit(50)
                ->get(['id', 'user_id', 'assignee_id', 'ticket_number', 'title'])
                ->filter(fn ($ticket) => AccessibleTimeContext::allows($user, 'ticket', $ticket->id))
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'label' => $t->ticket_number.' — '.Str::limit($t->title, 40),
                ]),

            default => collect(),
        };

        return response()->json($options->values());
    }

    // ── Allocation chart ─────────────────────────────────────

    public function allocationView(Request $request): Response
    {
        $user = auth()->user();
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $date = $validated['date'] ?? today()->toDateString();

        $blocks = TimeEntryBlock::where('user_id', $user->id)
            ->where('block_date', $date)
            ->with(['timeEntry.project', 'timeEntry.task', 'timeEntry.ticket'])
            ->get()
            ->groupBy('time_entry_id');

        $entries = $blocks->map(function ($entryBlocks, $entryId): array {
            $entry = $entryBlocks->first()->timeEntry;

            return [
                'id' => (int) $entryId,
                'description' => $entry->description,
                'locked' => $entry->isLockedForBilling(),
                'context' => $this->buildContextPayload($entry),
                'blocks' => $entryBlocks
                    ->sortBy('block_number')
                    ->map(fn (TimeEntryBlock $block) => [
                        'id' => $block->id,
                        'blockNumber' => $block->block_number,
                        'allocationPct' => (float) $block->allocation_pct,
                        'isOverridden' => $block->is_overridden,
                    ])
                    ->values(),
            ];
        })->values();

        return Inertia::render('time/allocation', [
            'date' => $date,
            'entries' => $entries,
        ]);
    }

    public function updateBlockAllocation(Request $request, TimeEntryBlock $block): JsonResponse
    {
        abort_unless(
            $block->user_id === auth()->id() && $block->timeEntry->user_id === auth()->id(),
            403,
        );

        $data = $request->validate(['allocation_pct' => 'required|numeric|min:0|max:100']);

        $slotBlocks = $this->service->updateBlockAllocation($block, (float) $data['allocation_pct']);

        return response()->json([
            'slot' => [
                'block_date' => $block->block_date->toDateString(),
                'block_number' => $block->block_number,
                'allocation_pct' => round((float) $slotBlocks->sum('allocation_pct'), 2),
                'blocks' => $slotBlocks->map(fn (TimeEntryBlock $slotBlock) => [
                    'id' => $slotBlock->id,
                    'time_entry_id' => $slotBlock->time_entry_id,
                    'allocation_pct' => (float) $slotBlock->allocation_pct,
                    'is_overridden' => $slotBlock->is_overridden,
                    'locked' => $slotBlock->timeEntry->isLockedForBilling(),
                ])->values(),
            ],
        ]);
    }

    // ── Private ──────────────────────────────────────────────

    private function buildContextPayload(TimeEntry $entry): ?array
    {
        if ($entry->ticket_id && $entry->ticket) {
            return [
                'type' => 'Ticket',
                'id' => $entry->ticket_id,
                'label' => $entry->ticket->ticket_number.' — '.Str::limit($entry->ticket->title, 40),
                'url' => route('tickets.show', $entry->ticket),
            ];
        }

        if ($entry->task_id && $entry->task) {
            return [
                'type' => 'Task',
                'id' => $entry->task_id,
                'label' => Str::limit($entry->task->title, 50),
                'url' => $entry->task->project_id
                    ? route('projects.tasks.show', [$entry->task->project_id, $entry->task])
                    : null,
            ];
        }

        if ($entry->project_id && $entry->project) {
            return [
                'type' => 'Project',
                'id' => $entry->project_id,
                'label' => $entry->project->name,
                'url' => route('projects.board', $entry->project),
            ];
        }

        return null;
    }

    private function buildTimerPayload(TimeEntry $entry, string $serverNow): array
    {
        return [
            'id' => $entry->id,
            'started_at' => $entry->timer_started_at->toISOString(),
            'server_now' => $serverNow,
            'description' => $entry->description,
            'context' => $this->buildContextPayload($entry),
        ];
    }

    private function buildEntryPayload(TimeEntry $entry): array
    {
        $context = null;

        if ($entry->ticket) {
            $context = [
                'kind' => 'ticket',
                'id' => $entry->ticket_id,
                'label' => $entry->ticket->ticket_number,
                'url' => route('tickets.show', $entry->ticket),
            ];
        } elseif ($entry->task) {
            $context = [
                'kind' => 'task',
                'id' => $entry->task_id,
                'label' => Str::limit($entry->task->title, 50),
                'url' => $entry->task->project_id
                    ? route('projects.tasks.show', [$entry->task->project_id, $entry->task])
                    : null,
            ];
        } elseif ($entry->project) {
            $context = [
                'kind' => 'project',
                'id' => $entry->project_id,
                'label' => $entry->project->name,
                'url' => route('projects.board', $entry->project),
            ];
        }

        return [
            'id' => $entry->id,
            'date' => $entry->date->format('Y-m-d'),
            'durationMinutes' => $entry->duration_minutes,
            'durationHuman' => $entry->durationForHumans(),
            'hours' => round($entry->duration_minutes / 60, 2),
            'description' => $entry->description,
            'billable' => $entry->billable,
            'billed' => $entry->billed,
            'invoiceLinked' => $entry->invoice_id !== null,
            'locked' => $entry->isLockedForBilling(),
            'running' => $entry->isRunning(),
            'context' => $context,
        ];
    }

    /**
     * The context an update writes (EPIC-014 owner decision (c)). The context is one of
     * project/task/ticket, so it is replaced as a whole or not at all:
     *   - none of the three keys sent: nothing is written, the stored context stays (absence is
     *     not a request to clear; TimeEntryService already reads an absent key as "keep");
     *   - the triple sent equals the stored one: nothing is written either, so an edit of
     *     unrelated fields never rewrites (or, racing another edit, reverts) the attribution;
     *   - otherwise the submitted triple replaces it, a key left out being null. An explicit
     *     `task_id: null` therefore still clears the task.
     *
     * @return array<string, int|string|null>
     */
    private function submittedContext(Request $request, TimeEntry $entry): array
    {
        if (! $request->hasAny(self::CONTEXT_KEYS)) {
            return [];
        }

        $submitted = [];
        foreach (self::CONTEXT_KEYS as $key) {
            $value = $request->input($key);
            $submitted[$key] = $value === null ? null : (int) $value;
        }

        return $submitted === $entry->only(self::CONTEXT_KEYS) ? [] : $submitted;
    }

    /**
     * Owner decision (c): an entry that already references a task keeps that UNCHANGED task
     * without the actor's current eligibility. Eligibility still governs every new or changed
     * attribution (a new entry, a timer, a switch to another task, adding a task), and project
     * or ticket contexts are unaffected. Billing locks are enforced by the service regardless.
     */
    private function keepsTask(Request $request, TimeEntry $entry): bool
    {
        $taskId = $request->input('task_id');

        return $entry->task_id !== null && is_numeric($taskId) && (int) $taskId === $entry->task_id;
    }

    private function contextRules(User $user, bool $keepsTask = false): array
    {
        return [
            'project_id' => [
                'nullable',
                'integer',
                'prohibits:task_id,ticket_id',
                new AccessibleTimeContext($user, 'project'),
            ],
            'task_id' => array_values(array_filter([
                'nullable',
                'integer',
                'prohibits:project_id,ticket_id',
                $keepsTask ? null : new AccessibleTimeContext($user, 'task'),
            ])),
            'ticket_id' => [
                'nullable',
                'integer',
                'prohibits:project_id,task_id',
                new AccessibleTimeContext($user, 'ticket'),
            ],
        ];
    }
}
