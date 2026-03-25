@extends('layouts.app', ['title' => $ticket->ticket_number])

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8 space-y-6">

    {{-- Back --}}
    <a href="{{ route('tickets.index') }}"
       class="inline-flex items-center gap-1 text-sm transition-colors hover:underline"
       style="color: var(--text-secondary);">
        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
        </svg>
        Back to tickets
    </a>

    {{-- Flash --}}
    @if (session('status'))
    <div class="rounded-lg border px-4 py-3 text-sm"
         style="background-color: var(--surface-success); border-color: var(--border-success); color: var(--text-success);">
        {{ session('status') }}
    </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- Main thread --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- Ticket header --}}
            <div class="rounded-xl border p-6" style="border-color: var(--border-base); background-color: var(--surface-base);">
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div>
                        <p class="text-xs font-medium mb-1" style="color: var(--text-secondary);">{{ $ticket->ticket_number }}</p>
                        <h1 class="text-xl font-semibold" style="color: var(--text-primary);">{{ $ticket->title }}</h1>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        @include('tickets._priority_badge', ['priority' => $ticket->priority])
                        @include('tickets._status_badge', ['status' => $ticket->status])
                    </div>
                </div>
                <div class="prose prose-sm max-w-none" style="color: var(--text-primary);">
                    {!! nl2br(e($ticket->description)) !!}
                </div>
                @if ($ticket->attachments->isNotEmpty())
                <div class="mt-4 pt-4 border-t" style="border-color: var(--border-subtle);">
                    <p class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">Attachments</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($ticket->attachments as $att)
                        <a href="{{ route('tickets.attachment.download', $att) }}"
                           class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs transition-colors hover:bg-surface"
                           style="border-color: var(--border-base); color: var(--text-secondary);">
                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M10.75 2.75a.75.75 0 0 0-1.5 0v8.614L6.295 8.235a.75.75 0 1 0-1.09 1.03l4.25 4.5a.75.75 0 0 0 1.09 0l4.25-4.5a.75.75 0 0 0-1.09-1.03l-2.955 3.129V2.75Z" />
                            </svg>
                            {{ $att->filename }}
                            <span style="color: var(--text-secondary);">({{ $att->formattedSize() }})</span>
                        </a>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>

            {{-- Replies --}}
            @foreach ($ticket->replies as $reply)
            <div class="rounded-xl border p-5 @if($reply->is_internal) border-dashed @endif"
                 style="border-color: {{ $reply->is_internal ? 'var(--border-warning, #d97706)' : 'var(--border-base)' }}; background-color: {{ $reply->is_internal ? 'var(--surface-warning, #fffbeb)' : 'var(--surface-base)' }};">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm font-medium" style="color: var(--text-primary);">{{ $reply->user->name }}</span>
                    <div class="flex items-center gap-2">
                        @if ($reply->is_internal)
                        <span class="text-xs font-medium px-2 py-0.5 rounded-full"
                              style="background-color: #fef3c7; color: #92400e;">Internal Note</span>
                        @endif
                        <time class="text-xs" style="color: var(--text-secondary);" datetime="{{ $reply->created_at->toIso8601String() }}">
                            {{ $reply->created_at->diffForHumans() }}
                        </time>
                    </div>
                </div>
                <div style="color: var(--text-primary);">
                    {!! nl2br(e($reply->body)) !!}
                </div>
                @if ($reply->attachments->isNotEmpty())
                <div class="mt-3 pt-3 border-t flex flex-wrap gap-2" style="border-color: var(--border-subtle);">
                    @foreach ($reply->attachments as $att)
                    <a href="{{ route('tickets.attachment.download', $att) }}"
                       class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs transition-colors hover:bg-surface"
                       style="border-color: var(--border-base); color: var(--text-secondary);">
                        {{ $att->filename }} ({{ $att->formattedSize() }})
                    </a>
                    @endforeach
                </div>
                @endif
            </div>
            @endforeach

            {{-- Reply form --}}
            @if ($ticket->isOpen())
            <div class="rounded-xl border p-6" style="border-color: var(--border-base); background-color: var(--surface-base);">
                <h2 class="text-sm font-semibold mb-4" style="color: var(--text-primary);">Add Reply</h2>
                <form method="POST" action="{{ route('tickets.replies.store', $ticket) }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <textarea name="body" rows="4" required placeholder="Your reply&hellip;"
                              class="block w-full rounded-lg border px-3 py-2 text-sm outline-none transition focus:ring-2 resize-y"
                              style="background-color: var(--surface-input); border-color: var(--border-base); color: var(--text-primary);"></textarea>
                    @can('tickets.assign')
                    <label class="flex cursor-pointer items-center gap-2 text-sm" style="color: var(--text-secondary);">
                        <input type="checkbox" name="is_internal" value="1" class="h-4 w-4 rounded accent-accent">
                        Internal note (not visible to submitter)
                    </label>
                    @endcan
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
            @endif

        </div>

        {{-- Sidebar --}}
        <aside class="space-y-6">

            {{-- Ticket meta --}}
            <div class="rounded-xl border p-5" style="border-color: var(--border-base); background-color: var(--surface-base);">
                <h2 class="text-xs font-semibold uppercase tracking-wide mb-4" style="color: var(--text-secondary);">Details</h2>
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-xs" style="color: var(--text-secondary);">Category</dt>
                        <dd style="color: var(--text-primary);">{{ $ticket->category }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs" style="color: var(--text-secondary);">Submitted</dt>
                        <dd style="color: var(--text-primary);">{{ $ticket->created_at->format('d M Y H:i') }}</dd>
                    </div>
                    @if ($ticket->sla_due_at)
                    <div>
                        <dt class="text-xs" style="color: var(--text-secondary);">SLA Due</dt>
                        <dd style="color: {{ $ticket->isOverdue() ? 'var(--text-danger)' : 'var(--text-primary)' }};">
                            {{ $ticket->sla_due_at->format('d M Y H:i') }}
                            @if ($ticket->isOverdue())
                            <span class="text-xs font-medium ml-1" style="color: var(--text-danger);">Overdue</span>
                            @endif
                        </dd>
                    </div>
                    @endif
                    @if ($ticket->assignee)
                    <div>
                        <dt class="text-xs" style="color: var(--text-secondary);">Assigned to</dt>
                        <dd style="color: var(--text-primary);">{{ $ticket->assignee->name }}</dd>
                    </div>
                    @endif
                </dl>
            </div>

            {{-- Status history --}}
            @if ($ticket->statusHistories->isNotEmpty())
            <div class="rounded-xl border p-5" style="border-color: var(--border-base); background-color: var(--surface-base);">
                <h2 class="text-xs font-semibold uppercase tracking-wide mb-4" style="color: var(--text-secondary);">History</h2>
                <ol class="space-y-2">
                    @foreach ($ticket->statusHistories as $h)
                    <li class="text-xs" style="color: var(--text-secondary);">
                        <span style="color: var(--text-primary);">{{ $h->new_status }}</span>
                        {{ $h->user ? 'by ' . $h->user->name : 'automatically' }}
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
