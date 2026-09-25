@extends('layouts.app', ['title' => 'Ticket Queue'])

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">Ticket Queue</h1>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">Triage, assign, and manage all support requests.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('operator.tickets.reports') }}"
               class="rounded-lg border px-4 py-2 text-sm font-medium transition-colors hover:bg-surface"
               style="border-color: var(--border-base); color: var(--text-secondary);">Reports</a>
        </div>
    </div>

    {{-- Flash --}}
    @if (session('status'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-danger); border-color: var(--border-danger); color: var(--text-danger);"
         role="alert">{{ $errors->first() }}</div>
    @endif

    {{-- Filters --}}
    <form method="GET" action="{{ route('operator.tickets.index') }}" class="mb-6 flex flex-wrap gap-2">
        <input type="search" name="search" value="{{ request('search') }}"
               placeholder="Search&hellip;"
               class="rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
               style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary); min-width:180px;">
        @foreach (['status' => ['open', 'in_progress', 'pending_user', 'resolved', 'closed'], 'priority' => ['low', 'medium', 'high', 'critical']] as $field => $opts)
        <select name="{{ $field }}"
                class="rounded-lg border px-3 py-2 text-sm outline-none"
                style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
            <option value="">All {{ ucfirst($field) }}s</option>
            @foreach ($opts as $opt)
            <option value="{{ $opt }}" {{ request($field) === $opt ? 'selected' : '' }}>
                {{ str_replace('_', ' ', ucfirst($opt)) }}
            </option>
            @endforeach
        </select>
        @endforeach
        <select name="assignee"
                class="rounded-lg border px-3 py-2 text-sm outline-none"
                style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
            <option value="">All Assignees</option>
            @foreach ($operators as $op)
            <option value="{{ $op->id }}" {{ request('assignee') == $op->id ? 'selected' : '' }}>{{ $op->name }}</option>
            @endforeach
        </select>
        <input type="date" name="date_from" value="{{ request('date_from') }}"
               class="rounded-lg border px-3 py-2 text-sm outline-none"
               style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
        <input type="date" name="date_to" value="{{ request('date_to') }}"
               class="rounded-lg border px-3 py-2 text-sm outline-none"
               style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
        <button type="submit"
                class="rounded-lg border px-4 py-2 text-sm font-medium transition-colors hover:bg-surface"
                style="border-color: var(--border-base); color: var(--text-secondary);">Filter</button>
        @if (request()->hasAny(['search', 'status', 'priority', 'assignee', 'date_from', 'date_to']))
        <a href="{{ route('operator.tickets.index') }}"
           class="rounded-lg border px-4 py-2 text-sm font-medium transition-colors hover:bg-surface"
           style="border-color: var(--border-base); color: var(--text-secondary);">Clear</a>
        @endif
    </form>

    {{-- Bulk action form --}}
    <form method="POST" action="{{ route('operator.tickets.bulk') }}" id="bulk-form">
        @csrf

    {{-- Table --}}
    <div class="overflow-x-auto rounded-xl border" style="border-color: var(--border-base); background-color: var(--surface-base);">
        <table class="min-w-full divide-y" style="border-color: var(--border-subtle);">
            <thead>
                <tr style="background-color: var(--surface-elevated);">
                    <th class="px-4 py-3">
                        <input type="checkbox" id="select-all" class="h-4 w-4 rounded accent-accent">
                    </th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Ticket</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Priority</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Status</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Assignee</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">SLA Due</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Submitted</th>
                    <th scope="col" class="relative px-4 py-3"><span class="sr-only">View</span></th>
                </tr>
            </thead>
            <tbody class="divide-y" style="border-color: var(--border-subtle);">
                @forelse ($tickets as $ticket)
                <tr class="transition-colors hover:bg-surface {{ $ticket->isOverdue() ? 'bg-red-50' : '' }}">
                    <td class="px-4 py-3">
                        <input type="checkbox" name="ticket_ids[]" value="{{ $ticket->id }}" class="h-4 w-4 rounded accent-accent ticket-cb">
                    </td>
                    <td class="px-4 py-3">
                        <p class="font-medium text-sm" style="color: var(--text-primary);">{{ $ticket->title }}</p>
                        <p class="text-xs mt-0.5" style="color: var(--text-secondary);">{{ $ticket->ticket_number }} &middot; {{ $ticket->category }} &middot; {{ $ticket->user->name }}</p>
                    </td>
                    <td class="px-4 py-3">@include('tickets._priority_badge', ['priority' => $ticket->priority])</td>
                    <td class="px-4 py-3">@include('tickets._status_badge', ['status' => $ticket->status])</td>
                    <td class="px-4 py-3 text-sm" style="color: var(--text-secondary);">
                        {{ $ticket->assignee?->name ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-sm {{ $ticket->isOverdue() ? '' : '' }}"
                        style="color: {{ $ticket->isOverdue() ? 'var(--text-danger)' : 'var(--text-secondary)' }};">
                        {{ $ticket->sla_due_at?->format('d M H:i') ?? '—' }}
                        @if ($ticket->isOverdue())
                        <span class="text-xs font-semibold" style="color: var(--text-danger);">Overdue</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm" style="color: var(--text-secondary);">{{ $ticket->created_at->diffForHumans() }}</td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('operator.tickets.show', $ticket) }}"
                           class="text-sm font-medium transition-colors hover:underline"
                           style="color: var(--accent);">View</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-6 py-10 text-center text-sm" style="color: var(--text-secondary);">
                        No tickets found.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Bulk action bar --}}
    <div id="bulk-bar" class="hidden mt-4 flex items-center gap-3 rounded-lg border px-4 py-3"
         style="border-color: var(--border-base); background-color: var(--surface-elevated);">
        <span class="text-sm" style="color: var(--text-secondary);"><span id="selected-count">0</span> selected</span>
        <select name="action" required
                class="rounded-lg border px-3 py-1.5 text-sm"
                style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
            <option value="">Choose action&hellip;</option>
            <option value="assign">Assign to&hellip;</option>
            <option value="resolve">Mark Resolved</option>
            <option value="close">Close</option>
        </select>
        <select name="assignee_id"
                class="rounded-lg border px-3 py-1.5 text-sm"
                style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
            <option value="">Select assignee&hellip;</option>
            @foreach ($operators as $op)
            <option value="{{ $op->id }}">{{ $op->name }}</option>
            @endforeach
        </select>
        <button type="submit"
                class="rounded-lg px-4 py-1.5 text-sm font-medium transition-colors"
                style="background-color: var(--accent); color: #fff;">
            Apply
        </button>
    </div>

    </form>

    @if ($tickets->hasPages())
    <div class="mt-6">{{ $tickets->links() }}</div>
    @endif

</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectAll = document.getElementById('select-all');
    const cbs       = () => document.querySelectorAll('.ticket-cb');
    const bar       = document.getElementById('bulk-bar');
    const count     = document.getElementById('selected-count');

    function update() {
        const checked = [...cbs()].filter(c => c.checked).length;
        count.textContent = checked;
        bar.classList.toggle('hidden', checked === 0);
    }

    selectAll.addEventListener('change', () => {
        cbs().forEach(c => c.checked = selectAll.checked);
        update();
    });

    document.addEventListener('change', e => {
        if (e.target.classList.contains('ticket-cb')) update();
    });
});
</script>
@endpush

@endsection
