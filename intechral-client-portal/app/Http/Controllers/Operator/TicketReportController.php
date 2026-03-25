<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketReportController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->date('date_from', 'Y-m-d') ?? now()->subDays(30)->startOfDay();
        $to   = $request->date('date_to', 'Y-m-d') ?? now()->endOfDay();

        // Volume by day
        $volumeByDay = Ticket::whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->pluck('count', 'date');

        // Volume by category
        $byCategory = Ticket::whereBetween('created_at', [$from, $to])
            ->selectRaw('category, COUNT(*) as count')
            ->groupBy('category')
            ->orderByDesc('count')
            ->pluck('count', 'category');

        // Volume by priority
        $byPriority = Ticket::whereBetween('created_at', [$from, $to])
            ->selectRaw('priority, COUNT(*) as count')
            ->groupBy('priority')
            ->orderByDesc('count')
            ->pluck('count', 'priority');

        // Average resolution time (in hours) by assignee
        $avgResolutionByAssignee = Ticket::whereBetween('resolved_at', [$from, $to])
            ->whereNotNull('assignee_id')
            ->whereNotNull('resolved_at')
            ->join('users', 'users.id', '=', 'tickets.assignee_id')
            ->selectRaw('users.name, AVG(TIMESTAMPDIFF(HOUR, tickets.created_at, tickets.resolved_at)) as avg_hours, COUNT(*) as count')
            ->groupBy('tickets.assignee_id', 'users.name')
            ->orderBy('avg_hours')
            ->get();

        return view('operator.tickets.reports', compact(
            'volumeByDay',
            'byCategory',
            'byPriority',
            'avgResolutionByAssignee',
            'from',
            'to',
        ));
    }

    public function export(Request $request)
    {
        $from = $request->date('date_from', 'Y-m-d') ?? now()->subDays(30)->startOfDay();
        $to   = $request->date('date_to', 'Y-m-d') ?? now()->endOfDay();

        $tickets = Ticket::with('user', 'assignee')
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->get();

        $filename = 'tickets-' . now()->format('Y-m-d') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($tickets) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Ticket #', 'Title', 'Category', 'Priority', 'Status', 'Submitter', 'Assignee', 'Created', 'Resolved', 'SLA Due']);

            foreach ($tickets as $t) {
                fputcsv($out, [
                    $t->ticket_number,
                    $t->title,
                    $t->category,
                    $t->priority,
                    $t->status,
                    $t->user->name,
                    $t->assignee?->name ?? '',
                    $t->created_at->toDateTimeString(),
                    $t->resolved_at?->toDateTimeString() ?? '',
                    $t->sla_due_at?->toDateTimeString() ?? '',
                ]);
            }
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }
}
