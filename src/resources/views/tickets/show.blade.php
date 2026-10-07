@extends('layouts.app', ['title' => $ticket->ticket_number])

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8 space-y-6">

    {{-- Back --}}
    <x-ui.link variant="quiet" :href="route('tickets.index')" class="inline-flex items-center gap-1 text-sm">
        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
        </svg>
        Back to tickets
    </x-ui.link>

    {{-- Flash --}}
    @if (session('status'))
    <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- Main thread --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- Ticket header --}}
            <div class="rounded-lg border p-6 border-rule bg-surface">
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div>
                        <p class="text-xs font-medium mb-1 text-text-secondary">{{ $ticket->ticket_number }}</p>
                        <h1 class="text-xl font-semibold text-text">{{ $ticket->title }}</h1>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        @include('tickets._priority_badge', ['priority' => $ticket->priority])
                        @include('tickets._status_badge', ['status' => $ticket->status])
                    </div>
                </div>
                <div class="prose prose-sm max-w-none text-text">
                    {!! nl2br(e($ticket->description)) !!}
                </div>
                @if ($ticket->attachments->isNotEmpty())
                <div class="mt-4 pt-4 border-t border-rule">
                    <p class="text-xs font-semibold mb-2 text-text-secondary">Attachments</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($ticket->attachments as $att)
                        <x-ui.button :href="route('tickets.attachment.download', $att)" variant="secondary" size="sm">
                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M10.75 2.75a.75.75 0 0 0-1.5 0v8.614L6.295 8.235a.75.75 0 1 0-1.09 1.03l4.25 4.5a.75.75 0 0 0 1.09 0l4.25-4.5a.75.75 0 0 0-1.09-1.03l-2.955 3.129V2.75Z" />
                            </svg>
                            {{ $att->filename }}
                            <span class="font-normal text-text-secondary">({{ $att->formattedSize() }})</span>
                        </x-ui.button>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>

            {{-- Replies --}}
            @foreach ($ticket->replies as $reply)
            <div class="rounded-lg border p-5 {{ $reply->is_internal ? 'border-dashed border-warning-glyph bg-warning-soft' : 'border-rule bg-surface' }}">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm font-medium text-text">{{ $reply->user->name }}</span>
                    <div class="flex items-center gap-2">
                        @if ($reply->is_internal)
                        @include('tickets._internal_note_label')
                        @endif
                        <time class="text-xs text-text-secondary" datetime="{{ $reply->created_at->toIso8601String() }}">
                            {{ $reply->created_at->diffForHumans() }}
                        </time>
                    </div>
                </div>
                <div class="text-text">
                    {!! nl2br(e($reply->body)) !!}
                </div>
                @if ($reply->attachments->isNotEmpty())
                <div class="mt-3 pt-3 border-t flex flex-wrap gap-2 border-rule">
                    @foreach ($reply->attachments as $att)
                    <x-ui.button :href="route('tickets.attachment.download', $att)" variant="secondary" size="sm">
                        {{ $att->filename }} ({{ $att->formattedSize() }})
                    </x-ui.button>
                    @endforeach
                </div>
                @endif
            </div>
            @endforeach

            {{-- Reply form --}}
            @if ($ticket->isOpen())
            <div class="rounded-lg border p-6 border-rule bg-surface">
                <h2 class="text-sm font-semibold mb-4 text-text">Add Reply</h2>
                <form method="POST" action="{{ route('tickets.replies.store', $ticket) }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <x-ui.textarea name="body" rows="4" required placeholder="Your reply&hellip;" class="w-full"></x-ui.textarea>
                    <x-ui.field-error for="body" />
                    @can('tickets.assign')
                    <x-ui.checkbox name="is_internal" value="1">Internal note (not visible to submitter)</x-ui.checkbox>
                    @endcan
                    <div class="space-y-2">
                        <x-ui.input type="file" name="attachments[]" id="attachments" multiple
                                    aria-label="Attachments" aria-describedby="attachments-hint"
                                    :error-key="['attachments', 'attachments.*']" />
                        <p id="attachments-hint" class="text-xs text-text-secondary">Up to 10 files, 20 MB each.</p>
                        <x-ui.field-error for="attachments" :error-key="['attachments', 'attachments.*']" />
                    </div>
                    <x-ui.button type="submit">Post Reply</x-ui.button>
                </form>
            </div>
            @endif

        </div>

        {{-- Sidebar --}}
        <aside class="space-y-6">

            {{-- Ticket meta --}}
            <div class="rounded-lg border p-5 border-rule bg-surface">
                <h2 class="text-xs font-semibold uppercase tracking-wide mb-4 text-text-secondary">Details</h2>
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-xs text-text-secondary">Category</dt>
                        <dd class="text-text">{{ $ticket->category }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-text-secondary">Submitted</dt>
                        <dd class="text-text">{{ $ticket->created_at->format('d M Y H:i') }}</dd>
                    </div>
                    @if ($ticket->sla_due_at)
                    <div>
                        <dt class="text-xs text-text-secondary">SLA Due</dt>
                        <dd class="{{ $ticket->isOverdue() ? 'text-danger' : 'text-text' }}">
                            {{ $ticket->sla_due_at->format('d M Y H:i') }}
                            @if ($ticket->isOverdue())
                            @include('tickets._overdue_status', ['class' => 'ml-1'])
                            @endif
                        </dd>
                    </div>
                    @endif
                    @if ($ticket->assignee)
                    <div>
                        <dt class="text-xs text-text-secondary">Assigned to</dt>
                        <dd class="text-text">{{ $ticket->assignee->name }}</dd>
                    </div>
                    @endif
                </dl>
            </div>

            {{-- Status history --}}
            @if ($ticket->statusHistories->isNotEmpty())
            <div class="rounded-lg border p-5 border-rule bg-surface">
                <h2 class="text-xs font-semibold uppercase tracking-wide mb-4 text-text-secondary">History</h2>
                <ol class="space-y-2">
                    @foreach ($ticket->statusHistories as $h)
                    <li class="text-xs text-text-secondary">
                        <span class="text-text">{{ $h->new_status }}</span>
                        {{ $h->user ? 'by ' . $h->user->name : 'automatically' }}
                        &middot; {{ $h->created_at->diffForHumans() }}
                    </li>
                    @endforeach
                </ol>
            </div>
            @endif

            {{-- Time tracking --}}
            @can('time.log')
            <x-time-tracker
                context-type="ticket"
                :context-id="$ticket->id"
                :context-label="$ticket->ticket_number"
                :context-url="route('tickets.show', $ticket)"
            />
            @endcan

        </aside>

    </div>

</div>
@endsection
