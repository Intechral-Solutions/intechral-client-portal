<?php

/*
 * EPIC-016 WP2: renders every semantic state through the PRODUCTION partials and components and prints the
 * HTML as JSON, for tests/Browser/blade-theme-controls.spec.ts to inject into a real, signed-in Blade page.
 *
 * Why it exists: several states cannot be created through the application's supported routes without leaving
 * records behind (tickets have no delete route and cannot be made overdue; only a draft invoice can be
 * deleted; there is no way to cancel one). The browser spec must still measure them in real Chromium, in
 * both themes, against the real compiled CSS. So the SAME partials the pages include are rendered here and
 * injected into the real page, rather than re-typed as HTML in the spec.
 *
 * It is read-only: it boots the application, renders views, and touches no database (the partials and
 * components read only the values they are passed) and writes nothing but Blade's compiled-view cache.
 *
 * Usage: php tests/Support/semantic_state_gallery.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Blade;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$template = <<<'BLADE'
<div data-wp2-gallery class="p-4 space-y-4">
    @foreach (['open', 'in_progress', 'pending_user', 'resolved', 'closed'] as $status)
    <div data-case="ticket-status:{{ $status }}">@include('tickets._status_badge', ['status' => $status])</div>
    @endforeach

    @foreach (['low', 'medium', 'high', 'critical'] as $priority)
    <div data-case="ticket-priority:{{ $priority }}">@include('tickets._priority_badge', ['priority' => $priority])</div>
    @endforeach

    @foreach (['draft', 'sent', 'paid', 'overdue', 'cancelled'] as $status)
    <div data-case="invoice-status:{{ $status }}">@include('billing._invoice_status', ['status' => $status])</div>
    @endforeach

    <div data-case="cms:published">@include('operator.cms._state', ['published' => true])</div>
    <div data-case="cms:draft">@include('operator.cms._state', ['published' => false])</div>
    <div data-case="mfa:enabled"><x-ui.status tone="success">Enabled</x-ui.status></div>
    <div data-case="mfa:disabled"><x-ui.status glyph="dashed">Disabled</x-ui.status></div>

    {{-- The SLA cell of the operator queue and the ticket detail: due date in danger + the Overdue status. --}}
    <div data-case="overdue-sla" class="text-sm font-medium text-danger">06 Oct 14:00 @include('tickets._overdue_status', ['class' => 'ml-1'])</div>

    {{-- The internal-note card exactly as both ticket detail views draw it (class string pinned by Pest). --}}
    <div data-case="internal-note" class="rounded-xl border p-5 border-dashed border-warning-glyph bg-warning-soft">
        <div class="flex items-center justify-between mb-3">
            <span class="text-sm font-medium" style="color: var(--text-primary);">Operator</span>
            <div class="flex items-center gap-2">
                @include('tickets._internal_note_label')
                <time class="text-xs" style="color: var(--text-secondary);">2 minutes ago</time>
            </div>
        </div>
        <div style="color: var(--text-primary);">Customer is on the legacy plan.</div>
    </div>

    <div data-case="tags" class="flex gap-2">
        <x-ui.tag>Built-in</x-ui.tag>
        <x-ui.tag>Custom</x-ui.tag>
        <x-ui.tag>Admin</x-ui.tag>
        <x-ui.tag>Member</x-ui.tag>
    </div>

    @foreach (['neutral', 'info', 'success', 'warning', 'danger'] as $variant)
    <x-ui.alert :variant="$variant" data-case="alert:{{ $variant }}">The {{ $variant }} message.</x-ui.alert>
    @endforeach

    <div data-case="tracker-running" class="flex items-center gap-2">
        <x-time-tracker.running :entry-id="1" />
    </div>
</div>
BLADE;

echo json_encode(['html' => Blade::render($template)], JSON_THROW_ON_ERROR);
