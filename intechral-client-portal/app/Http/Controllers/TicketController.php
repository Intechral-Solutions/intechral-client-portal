<?php

namespace App\Http\Controllers;

use App\Models\CrmCompany;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Rules\AccessibleCrmCompany;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TicketController extends Controller
{
    public const CATEGORIES = ['General', 'Technical', 'Billing', 'Account', 'Other'];

    public function index(Request $request)
    {
        /** @var User $user */
        $user       = auth()->user();
        $companyIds = $user->can('tickets.view_org') ? $user->orgCompanyIds() : [];

        $tickets = Ticket::where(function ($q) use ($user, $companyIds) {
                $q->forUser($user);
                if (! empty($companyIds)) {
                    $q->orWhereIn('company_id', $companyIds);
                }
            })
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('tickets.index', compact('tickets'));
    }

    public function create()
    {
        /** @var User $user */
        $user        = auth()->user();
        $companyIds  = $user->orgCompanyIds();
        $companies   = count($companyIds) > 1
            ? CrmCompany::whereIn('id', $companyIds)->orderBy('name')->get(['id', 'name'])
            : collect();
        $autoCompanyId = count($companyIds) === 1 ? $companyIds[0] : null;

        return view('tickets.create', [
            'categories'    => self::CATEGORIES,
            'companies'     => $companies,
            'autoCompanyId' => $autoCompanyId,
        ]);
    }

    public function store(Request $request, TicketService $service)
    {
        $validated = $request->validate([
            'title'         => ['required', 'string', 'max:255'],
            'description'   => ['required', 'string'],
            'category'      => ['required', 'string', 'in:' . implode(',', self::CATEGORIES)],
            'priority'      => ['required', 'in:low,medium,high,critical'],
            'company_id'    => ['nullable', new AccessibleCrmCompany],
            'attachments'   => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:20480'], // 20 MB each
        ]);

        // Auto-assign single-org users so they never need to pick
        if (empty($validated['company_id'])) {
            $ids = auth()->user()->orgCompanyIds();
            if (count($ids) === 1) {
                $validated['company_id'] = $ids[0];
            }
        }

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
