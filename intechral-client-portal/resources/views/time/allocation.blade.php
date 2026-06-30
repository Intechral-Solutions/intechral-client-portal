@extends('layouts.app', ['title' => 'Allocation Chart'])

@section('content')
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">

    {{-- Page header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold" style="color: var(--text-primary);">My Time</h1>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">View how your time is allocated across 15-minute blocks.</p>
        </div>
    </div>

    {{-- Tab bar --}}
    <div class="mb-6 flex items-center gap-0.5 border-b" style="border-color: var(--border-base);">
        <a href="{{ route('time.index') }}"
           class="px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors"
           style="border-color: transparent; color: var(--text-secondary);">
            Entries
        </a>
        <a href="{{ route('time.allocation') }}"
           class="px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors"
           style="border-color: var(--accent); color: var(--accent);">
            Allocation Chart
        </a>
    </div>

    {{-- Date picker --}}
    <div class="mb-6">
        <form method="GET" action="{{ route('time.allocation') }}" class="flex items-center gap-3">
            <label class="text-sm font-medium" style="color: var(--text-secondary);">Date</label>
            <input type="date" name="date" value="{{ $date }}"
                   class="rounded-lg border px-3 py-1.5 text-sm outline-none focus:ring-1"
                   style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);"
                   onchange="this.form.submit()">
        </form>
    </div>

    {{-- Chart --}}
    <div class="rounded-xl border p-6 mb-6"
         style="background-color: var(--surface-card); border-color: var(--border-base);">

        @if ($blocks->isEmpty())
        <div class="flex items-center justify-center h-48">
            <p class="text-sm" style="color: var(--text-muted);">No time blocks recorded for this date.</p>
        </div>
        @else
        <div style="position: relative; height: 400px;">
            <canvas id="allocation-chart"></canvas>
        </div>

        {{-- Legend --}}
        <div id="block-legend" class="mt-4 flex flex-wrap gap-4">
            {{-- JS-rendered --}}
        </div>
        @endif
    </div>

    {{-- Blocks table --}}
    @if ($blocks->isNotEmpty())
    <div class="rounded-xl border overflow-hidden"
         style="background-color: var(--surface-card); border-color: var(--border-base);">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom: 1px solid var(--border-base);">
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Block</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Timer</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Allocation</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Minutes</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">Override</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($blocks as $entryId => $entryBlocks)
                @php
                    $entry = $entryBlocks->first()->timeEntry;
                    $label = $entry->ticket?->ticket_number
                        ?? ($entry->task?->title ? \Illuminate\Support\Str::limit($entry->task->title, 30) : null)
                        ?? $entry->project?->name
                        ?? ('Entry #' . $entryId);
                    $contextUrl = $entry->ticket ? route('tickets.show', $entry->ticket)
                        : ($entry->task && $entry->task->project ? route('projects.tasks.show', [$entry->task->project, $entry->task]) : null)
                        ?? ($entry->project ? route('projects.board', $entry->project) : null);
                @endphp
                @foreach ($entryBlocks->sortBy('block_number') as $block)
                @php
                    $hour   = intdiv($block->block_number, 4);
                    $minute = ($block->block_number % 4) * 15;
                    $blockTime = sprintf('%02d:%02d', $hour, $minute);
                @endphp
                <tr style="border-bottom: 1px solid var(--border-subtle);" class="hover:bg-surface-hover">
                    <td class="px-4 py-2.5 font-mono text-xs" style="color: var(--text-secondary);">{{ $blockTime }}</td>
                    <td class="px-4 py-2.5">
                        @if ($contextUrl)
                        <a href="{{ $contextUrl }}" class="text-xs hover:underline" style="color: var(--accent);">{{ $label }}</a>
                        @else
                        <span class="text-xs" style="color: var(--text-secondary);">{{ $label }}</span>
                        @endif
                        @if ($block->entry->description ?? $entry->description)
                        <span class="text-xs ml-1" style="color: var(--text-muted);">— {{ \Illuminate\Support\Str::limit($entry->description, 40) }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-right font-mono text-xs" style="color: var(--text-primary);">
                        {{ number_format($block->allocation_pct, 1) }}%
                    </td>
                    <td class="px-4 py-2.5 text-right font-mono text-xs" style="color: var(--text-secondary);">
                        {{ number_format($block->allocation_pct * 15 / 100, 1) }}m
                    </td>
                    <td class="px-4 py-2.5 text-center">
                        @if ($block->is_overridden)
                        <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                              style="background-color: var(--surface-warning); color: var(--text-warning);">Manual</span>
                        @elseif ($entry->isLockedForBilling())
                        <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                              style="background-color: var(--surface-muted); color: var(--text-muted);">Locked</span>
                        @endif
                    </td>
                </tr>
                @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

</div>

@php
// Build data for JS: array of { entryId, label, contextUrl, description, blocks: {[blockNumber]: {id, pct}} }
$chartData = [];
foreach ($blocks as $entryId => $entryBlocks) {
    $entry = $entryBlocks->first()->timeEntry;
    $label = $entry->ticket?->ticket_number
        ?? (\Illuminate\Support\Str::limit($entry->task?->title ?? '', 30) ?: null)
        ?? $entry->project?->name
        ?? ('Entry #' . $entryId);
    $contextUrl = $entry->ticket ? route('tickets.show', $entry->ticket)
        : ($entry->task && $entry->task->project ? route('projects.tasks.show', [$entry->task->project, $entry->task]) : null)
        ?? ($entry->project ? route('projects.board', $entry->project) : null);
    $blockMap = [];
    foreach ($entryBlocks as $block) {
        $blockMap[$block->block_number] = [
            'id'  => $block->id,
            'pct' => (float) $block->allocation_pct,
        ];
    }
    $chartData[] = [
        'entryId'    => $entryId,
        'label'      => $label,
        'contextUrl' => $contextUrl,
        'description'=> $entry->description,
        'locked'     => $entry->isLockedForBilling(),
        'blocks'     => $blockMap,
    ];
}
@endphp

<script>
window.AllocationData = @json($chartData);
window.AllocationDate = "{{ $date }}";
window.AllocationRoutes = {
    blockAllocation: "{{ url('/time/blocks') }}"
};
</script>
@endsection
