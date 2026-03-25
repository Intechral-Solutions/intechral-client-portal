<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TicketController extends Controller
{
    public const CATEGORIES = ['General', 'Technical', 'Billing', 'Account', 'Other'];

    public function index(Request $request)
    {
        $tickets = Ticket::forUser(auth()->user())
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('tickets.index', compact('tickets'));
    }

    public function create()
    {
        return view('tickets.create', ['categories' => self::CATEGORIES]);
    }

    public function store(Request $request, TicketService $service)
    {
        $validated = $request->validate([
            'title'         => ['required', 'string', 'max:255'],
            'description'   => ['required', 'string'],
            'category'      => ['required', 'string', 'in:' . implode(',', self::CATEGORIES)],
            'priority'      => ['required', 'in:low,medium,high,critical'],
            'attachments'   => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:20480'], // 20 MB each
        ]);

        $ticket = $service->create(
            auth()->user(),
            $validated,
            $request->file('attachments', []),
        );

        return redirect()->route('tickets.show', $ticket)
            ->with('status', "Ticket {$ticket->ticket_number} submitted successfully.");
    }

    public function show(Ticket $ticket)
    {
        $this->authorize('view', $ticket);

        $ticket->load([
            'user',
            'assignee',
            'attachments',
            'statusHistories.user',
            'replies' => fn ($q) => $q->with('user', 'attachments')
                ->when(! auth()->user()->can('tickets.assign'), fn ($q) => $q->where('is_internal', false)),
        ]);

        return view('tickets.show', compact('ticket'));
    }

    public function downloadAttachment(TicketAttachment $attachment)
    {
        $this->authorize('view', $attachment->ticket);

        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->filename);
    }
}
