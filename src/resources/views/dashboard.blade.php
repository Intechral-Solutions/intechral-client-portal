@extends('layouts.app', ['title' => 'Dashboard'])

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8">
        <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">
            Welcome back, {{ auth()->user()->name }}
        </h1>
        <p class="mt-1 text-sm" style="color: var(--text-secondary);">
            Here's what's happening across your portal.
        </p>
        @env(['local', 'testing'])
        <a href="{{ route('inertia.smoke') }}" class="mt-2 inline-block text-xs hover:underline" style="color: var(--accent);">
            Frontend foundation smoke proof
        </a>
        @endenv
    </div>

    {{-- ── Stat cards ─────────────────────────────────────────── --}}
    <div class="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-2 lg:grid-cols-4">

        @can('tickets.view')
        <a href="{{ route('tickets.index') }}"
           class="block rounded-xl border p-5 transition-shadow hover:shadow-theme-md"
           style="background-color: var(--surface-card); border-color: var(--border-base);">
            <p class="text-xs font-semibold uppercase tracking-wide mb-1" style="color: var(--text-muted);">
                Open Tickets
            </p>
            <p class="text-3xl font-bold" style="color: var(--text-primary);">{{ $openTicketCount }}</p>
            <p class="mt-1 text-xs" style="color: {{ $openTicketCount > 0 ? 'var(--text-warning)' : 'var(--text-muted)' }};">
                {{ $openTicketCount > 0 ? 'Needs attention' : 'All clear' }}
            </p>
        </a>
        @endcan

        @can('projects.view')
        <a href="{{ route('projects.index') }}"
           class="block rounded-xl border p-5 transition-shadow hover:shadow-theme-md"
           style="background-color: var(--surface-card); border-color: var(--border-base);">
            <p class="text-xs font-semibold uppercase tracking-wide mb-1" style="color: var(--text-muted);">
                Active Projects
            </p>
            <p class="text-3xl font-bold" style="color: var(--text-primary);">{{ $activeProjectCount }}</p>
            <p class="mt-1 text-xs" style="color: var(--text-muted);">In progress</p>
        </a>
        @endcan

        @can('time.log')
        <a href="{{ route('time.index') }}"
           class="block rounded-xl border p-5 transition-shadow hover:shadow-theme-md"
           style="background-color: var(--surface-card); border-color: var(--border-base);">
            <p class="text-xs font-semibold uppercase tracking-wide mb-1" style="color: var(--text-muted);">
                Time This Month
            </p>
            <p class="text-3xl font-bold" style="color: var(--text-primary);">
                {{ number_format($timeThisMonth / 60, 1) }}h
            </p>
            @if ($unbilledMinutes > 0)
            <p class="mt-1 text-xs" style="color: var(--text-info);">
                {{ number_format($unbilledMinutes / 60, 1) }}h unbilled
            </p>
            @else
            <p class="mt-1 text-xs" style="color: var(--text-muted);">This month</p>
            @endif
        </a>
        @endcan

        @if (auth()->user()->can('billing.view') || auth()->user()->can('billing.manage'))
        @php
            $billingRoute = auth()->user()->can('billing.manage')
                ? route('billing.invoices.index')
                : route('billing.client.invoices.index');
        @endphp
        <a href="{{ $billingRoute }}"
           class="block rounded-xl border p-5 transition-shadow hover:shadow-theme-md"
           style="background-color: var(--surface-card); border-color: var(--border-base);">
            <p class="text-xs font-semibold uppercase tracking-wide mb-1" style="color: var(--text-muted);">
                Outstanding Invoices
            </p>
            <p class="text-3xl font-bold" style="color: var(--text-primary);">{{ $outstandingInvoiceCount }}</p>
            <p class="mt-1 text-xs" style="color: {{ $outstandingInvoiceCount > 0 ? 'var(--text-danger)' : 'var(--text-muted)' }};">
                {{ $outstandingInvoiceCount > 0 ? 'Awaiting payment' : 'All settled' }}
            </p>
        </a>
        @endif

    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- ── Recent tickets ──────────────────────────────── --}}
        @can('tickets.view')
        <div class="lg:col-span-2 rounded-xl border overflow-hidden"
             style="background-color: var(--surface-card); border-color: var(--border-base);">
            <div class="flex items-center justify-between px-5 py-4 border-b"
                 style="border-color: var(--border-base);">
                <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Recent Tickets</h2>
                <a href="{{ route('tickets.index') }}" class="text-xs hover:underline" style="color: var(--accent);">
                    View all →
                </a>
            </div>
            <div class="divide-y" style="divide-color: var(--border-base);">
                @forelse ($recentTickets as $ticket)
                @php
                    $statusColors = [
                        'open'         => 'var(--text-info)',
                        'in_progress'  => 'var(--text-warning)',
                        'pending_user' => 'var(--text-muted)',
                        'resolved'     => 'var(--text-success)',
                        'closed'       => 'var(--text-muted)',
                    ];
                    $statusColor = $statusColors[$ticket->status] ?? 'var(--text-muted)';
                    $statusLabel = str_replace('_', ' ', ucfirst($ticket->status));
                @endphp
                <a href="{{ auth()->user()->can('tickets.assign') ? route('operator.tickets.show', $ticket) : route('tickets.show', $ticket) }}"
                   class="flex items-center justify-between px-5 py-3 transition-colors hover:bg-surface">
                    <div class="min-w-0">
                        <p class="text-sm font-medium truncate" style="color: var(--text-primary);">
                            {{ $ticket->subject }}
                        </p>
                        @if ($ticket->user)
                        <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                            {{ $ticket->user->name }} · {{ $ticket->created_at->diffForHumans() }}
                        </p>
                        @endif
                    </div>
                    <span class="ml-3 shrink-0 text-xs font-medium capitalize" style="color: {{ $statusColor }};">
                        {{ $statusLabel }}
                    </span>
                </a>
                @empty
                <div class="px-5 py-10 text-center">
                    <p class="text-sm" style="color: var(--text-muted);">No open tickets.</p>
                    @can('tickets.create')
                    <a href="{{ route('tickets.create') }}" class="mt-2 inline-block text-xs hover:underline" style="color: var(--accent);">
                        Submit a ticket →
                    </a>
                    @endcan
                </div>
                @endforelse
            </div>
        </div>
        @endcan

        {{-- ── Right column ────────────────────────────────── --}}
        <div class="space-y-6">

            {{-- Quick actions --}}
            <div class="rounded-xl border p-5"
                 style="background-color: var(--surface-card); border-color: var(--border-base);">
                <h2 class="mb-3 text-sm font-semibold" style="color: var(--text-primary);">Quick Actions</h2>
                <div class="space-y-2">
                    @can('tickets.create')
                    <a href="{{ route('tickets.create') }}"
                       class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors hover:bg-surface"
                       style="color: var(--text-secondary);">
                        <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        New Ticket
                    </a>
                    @endcan
                    @can('time.log')
                    <a href="{{ route('time.index') }}"
                       class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors hover:bg-surface"
                       style="color: var(--text-secondary);">
                        <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        Log Time
                    </a>
                    @endcan
                    @can('projects.view')
                    <a href="{{ route('projects.index') }}"
                       class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors hover:bg-surface"
                       style="color: var(--text-secondary);">
                        <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" /></svg>
                        My Projects
                    </a>
                    @endcan
                    <a href="{{ route('profile.show') }}"
                       class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors hover:bg-surface"
                       style="color: var(--text-secondary);">
                        <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                        My Profile
                    </a>
                </div>
            </div>

            {{-- CRM summary (operator only) --}}
            @if ($crmStats)
            <div class="rounded-xl border p-5"
                 style="background-color: var(--surface-card); border-color: var(--border-base);">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-semibold" style="color: var(--text-primary);">CRM</h2>
                    <a href="{{ route('crm.companies.index') }}" class="text-xs hover:underline" style="color: var(--accent);">
                        Open →
                    </a>
                </div>
                <div class="grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-lg p-2" style="background-color: var(--bg-surface);">
                        <p class="text-xl font-bold" style="color: var(--text-primary);">{{ $crmStats['companies'] }}</p>
                        <p class="text-xs" style="color: var(--text-muted);">Companies</p>
                    </div>
                    <div class="rounded-lg p-2" style="background-color: var(--bg-surface);">
                        <p class="text-xl font-bold" style="color: var(--text-primary);">{{ $crmStats['contacts'] }}</p>
                        <p class="text-xs" style="color: var(--text-muted);">Contacts</p>
                    </div>
                    <div class="rounded-lg p-2" style="background-color: var(--bg-surface);">
                        <p class="text-xl font-bold" style="color: var(--text-primary);">{{ $crmStats['orgs'] }}</p>
                        <p class="text-xs" style="color: var(--text-muted);">Orgs</p>
                    </div>
                </div>
            </div>
            @endif

        </div>
    </div>

</div>
@endsection
