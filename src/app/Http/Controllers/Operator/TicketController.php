<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TicketController as UserTicketController;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use App\Support\Helpdesk\DueDateInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        // The assignee FILTER lists every holder, suspended ones included, because it searches existing assignments;
        // the bulk "Assign to" choices are active, operator-eligible holders only (Release 0.9, H3; R1-1).
        $operators = User::permission('tickets.assign')->orderBy('name')->get();
        $assignableOperators = $operators->filter(fn (User $operator) => TicketService::isAssignable($operator))->values();
        $includeInternal = $request->user()->can('viewInternal', Ticket::class);

        // R1-2B: an exact organization, by id. Only a whole positive number filters; anything else (text, a list, zero, a negative) is ignored,
        // never looked up, and never echoed: the view is handed this validated value, not the raw query.
        $organization = $request->query('organization');
        $organizationFilter = is_string($organization) && ctype_digit($organization) && (int) $organization > 0 ? (int) $organization : null;

        $tickets = Ticket::with(['user', 'assignee', 'organization:id,name'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search, $includeInternal))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->priority))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->filled('assignee'), fn ($q) => $q->where('assignee_id', $request->assignee))
            ->when($organizationFilter !== null, fn ($q) => $q->where('organization_id', $organizationFilter))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->orderByRaw("CASE status WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'pending_user' THEN 3 WHEN 'resolved' THEN 4 ELSE 5 END")
            ->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderByEffectiveDue()
            ->paginate(30)
            ->withQueryString();

        $categories = UserTicketController::CATEGORIES;

        // The organizations that have at least one ticket, for the filter: an operator works every ticket, so every ticket's organization is
        // already theirs to see. Read from the tables, not through the membership scope (an operator holds no membership in most of them).
        $organizations = DB::table('organizations')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('tickets')->whereColumn('tickets.organization_id', 'organizations.id'))
            ->orderBy('name')->orderBy('id')->get(['id', 'name']);

        return view('operator.tickets.index', compact('tickets', 'operators', 'assignableOperators', 'categories', 'organizations', 'organizationFilter'));
    }

    public function show(Ticket $ticket, TicketService $service)
    {
        $this->authorize('view', $ticket);

        $ticket->load([
            'user',
            'submittedBy',
            'organization',
            'assignee',
            'attachments',
            'statusHistories.user',
            'replies.user',
            'replies.attachments',
            'replies.dueDateChange',
        ]);

        $operators = User::permission('tickets.assign')->active()->orderBy('name')->get()
            ->filter(fn (User $operator) => TicketService::isAssignable($operator))->values();
        $categories = UserTicketController::CATEGORIES;

        // Release 1.0: the due-date form states its zone and bounds itself (the browser's zone is never consulted).
        $dueZone = DueDateInput::zoneLabel();
        $dueDays = TicketService::DUE_OVERRIDE_MAX_DAYS;
        $dueMin = DueDateInput::format(now()->addMinute());
        $dueMax = DueDateInput::format(now()->addDays($dueDays));

        return view('operator.tickets.show', compact('ticket', 'operators', 'categories', 'dueZone', 'dueDays', 'dueMin', 'dueMax'));
    }

    public function updateStatus(Request $request, Ticket $ticket, TicketService $service)
    {
        $this->authorize('view', $ticket);

        $validated = $request->validate([
            'status' => ['required', 'in:open,in_progress,pending_user,resolved,closed'],
        ]);

        $service->transition($ticket, auth()->user(), $validated['status']);

        return back()->with('status', 'Ticket status updated to "'.Ticket::operatorStatusLabel($validated['status']).'".');
    }

    public function assign(Request $request, Ticket $ticket, TicketService $service)
    {
        $this->authorize('view', $ticket);

        // `present` makes an absent key a validation error; an explicit blank value is the
        // intentional single-ticket unassign. Eligibility is enforced by TicketService::assign.
        $validated = $request->validate([
            'assignee_id' => ['present', 'nullable', 'integer', 'exists:users,id'],
        ]);

        $service->assign($ticket, $validated['assignee_id'] === null ? null : (int) $validated['assignee_id'], auth()->user());

        return back()->with('status', 'Ticket assignment updated.');
    }
}
