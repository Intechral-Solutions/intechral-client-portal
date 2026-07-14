<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TicketController as UserTicketController;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        $operators = User::permission('tickets.assign')->orderBy('name')->get();

        $tickets = Ticket::with(['user', 'assignee'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->priority))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->filled('assignee'), fn ($q) => $q->where('assignee_id', $request->assignee))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->orderByRaw("CASE status WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'pending_user' THEN 3 WHEN 'resolved' THEN 4 ELSE 5 END")
            ->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderBy('sla_due_at')
            ->paginate(30)
            ->withQueryString();

        return view('operator.tickets.index', compact('tickets', 'operators'));
    }

    public function show(Ticket $ticket, TicketService $service)
    {
        $ticket->load([
            'user',
            'assignee',
            'attachments',
            'statusHistories.user',
            'replies.user',
            'replies.attachments',
        ]);

        $operators = User::permission('tickets.assign')->orderBy('name')->get();
        $categories = UserTicketController::CATEGORIES;

        return view('operator.tickets.show', compact('ticket', 'operators', 'categories'));
    }

    public function updateStatus(Request $request, Ticket $ticket, TicketService $service)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:open,in_progress,pending_user,resolved,closed'],
        ]);

        $service->transition($ticket, auth()->user(), $validated['status']);

        return back()->with('status', "Ticket status updated to \"{$validated['status']}\".");
    }

    public function assign(Request $request, Ticket $ticket, TicketService $service)
    {
        $validated = $request->validate([
            'assignee_id' => ['nullable', 'exists:users,id'],
        ]);

        $service->assign($ticket, $validated['assignee_id'] ?: null, auth()->user());

        return back()->with('status', 'Ticket assignment updated.');
    }
}
