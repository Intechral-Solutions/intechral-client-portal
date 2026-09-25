@extends('layouts.app', ['title' => $ticket->ticket_number . ' — Operator'])

@section('content')
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8 space-y-6">

    {{-- Back --}}
    <a href="{{ route('operator.tickets.index') }}"
       class="inline-flex items-center gap-1 text-sm transition-colors hover:underline"
       style="color: var(--text-secondary);">
        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
        </svg>
        Back to queue
    </a>

    {{-- Flash / errors --}}
    @if (session('status'))
    <div class="rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);">
        {{ session('status') }}
    </div>
    @endif
    @if ($errors->any())
    <div class="rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-danger); border-color: var(--border-danger); color: var(--text-danger);">
        {{ $errors->first() }}
    </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- Thread --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- Original ticket --}}
            <div class="rounded-xl border p-6" style="border-color: var(--border-base); background-color: var(--surface-base);">
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div>
                        <p class="text-xs font-medium mb-1" style="color: var(--text-secondary);">
                            {{ $ticket->ticket_number }} &middot; {{ $ticket->user->name }} &middot; {{ $ticket->created_at->format('d M Y H:i') }}
                        </p>
                        <h1 class="text-xl font-semibold" style="color: var(--text-primary);">{{ $ticket->title }}</h1>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        @include('tickets._priority_badge', ['priority' => $ticket->priority])
                        @include('tickets._status_badge', ['status' => $ticket->status])
                        @if ($ticket->isOverdue())
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                              style="background-color: #fef2f2; color: #dc2626;">Overdue</span>
                        @endif
                    </div>
                </div>
                <div style="color: var(--text-primary);">{!! nl2br(e($ticket->description)) !!}</div>
                @if ($ticket->attachments->isNotEmpty())
                <div class="mt-4 pt-4 border-t flex flex-wrap gap-2" style="border-color: var(--border-subtle);">
                    @foreach ($ticket->attachments as $att)
                    <a href="{{ route('tickets.attachment.download', $att) }}"
                       class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs hover:legacy-bg-surface"
                       style="border-color: var(--border-base); color: var(--text-secondary);">
                        {{ $att->filename }} ({{ $att->formattedSize() }})
                    </a>
                    @endforeach
                </div>
                @endif
            </div>

            {{-- Replies --}}
            @foreach ($ticket->replies as $reply)
            <div class="rounded-xl border p-5 {{ $reply->is_internal ? 'border-dashed' : '' }}"
                 style="border-color: {{ $reply->is_internal ? '#d97706' : 'var(--border-base)' }}; background-color: {{ $reply->is_internal ? '#fffbeb' : 'var(--surface-base)' }};">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm font-medium" style="color: var(--text-primary);">{{ $reply->user->name }}</span>
                    <div class="flex items-center gap-2">
                        @if ($reply->is_internal)
                        <span class="text-xs font-medium px-2 py-0.5 rounded-full" style="background-color: #fef3c7; color: #92400e;">Internal Note</span>
                        @endif
                        <time class="text-xs" style="color: var(--text-secondary);">{{ $reply->created_at->diffForHumans() }}</time>
                    </div>
                </div>
                <div style="color: var(--text-primary);">{!! nl2br(e($reply->body)) !!}</div>
                @if ($reply->attachments->isNotEmpty())
                <div class="mt-3 pt-3 border-t flex flex-wrap gap-2" style="border-color: var(--border-subtle);">
                    @foreach ($reply->attachments as $att)
                    <a href="{{ route('tickets.attachment.download', $att) }}"
                       class="inline-flex items-center gap-1.5 rounded border px-2.5 py-1 text-xs hover:legacy-bg-surface"
                       style="border-color: var(--border-base); color: var(--text-secondary);">
                        {{ $att->filename }} ({{ $att->formattedSize() }})
                    </a>
                    @endforeach
                </div>
                @endif
            </div>
            @endforeach

            {{-- Reply box --}}
            <div class="rounded-xl border p-6" style="border-color: var(--border-base); background-color: var(--surface-base);">
                <h2 class="text-sm font-semibold mb-4" style="color: var(--text-primary);">Add Reply / Note</h2>
                <form method="POST" action="{{ route('operator.tickets.replies.store', $ticket) }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <textarea name="body" rows="5" required placeholder="Write your reply&hellip;"
                              class="block w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2 resize-y"
                              style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);"></textarea>
                    <label class="flex cursor-pointer items-center gap-2 text-sm" style="color: var(--text-secondary);">
                        <input type="checkbox" name="is_internal" value="1" class="h-4 w-4 rounded accent-accent">
                        Internal note (not visible to submitter)
                    </label>
                    <div>
                        <input type="file" name="attachments[]" multiple class="text-sm" style="color: var(--text-secondary);">
                        <p class="mt-1 text-xs" style="color: var(--text-secondary);">Up to 10 files, 20 MB each.</p>
                    </div>
                    <button type="submit"
                            class="rounded-lg px-5 py-2 text-sm font-medium transition-colors"
                            style="background-color: var(--accent); color: #fff;">
                        Post Reply
                    </button>
                </form>
            </div>

        </div>

        {{-- Sidebar --}}
        <aside class="space-y-6">

            {{-- Status --}}
            <div class="rounded-xl border p-5" style="border-color: var(--border-base); background-color: var(--surface-base);">
                <h2 class="text-xs font-semibold uppercase tracking-wide mb-3" style="color: var(--text-secondary);">Status</h2>
                <form method="POST" action="{{ route('operator.tickets.status', $ticket) }}">
                    @csrf @method('PUT')
                    <select name="status" class="block w-full rounded-lg border px-3 py-2 text-sm mb-3"
                            style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                        @foreach (['open', 'in_progress', 'pending_user', 'resolved', 'closed'] as $s)
                        <option value="{{ $s }}"
                                {{ $ticket->status === $s ? 'selected' : '' }}
                                {{ $ticket->canTransitionTo($s) || $ticket->status === $s ? '' : 'disabled' }}>
                            {{ str_replace('_', ' ', ucfirst($s)) }}
                        </option>
                        @endforeach
                    </select>
                    <button type="submit" class="w-full rounded-lg py-2 text-sm font-medium transition-colors"
                            style="background-color: var(--accent); color: #fff;">
                        Update Status
                    </button>
                </form>
            </div>

            {{-- Assign --}}
            <div class="rounded-xl border p-5" style="border-color: var(--border-base); background-color: var(--surface-base);">
                <h2 class="text-xs font-semibold uppercase tracking-wide mb-3" style="color: var(--text-secondary);">Assignee</h2>
                <form method="POST" action="{{ route('operator.tickets.assign', $ticket) }}">
                    @csrf @method('PUT')
                    <select name="assignee_id" class="block w-full rounded-lg border px-3 py-2 text-sm mb-3"
                            style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);">
                        <option value="">Unassigned</option>
                        @foreach ($operators as $op)
                        <option value="{{ $op->id }}" {{ $ticket->assignee_id == $op->id ? 'selected' : '' }}>
                            {{ $op->name }}
                        </option>
                        @endforeach
                    </select>
                    <button type="submit" class="w-full rounded-lg py-2 text-sm font-medium transition-colors"
                            style="background-color: var(--accent); color: #fff;">
                        Update Assignee
                    </button>
                </form>
            </div>

            {{-- Meta --}}
            <div class="rounded-xl border p-5" style="border-color: var(--border-base); background-color: var(--surface-base);">
                <h2 class="text-xs font-semibold uppercase tracking-wide mb-3" style="color: var(--text-secondary);">Details</h2>
                <dl class="space-y-2 text-sm">
                    <div><dt class="text-xs" style="color: var(--text-secondary);">Category</dt><dd style="color: var(--text-primary);">{{ $ticket->category }}</dd></div>
                    <div><dt class="text-xs" style="color: var(--text-secondary);">Submitted</dt><dd style="color: var(--text-primary);">{{ $ticket->created_at->format('d M Y H:i') }}</dd></div>
                    @if ($ticket->sla_due_at)
                    <div>
                        <dt class="text-xs" style="color: var(--text-secondary);">SLA Due</dt>
                        <dd style="color: {{ $ticket->isOverdue() ? 'var(--text-danger)' : 'var(--text-primary)' }};">{{ $ticket->sla_due_at->format('d M Y H:i') }}</dd>
                    </div>
                    @endif
                    @if ($ticket->resolved_at)
                    <div><dt class="text-xs" style="color: var(--text-secondary);">Resolved</dt><dd style="color: var(--text-primary);">{{ $ticket->resolved_at->format('d M Y H:i') }}</dd></div>
                    @endif
                </dl>
            </div>

            {{-- Status history --}}
            @if ($ticket->statusHistories->isNotEmpty())
            <div class="rounded-xl border p-5" style="border-color: var(--border-base); background-color: var(--surface-base);">
                <h2 class="text-xs font-semibold uppercase tracking-wide mb-3" style="color: var(--text-secondary);">History</h2>
                <ol class="space-y-2">
                    @foreach ($ticket->statusHistories as $h)
                    <li class="text-xs" style="color: var(--text-secondary);">
                        <span class="font-medium" style="color: var(--text-primary);">{{ str_replace('_', ' ', $h->old_status) }}</span>
                        &rarr; <span class="font-medium" style="color: var(--text-primary);">{{ str_replace('_', ' ', $h->new_status) }}</span>
                        {{ $h->user ? 'by ' . $h->user->name : '(auto)' }}
                        &middot; {{ $h->created_at->diffForHumans() }}
                    </li>
                    @endforeach
                </ol>
            </div>
            @endif

        </aside>

    </div>

</div>
@endsection
