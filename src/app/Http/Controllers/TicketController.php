<?php

namespace App\Http\Controllers;

use App\Http\Presenters\RequesterTicketPresenter;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Queries\RequesterTicketQuery;
use App\Rules\AllowedTicketAttachment;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The requester's Helpdesk (EPIC-017 WP2): `/tickets*` is the requester-safe surface. Every page
 * is built by RequesterTicketPresenter, so a customer-facing prop is whatever that file writes out
 * and nothing else. Operators work a ticket on `/operator/tickets/{ticket}` (P2).
 */
class TicketController extends Controller
{
    public const CATEGORIES = ['General', 'Technical', 'Billing', 'Account', 'Other'];

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        // Own Tickets only, searched over public text only (RequesterTicketQuery): the same
        // universe TicketPolicy::view grants a customer. Company membership grants no Ticket
        // visibility (EPIC-010D D1, H4), and internal notes never steer the requester list.
        $filters = RequesterTicketQuery::filters($request);
        $tickets = RequesterTicketQuery::paginate($user, $filters);

        return Inertia::render('tickets/index', [
            'tickets' => $tickets->through(fn (Ticket $ticket) => RequesterTicketPresenter::row($ticket)),
            'filters' => $filters,
            'statusOptions' => RequesterTicketPresenter::statusOptions(),
            'hasRequests' => $this->hasRequests($user, $tickets->total(), $filters),
            'canCreate' => $user->can('tickets.create'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('tickets/create', [
            'categories' => self::CATEGORIES,
            'priorities' => RequesterTicketPresenter::priorityOptions(),
            'defaultPriority' => 'medium',
            'attachmentPolicy' => RequesterTicketPresenter::attachmentPolicy(),
        ]);
    }

    public function store(Request $request, TicketService $service)
    {
        // R1-2: `organization_id` is a REQUEST: an integer, never `exists:` (that rule would tell a caller which organizations exist).
        // Whether the actor may file for it, and which organization is used when none is named, is decided inside the creation
        // transaction by ServiceRequestAccess. The submitter and requester are the authenticated user and nothing in the payload. The
        // old `company_id` input is gone: nothing ever persisted it, and the column is dropped.
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'category' => ['required', 'string', 'in:'.implode(',', self::CATEGORIES)],
            'priority' => ['required', 'in:low,medium,high,critical'],
            'organization_id' => ['nullable', 'integer', 'min:1'],
            ...AllowedTicketAttachment::rules(),
        ]);

        $ticket = $service->create(
            $request->user(),
            $validated,
            $request->file('attachments', []),
            isset($validated['organization_id']) ? (int) $validated['organization_id'] : null,
        );

        return redirect()->route('tickets.show', $ticket)
            ->with('status', "Request {$ticket->ticket_number} received.");
    }

    public function show(Request $request, Ticket $ticket): Response|SymfonyResponse
    {
        // First: nothing below runs for a viewer the policy refuses, so a redirect never confirms
        // a ticket exists to someone who cannot see it.
        $this->authorize('view', $ticket);

        // P2: the policy admits the requester and anyone working tickets. A viewer who is not the
        // requester can only be the latter, and their page is the operator workspace. Inertia::location
        // is a plain 302 for a document request and a 409 hard visit for an Inertia one, so an
        // Inertia visit never lands the Blade operator page in the client-visit error modal.
        if ($ticket->user_id !== $request->user()->id) {
            return Inertia::location(route('operator.tickets.show', $ticket));
        }

        $ticket->load([
            'user:id,name',
            'assignee:id,name',
            'attachments',
            'statusHistories' => fn ($query) => $query->orderBy('id'),
            // Public replies only, for every viewer, an operator who is the requester included.
            'replies' => fn ($query) => $query->where('is_internal', false)
                ->orderBy('id')
                ->with(['user:id,name', 'attachments']),
        ]);

        return Inertia::render('tickets/show', [
            ...RequesterTicketPresenter::detail($ticket),
            'abilities' => ['create' => $request->user()->can('tickets.create')],
        ]);
    }

    /**
     * The attachment is looked up BY the bound ticket (its `ticket_id`), so it covers the first message's files and every reply's,
     * and an attachment id that belongs to another ticket is a 404, never that ticket's file. The policy then decides, unchanged:
     * ticket visibility, and internal-note attachments only for those who may see internal notes.
     */
    public function downloadAttachment(Ticket $ticket, string $attachment)
    {
        // First: someone who cannot see the ticket learns nothing about which attachment ids it has.
        $this->authorize('view', $ticket);

        $attachment = TicketAttachment::where('ticket_id', $ticket->id)->findOrFail($attachment);

        $this->authorize('downloadAttachment', [$ticket, $attachment]);

        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->filename);
    }

    /**
     * Whether the requester has raised anything at all, so "no requests yet", "nothing matches" and
     * "that page does not exist" stay apart. An empty PAGE proves none of them. The paginator's total
     * answers it when there are matches; with no matches and no filters the list really is empty;
     * only a filtered-empty list needs the one extra existence query.
     *
     * @param  array{search: string|null, status: string|null}  $filters
     */
    private function hasRequests(User $user, int $matching, array $filters): bool
    {
        if ($matching > 0) {
            return true;
        }

        return array_filter($filters) !== [] && RequesterTicketQuery::hasRequests($user);
    }
}
