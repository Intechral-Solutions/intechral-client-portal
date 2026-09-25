@extends('layouts.app', ['title' => 'My Tickets'])

@section('content')
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">

    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">My Tickets</h1>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">Track the status of your support requests.</p>
        </div>
        @can('tickets.create')
        <a href="{{ route('tickets.create') }}"
           class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-colors"
           style="background-color: var(--accent); color: #fff;">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
            </svg>
            New Ticket
        </a>
        @endcan
    </div>

    {{-- Flash --}}
    @if (session('status'))
    <div class="mb-6 rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);"
         role="alert">{{ session('status') }}</div>
    @endif

    {{-- Filters --}}
    <form method="GET" action="{{ route('tickets.index') }}" class="mb-6 flex flex-wrap gap-2">
        <input type="search" name="search" value="{{ request('search') }}"
               placeholder="Search tickets&hellip;"
               class="block rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2"
               style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
        <select name="status"
                class="rounded-lg border px-3 py-2 text-sm outline-none"
                style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
            <option value="">All statuses</option>
            @foreach (['open', 'in_progress', 'pending_user', 'resolved', 'closed'] as $s)
            <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ str_replace('_', ' ', ucfirst($s)) }}</option>
            @endforeach
        </select>
        <button type="submit"
                class="rounded-lg border px-4 py-2 text-sm font-medium transition-colors hover:legacy-bg-surface"
                style="border-color: var(--border-base); color: var(--text-secondary);">
            Filter
        </button>
        @if (request()->hasAny(['search', 'status']))
        <a href="{{ route('tickets.index') }}"
           class="rounded-lg border px-4 py-2 text-sm font-medium transition-colors hover:legacy-bg-surface"
           style="border-color: var(--border-base); color: var(--text-secondary);">Clear</a>
        @endif
    </form>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border" style="border-color: var(--border-base); background-color: var(--surface-base);">
        <table class="min-w-full divide-y" style="border-color: var(--border-subtle);">
            <thead>
                <tr style="background-color: var(--surface-elevated);">
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Ticket</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Priority</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Status</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Created</th>
                    <th scope="col" class="relative px-6 py-3"><span class="sr-only">View</span></th>
                </tr>
            </thead>
            <tbody class="divide-y" style="border-color: var(--border-subtle);">
                @forelse ($tickets as $ticket)
                <tr class="transition-colors hover:legacy-bg-surface">
                    <td class="px-6 py-4">
                        <p class="font-medium text-sm" style="color: var(--text-primary);">{{ $ticket->title }}</p>
                        <p class="text-xs mt-0.5" style="color: var(--text-secondary);">{{ $ticket->ticket_number }} &middot; {{ $ticket->category }}</p>
                    </td>
                    <td class="px-6 py-4">
                        @include('tickets._priority_badge', ['priority' => $ticket->priority])
                    </td>
                    <td class="px-6 py-4">
                        @include('tickets._status_badge', ['status' => $ticket->status])
                    </td>
                    <td class="px-6 py-4 text-sm" style="color: var(--text-secondary);">
                        {{ $ticket->created_at->diffForHumans() }}
                    </td>
                    <td class="px-6 py-4 text-right">
                        <a href="{{ route('tickets.show', $ticket) }}"
                           class="text-sm font-medium transition-colors hover:underline"
                           style="color: var(--accent);">View</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-6 py-10 text-center text-sm" style="color: var(--text-secondary);">
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
