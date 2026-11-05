@extends('layouts.app', ['title' => $ticket->ticket_number . ' — Operator'])

@section('content')
@php
    // A legacy ticket may have no SLA deadline at all; say so instead of printing an empty value.
    $slaText = $ticket->sla_due_at?->format('d M Y H:i') ?? 'none set';
    // A refused submit refills the date only if it is still inside the form's own bounds, so the browser never blocks a plain reply on it.
    $dueOld = (string) old('due_at');
    $dueOld = ($dueOld >= $dueMin && $dueOld <= $dueMax) ? $dueOld : '';
@endphp
<div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8 space-y-6">

    {{-- Back --}}
    <x-ui.link variant="quiet" :href="route('operator.tickets.index')" class="inline-flex items-center gap-1 text-sm">
        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
        </svg>
        Back to queue
    </x-ui.link>

    {{-- Flash / errors --}}
    @if (session('status'))
    <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif
    @if ($errors->any())
    <x-ui.alert variant="danger">{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- Thread --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- Original ticket --}}
            <div class="rounded-lg border p-6 border-rule bg-surface">
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div>
                        <p class="text-xs font-medium mb-1 text-text-secondary">
                            {{ $ticket->ticket_number }} &middot; {{ $ticket->user->name }} &middot; {{ $ticket->created_at->format('d M Y H:i') }}
                        </p>
                        <h1 class="text-xl font-semibold text-text">{{ $ticket->title }}</h1>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        @include('tickets._priority_badge', ['priority' => $ticket->priority])
                        @include('tickets._status_badge', ['status' => $ticket->status])
                        @if ($ticket->isOverdue())
                        @include('tickets._overdue_status')
                        @endif
                    </div>
                </div>
                <div class="text-text">{!! nl2br(e($ticket->description)) !!}</div>
                @if ($ticket->attachments->isNotEmpty())
                <div class="mt-4 pt-4 border-t flex flex-wrap gap-2 border-rule">
                    @foreach ($ticket->attachments as $att)
                    <x-ui.button :href="route('tickets.attachment.download', [$ticket, $att])" variant="secondary" size="sm">
                        {{ $att->filename }} ({{ $att->formattedSize() }})
                    </x-ui.button>
                    @endforeach
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
                        <time class="text-xs text-text-secondary">{{ $reply->created_at->diffForHumans() }}</time>
                    </div>
                </div>
                <div class="text-text">{!! nl2br(e($reply->body)) !!}</div>
                @if ($reply->dueDateChange)
                {{-- Release 1.0: operator-only. The requester page never loads or serializes due-date changes. --}}
                <p data-due-date-change class="mt-3 pt-3 border-t text-xs text-text-secondary border-rule">
                    Due date changed from
                    <span class="font-medium text-text">{{ $reply->dueDateChange->previous_override_at ? $reply->dueDateChange->previous_override_at->format('d M Y H:i') : 'the SLA deadline ('.$slaText.')' }}</span>
                    to
                    <span class="font-medium text-text">{{ $reply->dueDateChange->new_override_at ? $reply->dueDateChange->new_override_at->format('d M Y H:i') : 'the SLA deadline ('.$slaText.')' }}</span>
                    ({{ $dueZone }})
                </p>
                @endif
                @if ($reply->attachments->isNotEmpty())
                <div class="mt-3 pt-3 border-t flex flex-wrap gap-2 border-rule">
                    @foreach ($reply->attachments as $att)
                    <x-ui.button :href="route('tickets.attachment.download', [$ticket, $att])" variant="secondary" size="sm">
                        {{ $att->filename }} ({{ $att->formattedSize() }})
                    </x-ui.button>
                    @endforeach
                </div>
                @endif
            </div>
            @endforeach

            {{-- Reply box --}}
            <div class="rounded-lg border p-6 border-rule bg-surface">
                <h2 class="text-sm font-semibold mb-4 text-text">Add Reply / Note</h2>
                <form method="POST" action="{{ route('operator.tickets.replies.store', $ticket) }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <x-ui.textarea name="body" rows="5" required placeholder="Write your reply&hellip;" class="w-full">{{ old('body') }}</x-ui.textarea>
                    <x-ui.field-error for="body" />
                    <x-ui.checkbox name="is_internal" value="1" :checked="(bool) old('is_internal')">Internal note (not visible to submitter)</x-ui.checkbox>
                    @can('adjustDueDate', $ticket)
                    @if ($ticket->isOpen())
                    {{-- Release 1.0: an optional part of this reply or note, never a separate edit. The message above is the recorded reason. --}}
                    <details data-due-date-adjust class="rounded-lg border p-4 border-rule" @if (old('due_action', 'keep') !== 'keep' || $errors->has('due_at') || $errors->has('due_action')) open @endif>
                        <summary class="cursor-pointer text-sm font-medium text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">Change due date (optional)</summary>
                        <div class="mt-4 space-y-4">
                            <p class="text-sm text-text-secondary">
                                Current due date: {{ $ticket->effectiveDueAt() ? $ticket->effectiveDueAt()->format('d M Y H:i').' '.$dueZone : 'none' }}
                                ({{ $ticket->hasDueOverride() ? 'set by an operator' : 'SLA deadline' }}).
                                Your message above is recorded as the reason.
                            </p>
                            <div class="space-y-1">
                                <x-ui.label for="due_action">Due date</x-ui.label>
                                <x-ui.select name="due_action" id="due_action" class="w-full" aria-describedby="due-date-hint">
                                    <option value="keep" @selected(old('due_action', 'keep') === 'keep')>Keep the current due date</option>
                                    <option value="set" @selected(old('due_action') === 'set')>Set a new due date</option>
                                    @if ($ticket->hasDueOverride())
                                    <option value="restore" @selected(old('due_action') === 'restore')>Restore the SLA deadline ({{ $slaText }})</option>
                                    @endif
                                </x-ui.select>
                                <x-ui.field-error for="due_action" />
                            </div>
                            <div class="space-y-1">
                                <x-ui.label for="due_at">New due date and time ({{ $dueZone }})</x-ui.label>
                                <x-ui.input type="datetime-local" name="due_at" :value="$dueOld" min="{{ $dueMin }}" max="{{ $dueMax }}" class="w-full" aria-describedby="due-date-hint" />
                                <x-ui.field-error for="due_at" />
                            </div>
                            <p id="due-date-hint" class="text-xs text-text-secondary">
                                The date applies only with &ldquo;Set a new due date&rdquo;. Times are in {{ $dueZone }}, from now up to {{ $dueDays }} days ahead.
                                Restoring the SLA deadline needs a message too. A public reply is visible to the submitter; the due-date change itself is not.
                            </p>
                        </div>
                    </details>
                    @endif
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

        </div>

        {{-- Sidebar --}}
        <aside class="space-y-6">

            {{-- Status --}}
            <div class="rounded-lg border p-5 border-rule bg-surface">
                <h2 id="ticket-status-heading" class="text-xs font-semibold uppercase tracking-wide mb-3 text-text-secondary">Status</h2>
                <form method="POST" action="{{ route('operator.tickets.status', $ticket) }}" class="space-y-3">
                    @csrf @method('PUT')
                    <x-ui.select name="status" aria-labelledby="ticket-status-heading" class="w-full">
                        @foreach (['open', 'in_progress', 'pending_user', 'resolved', 'closed'] as $s)
                        <option value="{{ $s }}"
                                {{ $ticket->status === $s ? 'selected' : '' }}
                                {{ $ticket->canTransitionTo($s) || $ticket->status === $s ? '' : 'disabled' }}>
                            {{ str_replace('_', ' ', ucfirst($s)) }}
                        </option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.field-error for="status" />
                    <x-ui.button type="submit" variant="secondary" class="w-full">Update Status</x-ui.button>
                </form>
            </div>

            {{-- Assign --}}
            <div class="rounded-lg border p-5 border-rule bg-surface">
                <h2 id="ticket-assignee-heading" class="text-xs font-semibold uppercase tracking-wide mb-3 text-text-secondary">Assignee</h2>
                <form method="POST" action="{{ route('operator.tickets.assign', $ticket) }}" class="space-y-3">
                    @csrf @method('PUT')
                    <x-ui.select name="assignee_id" aria-labelledby="ticket-assignee-heading" class="w-full">
                        <option value="">Unassigned</option>
                        @foreach ($operators as $op)
                        <option value="{{ $op->id }}" {{ $ticket->assignee_id == $op->id ? 'selected' : '' }}>
                            {{ $op->name }}
                        </option>
                        @endforeach
                        {{-- Release 0.9 (H3): a suspended assignee is not a choice, but the current value still shows. --}}
                        @if ($ticket->assignee?->isSuspended())
                        <option value="{{ $ticket->assignee_id }}" selected>{{ $ticket->assignee->name }} (suspended)</option>
                        @endif
                    </x-ui.select>
                    <x-ui.field-error for="assignee_id" />
                    <x-ui.button type="submit" variant="secondary" class="w-full">Update Assignee</x-ui.button>
                </form>
            </div>

            {{-- Meta --}}
            <div class="rounded-lg border p-5 border-rule bg-surface">
                <h2 class="text-xs font-semibold uppercase tracking-wide mb-3 text-text-secondary">Details</h2>
                <dl class="space-y-2 text-sm">
                    <div><dt class="text-xs text-text-secondary">Category</dt><dd class="text-text">{{ $ticket->category }}</dd></div>
                    <div><dt class="text-xs text-text-secondary">Submitted</dt><dd class="text-text">{{ $ticket->created_at->format('d M Y H:i') }}</dd></div>
                    @if ($ticket->effectiveDueAt())
                    <div>
                        <dt class="text-xs text-text-secondary">Due ({{ $dueZone }})</dt>
                        <dd class="{{ $ticket->isOverdue() ? 'font-medium text-danger' : 'text-text' }}">
                            {{ $ticket->effectiveDueAt()->format('d M Y H:i') }}
                            @unless ($ticket->hasDueOverride())
                            <x-ui.tag class="ml-1 align-middle">SLA</x-ui.tag>
                            @endunless
                        </dd>
                        @if ($ticket->hasDueOverride())
                        <dd class="text-xs text-text-secondary">Original SLA: {{ $slaText }}</dd>
                        @endif
                    </div>
                    @endif
                    @if ($ticket->resolved_at)
                    <div><dt class="text-xs text-text-secondary">Resolved</dt><dd class="text-text">{{ $ticket->resolved_at->format('d M Y H:i') }}</dd></div>
                    @endif
                </dl>
            </div>

            {{-- Status history --}}
            @if ($ticket->statusHistories->isNotEmpty())
            <div class="rounded-lg border p-5 border-rule bg-surface">
                <h2 class="text-xs font-semibold uppercase tracking-wide mb-3 text-text-secondary">History</h2>
                <ol class="space-y-2">
                    @foreach ($ticket->statusHistories as $h)
                    <li class="text-xs text-text-secondary">
                        <span class="font-medium text-text">{{ str_replace('_', ' ', $h->old_status) }}</span>
                        &rarr; <span class="font-medium text-text">{{ str_replace('_', ' ', $h->new_status) }}</span>
                        {{ $h->user ? 'by ' . $h->user->name : '(auto)' }}
                        &middot; {{ $h->created_at->diffForHumans() }}
                    </li>
                    @endforeach
                </ol>
            </div>
            @endif

            {{--
                N2 continuity (EPIC-017 WP2, owner-approved): the embedded tracker lives here, on the
                operator-only route, because `/tickets/{id}` became the requester-safe page and carries no
                time data. The same component, script and endpoints as before; it adds no authorization.
                Starting ticket time is still refused server-side without `tickets.assign` and ticket
                visibility (AccessibleTimeContext). TEMPORARY: WP3 replaces this with the React operator
                ticket page's TimerControl and time summary, and then deletes this embed,
                components/time-tracker (and /running), the timerStarted/timerStopped listeners in
                shell/blade-timer.ts, and the tests that pin them. The COMPLETE removal list, and the
                requester-side privacy tests that must stay, are in EPIC-017 section 37 (N2 continuity).
            --}}
            @can('time.log')
            <x-time-tracker
                context-type="ticket"
                :context-id="$ticket->id"
                :context-reference="$ticket->ticket_number"
                :context-label="$ticket->ticket_number"
                :context-url="route('operator.tickets.show', $ticket)"
            />
            @endcan

        </aside>

    </div>

</div>
@endsection
