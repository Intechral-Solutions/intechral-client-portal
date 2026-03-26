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
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        // ── Ticket stats ──────────────────────────────────────
        $openTicketCount = $user->can('tickets.assign')
            ? Ticket::whereNotIn('status', ['closed', 'resolved'])->count()
            : Ticket::where('user_id', $user->id)
                    ->whereNotIn('status', ['closed', 'resolved'])->count();

        // ── Project stats ─────────────────────────────────────
        $activeProjectCount = $user->can('projects.manage')
            ? Project::where('status', 'active')->count()
            : Project::where('status', 'active')
                     ->whereHas('members', fn ($q) => $q->where('user_id', $user->id))
                     ->count();

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
                'contacts'  => CrmContact::count(),
                'orgs'      => Organization::count(),
            ];
        }

        return view('dashboard', compact(
            'openTicketCount',
            'activeProjectCount',
            'timeThisMonth',
            'unbilledMinutes',
            'outstandingInvoiceCount',
            'recentTickets',
            'crmStats',
        ));
    }
}
