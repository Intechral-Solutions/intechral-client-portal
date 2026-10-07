@extends('layouts.app', ['title' => 'My Tickets'])

@section('content')
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-text">My Tickets</h1>
            <p class="mt-1 text-sm text-text-secondary">Track the status of your support requests.</p>
        </div>
        @can('tickets.create')
        <x-ui.button :href="route('tickets.create')" class="shrink-0">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
            </svg>
            New Ticket
        </x-ui.button>
        @endcan
    </div>

    {{-- Flash --}}
    @if (session('status'))
    <x-ui.alert variant="success" class="mb-6">{{ session('status') }}</x-ui.alert>
    @endif

    {{-- Filters --}}
    <form method="GET" action="{{ route('tickets.index') }}" class="mb-6 flex flex-wrap gap-2">
        <x-ui.input type="search" name="search" :value="request('search')"
                    placeholder="Search tickets&hellip;" />
        <x-ui.select name="status" aria-label="Status">
            <option value="">All statuses</option>
            @foreach (['open', 'in_progress', 'pending_user', 'resolved', 'closed'] as $s)
            <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ str_replace('_', ' ', ucfirst($s)) }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.button type="submit" variant="secondary">Filter</x-ui.button>
        @if (request()->hasAny(['search', 'status']))
        <x-ui.button :href="route('tickets.index')" variant="secondary">Clear</x-ui.button>
        @endif
    </form>

    {{-- Table --}}
    <div class="overflow-hidden rounded-lg border border-rule bg-surface">
        <table class="min-w-full divide-y divide-rule">
            <thead>
                <tr class="bg-surface-sunken">
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-secondary">Ticket</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-secondary">Priority</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-secondary">Status</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-text-secondary">Created</th>
                    <th scope="col" class="relative px-6 py-3"><span class="sr-only">View</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-rule">
                @forelse ($tickets as $ticket)
                <tr class="transition-colors hover:bg-surface-hover">
                    <td class="px-6 py-4">
                        <p class="font-medium text-sm text-text">{{ $ticket->title }}</p>
                        <p class="text-xs mt-0.5 text-text-secondary">{{ $ticket->ticket_number }} &middot; {{ $ticket->category }}</p>
                    </td>
                    <td class="px-6 py-4">
                        @include('tickets._priority_badge', ['priority' => $ticket->priority])
                    </td>
                    <td class="px-6 py-4">
                        @include('tickets._status_badge', ['status' => $ticket->status])
                    </td>
                    <td class="px-6 py-4 text-sm text-text-secondary">
                        {{ $ticket->created_at->diffForHumans() }}
                    </td>
                    <td class="px-6 py-4 text-right">
                        <x-ui.link :href="route('tickets.show', $ticket)" class="text-sm font-medium">View</x-ui.link>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-6 py-10 text-center text-sm text-text-secondary">
                        No tickets found.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($tickets->hasPages())
    <div class="mt-6">{{ $tickets->links() }}</div>
    @endif

</div>
@endsection
