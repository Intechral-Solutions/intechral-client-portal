@extends('layouts.app', ['title' => 'Ticket Reports'])

@section('content')
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8 space-y-8">

    <div class="flex items-start justify-between gap-4">
        <div>
            <a href="{{ route('operator.tickets.index') }}"
               class="mb-2 inline-flex items-center gap-1 text-sm hover:underline"
               style="color: var(--text-secondary);">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" /></svg>
                Back to queue
            </a>
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">Reports</h1>
        </div>
        <a href="{{ route('operator.tickets.export', request()->query()) }}"
           class="rounded-lg border px-4 py-2 text-sm font-medium transition-colors hover:legacy-bg-surface"
           style="border-color: var(--border-base); color: var(--text-secondary);">
            Export CSV
        </a>
    </div>

    {{-- Date range filter --}}
    <form method="GET" action="{{ route('operator.tickets.reports') }}" class="flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">From</label>
            <input type="date" name="date_from" value="{{ request('date_from', $from->toDateString()) }}"
                   class="rounded-lg border px-3 py-2 text-sm"
                   style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">To</label>
            <input type="date" name="date_to" value="{{ request('date_to', $to->toDateString()) }}"
                   class="rounded-lg border px-3 py-2 text-sm"
                   style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
        </div>
        <button type="submit" class="rounded-lg border px-4 py-2 text-sm font-medium hover:legacy-bg-surface"
                style="border-color: var(--border-base); color: var(--text-secondary);">Apply</button>
    </form>

    <div class="grid gap-6 sm:grid-cols-2">

        {{-- By Category --}}
        <section class="rounded-xl border p-6" style="border-color: var(--border-base); background-color: var(--surface-base);">
            <h2 class="text-sm font-semibold mb-4" style="color: var(--text-primary);">By Category</h2>
            @if ($byCategory->isEmpty())
            <p class="text-sm" style="color: var(--text-secondary);">No data.</p>
            @else
            <ul class="space-y-2">
                @foreach ($byCategory as $category => $count)
                <li class="flex items-center justify-between text-sm">
                    <span style="color: var(--text-primary);">{{ $category }}</span>
                    <span class="font-semibold tabular-nums" style="color: var(--accent);">{{ $count }}</span>
                </li>
                @endforeach
            </ul>
            @endif
        </section>

        {{-- By Priority --}}
        <section class="rounded-xl border p-6" style="border-color: var(--border-base); background-color: var(--surface-base);">
            <h2 class="text-sm font-semibold mb-4" style="color: var(--text-primary);">By Priority</h2>
            @if ($byPriority->isEmpty())
            <p class="text-sm" style="color: var(--text-secondary);">No data.</p>
            @else
            <ul class="space-y-2">
                @foreach ($byPriority as $priority => $count)
                <li class="flex items-center justify-between text-sm">
                    @include('tickets._priority_badge', ['priority' => $priority])
                    <span class="font-semibold tabular-nums" style="color: var(--accent);">{{ $count }}</span>
                </li>
                @endforeach
            </ul>
            @endif
        </section>

        {{-- Volume by day --}}
        <section class="rounded-xl border p-6 sm:col-span-2" style="border-color: var(--border-base); background-color: var(--surface-base);">
            <h2 class="text-sm font-semibold mb-4" style="color: var(--text-primary);">Volume by Day</h2>
            @if ($volumeByDay->isEmpty())
            <p class="text-sm" style="color: var(--text-secondary);">No data.</p>
            @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-left py-2 pr-6 font-semibold" style="color: var(--text-secondary);">Date</th>
                            <th class="text-right py-2 font-semibold" style="color: var(--text-secondary);">Tickets</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y" style="border-color: var(--border-subtle);">
                        @foreach ($volumeByDay as $date => $count)
                        <tr>
                            <td class="py-2 pr-6" style="color: var(--text-primary);">{{ $date }}</td>
                            <td class="py-2 text-right tabular-nums font-medium" style="color: var(--accent);">{{ $count }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </section>

        {{-- Avg resolution time --}}
        @if ($avgResolutionByAssignee->isNotEmpty())
        <section class="rounded-xl border p-6 sm:col-span-2" style="border-color: var(--border-base); background-color: var(--surface-base);">
            <h2 class="text-sm font-semibold mb-4" style="color: var(--text-primary);">Avg Resolution Time by Assignee</h2>
            <table class="min-w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left py-2 pr-6 font-semibold" style="color: var(--text-secondary);">Assignee</th>
                        <th class="text-right py-2 pr-6 font-semibold" style="color: var(--text-secondary);">Tickets</th>
                        <th class="text-right py-2 font-semibold" style="color: var(--text-secondary);">Avg Hours</th>
                    </tr>
                </thead>
                <tbody class="divide-y" style="border-color: var(--border-subtle);">
                    @foreach ($avgResolutionByAssignee as $row)
                    <tr>
                        <td class="py-2 pr-6" style="color: var(--text-primary);">{{ $row->name }}</td>
                        <td class="py-2 pr-6 text-right tabular-nums" style="color: var(--text-secondary);">{{ $row->count }}</td>
                        <td class="py-2 text-right tabular-nums font-medium" style="color: var(--accent);">{{ number_format($row->avg_hours, 1) }}h</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        @endif

    </div>

</div>
@endsection
