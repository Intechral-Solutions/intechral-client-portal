@extends('layouts.app', ['title' => 'Ticket Queue'])

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-text">Ticket Queue</h1>
            <p class="mt-1 text-sm text-text-secondary">Triage, assign, and manage all support requests.</p>
        </div>
        <div class="flex items-center gap-2">
            <x-ui.button :href="route('operator.tickets.reports')" variant="secondary">Reports</x-ui.button>
        </div>
    </div>

    {{-- Flash --}}
    @if (session('status'))
    <x-ui.alert variant="success" class="mb-6">{{ session('status') }}</x-ui.alert>
    @endif
    @if ($errors->any())
    <x-ui.alert variant="danger" class="mb-6">{{ $errors->first() }}</x-ui.alert>
    @endif

    {{-- Filters --}}
    <form method="GET" action="{{ route('operator.tickets.index') }}" class="mb-6 flex flex-wrap gap-2">
        <x-ui.input type="search" name="search" :value="request('search')"
                    placeholder="Search&hellip;" class="min-w-45" />
        @foreach (['status' => ['open', 'in_progress', 'pending_user', 'resolved', 'closed'], 'priority' => ['low', 'medium', 'high', 'critical']] as $field => $opts)
        <x-ui.select :name="$field" :aria-label="ucfirst($field)">
            <option value="">All {{ ucfirst($field) }}s</option>
            @foreach ($opts as $opt)
            <option value="{{ $opt }}" {{ request($field) === $opt ? 'selected' : '' }}>
                {{ str_replace('_', ' ', ucfirst($opt)) }}
            </option>
            @endforeach
        </x-ui.select>
        @endforeach
        <x-ui.select name="assignee" aria-label="Assignee">
            <option value="">All Assignees</option>
            @foreach ($operators as $op)
            <option value="{{ $op->id }}" {{ request('assignee') == $op->id ? 'selected' : '' }}>{{ $op->name }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.input type="date" name="date_from" :value="request('date_from')" aria-label="Submitted from" />
        <x-ui.input type="date" name="date_to" :value="request('date_to')" aria-label="Submitted to" />
        <x-ui.button type="submit" variant="secondary">Filter</x-ui.button>
        @if (request()->hasAny(['search', 'status', 'priority', 'assignee', 'date_from', 'date_to']))
        <x-ui.button :href="route('operator.tickets.index')" variant="secondary">Clear</x-ui.button>
        @endif
    </form>

    {{-- Bulk action form --}}
    <form method="POST" action="{{ route('operator.tickets.bulk') }}" id="bulk-form">
        @csrf

    {{-- Table --}}
    <div class="overflow-x-auto rounded-lg border border-rule bg-surface">
        <table class="min-w-full divide-y divide-rule">
            <thead>
                <tr class="bg-surface-sunken">
                    <th class="px-4 py-3">
                        <x-ui.checkbox id="select-all" aria-label="Select all tickets on this page" />
                    </th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-secondary">Ticket</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-secondary">Priority</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-secondary">Status</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-secondary">Assignee</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-secondary">SLA Due</th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-secondary">Submitted</th>
                    <th scope="col" class="relative px-4 py-3"><span class="sr-only">View</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-rule">
                @forelse ($tickets as $ticket)
                <tr class="transition-colors hover:bg-surface-hover">
                    <td class="px-4 py-3">
                        <x-ui.checkbox name="ticket_ids[]" id="ticket-cb-{{ $ticket->id }}" value="{{ $ticket->id }}"
                                       aria-label="Select ticket {{ $ticket->ticket_number }}" class="ticket-cb" />
                    </td>
                    <td class="px-4 py-3">
                        <p class="font-medium text-sm text-text">{{ $ticket->title }}</p>
                        <p class="text-xs mt-0.5 text-text-secondary">{{ $ticket->ticket_number }} &middot; {{ $ticket->category }} &middot; {{ $ticket->user->name }}</p>
                    </td>
                    <td class="px-4 py-3">@include('tickets._priority_badge', ['priority' => $ticket->priority])</td>
                    <td class="px-4 py-3">@include('tickets._status_badge', ['status' => $ticket->status])</td>
                    <td class="px-4 py-3 text-sm text-text-secondary">
                        {{ $ticket->assignee?->name ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-sm {{ $ticket->isOverdue() ? 'font-medium text-danger' : 'text-text-secondary' }}">
                        {{ $ticket->sla_due_at?->format('d M H:i') ?? '—' }}
                        @if ($ticket->isOverdue())
                        @include('tickets._overdue_status', ['class' => 'ml-1'])
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-text-secondary">{{ $ticket->created_at->diffForHumans() }}</td>
                    <td class="px-4 py-3 text-right">
                        <x-ui.link :href="route('operator.tickets.show', $ticket)" class="text-sm font-medium">View</x-ui.link>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-6 py-10 text-center text-sm text-text-secondary">
                        No tickets found.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Bulk action bar --}}
    <div id="bulk-bar" class="hidden mt-4 flex items-center gap-3 rounded-lg border px-4 py-3 border-rule bg-surface-sunken">
        <span class="text-sm text-text-secondary"><span id="selected-count">0</span> selected</span>
        <x-ui.select name="action" required aria-label="Bulk action">
            <option value="">Choose action&hellip;</option>
            <option value="assign">Assign to&hellip;</option>
            <option value="resolve">Mark Resolved</option>
            <option value="close">Close</option>
        </x-ui.select>
        <x-ui.field-error for="action" />
        <x-ui.select name="assignee_id" aria-label="Assign to">
            <option value="">Select assignee&hellip;</option>
            @foreach ($operators as $op)
            <option value="{{ $op->id }}">{{ $op->name }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.field-error for="assignee_id" />
        <x-ui.button type="submit" variant="secondary">Apply</x-ui.button>
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
