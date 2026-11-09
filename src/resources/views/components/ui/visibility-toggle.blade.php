{{--
    Release 1.0 (HD-C1): the operator composer's visibility control. It replaces the "Internal note (not visible to
    submitter)" checkbox with a compact toggle button that carries the SAME value, `is_internal`.

    <x-ui.visibility-toggle :internal="(bool) old('is_internal')" />

    - A <button aria-pressed>: PRESSED means Hidden (an internal note, `is_internal=1`); not pressed means Visible (a public
      reply, `is_internal=0`). The visible words are "Visible" / "Hidden" with an eye / eye-off glyph, so state never rests on
      colour, and the accessible name is those same words plus a screen-reader-only tail ("Visible to submitter" / "Hidden from
      submitter"), so the visible label is always inside the name (WCAG 2.5.3) and agrees with the pressed state. Both states
      are rendered and CSS shows one (`aria-pressed:` variants, `display:none` leaves the other out of the accessibility tree),
      so the markup is correct before any script runs; `shell/blade-ticket-composer.ts` only flips `aria-pressed` and the
      hidden input.
    - The tooltip is a `role="tooltip"` bubble for sighted users, shown on hover AND keyboard focus, dismissable with Escape,
      and kept open while the pointer travels onto it (its `before:` bridge covers the gap; WCAG 1.4.13). The name above already
      says it all to a screen reader, so the button does not also `aria-describedby` the same words.
    - Presentation only. Who may write an internal note is decided where it always was (TicketPolicy::viewInternal, and the
      reply controller demotes a note from an actor without it). Render this only for an actor who passes that policy.
    - A SEGMENT of a joined group: put it beside the other composer controls in one flex row; it rounds only its outer ends.
--}}
@props([
    'internal' => false,
    'name' => 'is_internal',
])

@php
    $tip = 'visibility-toggle-tip';
    $segment = 'relative flex -ms-px first:ms-0 rounded-none first:rounded-s-control last:rounded-e-control focus-within:z-10';
    $button = 'peer group inline-flex h-9 pointer-coarse:h-11 w-full items-center gap-2 rounded-[inherit] border px-3 text-sm font-medium transition-[color,background-color,border-color] duration-motion-fast ease-motion focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus '
        .'border-control-edge bg-surface text-text hover:bg-surface-hover active:brightness-95 '
        .'aria-pressed:border-dashed aria-pressed:border-warning-glyph aria-pressed:bg-warning-soft aria-pressed:text-warning aria-pressed:hover:bg-warning-soft';
@endphp

<div data-visibility-toggle data-tooltip-host class="group/tip {{ $segment }}">
    <input type="hidden" name="{{ $name }}" value="{{ $internal ? '1' : '0' }}" data-visibility-input>
    <button type="button" data-visibility-button aria-pressed="{{ $internal ? 'true' : 'false' }}" class="{{ $button }}">
        <svg viewBox="0 0 24 24" class="size-4 shrink-0 group-aria-pressed:hidden" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" data-glyph="eye"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>
        <svg viewBox="0 0 24 24" class="hidden size-4 shrink-0 group-aria-pressed:block" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" data-glyph="eye-off"><path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/></svg>
        <span class="group-aria-pressed:hidden">Visible<span class="sr-only"> to submitter</span></span>
        <span class="hidden group-aria-pressed:inline">Hidden<span class="sr-only"> from submitter</span></span>
    </button>
    <span id="{{ $tip }}" role="tooltip" class="invisible absolute bottom-full left-0 z-20 mb-2 w-max max-w-64 rounded-control bg-ink px-2 py-1 text-xs text-on-ink opacity-0 transition-opacity duration-motion-fast ease-motion peer-hover:visible peer-hover:opacity-100 peer-focus-visible:visible peer-focus-visible:opacity-100 hover:visible hover:opacity-100 before:absolute before:top-full before:left-0 before:h-2 before:w-full group-data-dismissed/tip:invisible!">
        <span class="group-has-[[aria-pressed=true]]/tip:hidden">Visible to submitter</span>
        <span class="hidden group-has-[[aria-pressed=true]]/tip:inline">Not visible to submitter</span>
    </span>
</div>
