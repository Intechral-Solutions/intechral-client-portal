<?php

namespace App\Http\Controllers;

use App\Models\CrmCompany;
use App\Models\CrmContact;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        // ── Ticket stats ──────────────────────────────────────
        $openTicketCount = $user->can('tickets.assign')
            ? Ticket::whereNotIn('status', ['closed', 'resolved'])->count()
            : Ticket::with('user:id,name')
                ->where('user_id', $user->id)
                ->whereNotIn('status', ['closed', 'resolved'])->count();

        // ── Project stats ─────────────────────────────────────
        // The same set the projects index lists and ProjectPolicy::view allows (D2), so the
        // figure neither disagrees with the index nor discloses projects the viewer cannot open.
        $activeProjectCount = Project::visibleTo($user)->where('status', 'active')->count();

        // ── Time stats (current user) ─────────────────────────
        $timeThisMonth = TimeEntry::where('user_id', $user->id)
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->whereNull('timer_started_at')
            ->sum('duration_minutes');

        $unbilledMinutes = TimeEntry::where('user_id', $user->id)
            ->where('billable', true)
            ->where('billed', false)
            ->whereNull('timer_started_at')
            ->sum('duration_minutes');

        // ── Invoice stats ─────────────────────────────────────
        $outstandingInvoiceCount = $user->can('billing.manage')
            ? Invoice::whereIn('status', ['sent', 'overdue'])->count()
            : Invoice::where('client_id', $user->id)
                ->whereIn('status', ['sent', 'overdue'])->count();

        // ── Recent tickets ────────────────────────────────────
        $recentTickets = $user->can('tickets.assign')
            ? Ticket::with('user:id,name')
                ->whereNotIn('status', ['closed', 'resolved'])
                ->latest()
                ->limit(6)
                ->get()
            : Ticket::where('user_id', $user->id)
                ->latest()
                ->limit(6)
                ->get();

        // ── Operator-only CRM stats ───────────────────────────
        $crmStats = null;
        if ($user->can('crm.manage')) {
            $crmStats = [
                'companies' => CrmCompany::count(),
                'contacts' => CrmContact::count(),
                'orgs' => Organization::count(),
            ];
        }

        $metrics = array_values(array_filter([
            $user->can('tickets.view') ? $this->metric('tickets', 'Open Tickets', $openTicketCount, $openTicketCount ? 'Needs attention' : 'All clear', route('tickets.index')) : null,
            $user->can('projects.view') ? $this->metric('projects', 'Active Projects', $activeProjectCount, 'In progress', route('projects.index'), 'inertia') : null,
            $user->can('time.log') ? $this->metric('time', 'Time This Month', number_format($timeThisMonth / 60, 1).'h', $unbilledMinutes ? number_format($unbilledMinutes / 60, 1).'h unbilled' : 'This month', route('time.index'), 'inertia') : null,
            ($user->can('billing.view') || $user->can('billing.manage')) ? $this->metric('billing', 'Outstanding Invoices', $outstandingInvoiceCount, $outstandingInvoiceCount ? 'Awaiting payment' : 'All settled', $user->can('billing.manage') ? route('billing.invoices.index') : route('billing.client.invoices.index')) : null,
        ]));

        $quickActions = array_values(array_filter([
            $user->can('tickets.create') ? ['key' => 'new-ticket', 'label' => 'New Ticket', 'href' => route('tickets.create'), 'visit' => 'document'] : null,
            $user->can('time.log') ? ['key' => 'log-time', 'label' => 'Log Time', 'href' => route('time.index'), 'visit' => 'inertia'] : null,
            $user->can('projects.view') ? ['key' => 'projects', 'label' => 'My Projects', 'href' => route('projects.index'), 'visit' => 'inertia'] : null,
            ['key' => 'profile', 'label' => 'My Profile', 'href' => route('profile.show'), 'visit' => 'inertia'],
        ]));

        return Inertia::render('dashboard/index', [
            'metrics' => $metrics,
            'recentTickets' => $recentTickets->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'subject' => $ticket->title,
                'status' => $ticket->status,
                'statusLabel' => str_replace('_', ' ', ucfirst($ticket->status)),
                'requesterName' => $ticket->user?->name,
                'createdAtHuman' => $ticket->created_at->diffForHumans(),
                'href' => $user->can('tickets.assign')
                    ? route('operator.tickets.show', $ticket)
                    : route('tickets.show', $ticket),
                'visit' => 'document',
            ])->values(),
            'quickActions' => $quickActions,
            'crmSummary' => $crmStats ? [
                ...$crmStats,
                'href' => route('crm.companies.index'),
                'visit' => 'document',
            ] : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function metric(string $key, string $label, int|string $value, string $supportingText, string $href, string $visit = 'document'): array
    {
        return compact('key', 'label', 'value', 'supportingText', 'href', 'visit');
    }
}
