{{--
    EPIC-016 WP2: the internal-note marker (§9.5, Direction D §10.5): a lock glyph (lucide `lock` path data,
    an icon rather than a Status glyph, so no substitution) and the existing "Internal Note" text in
    `warning`. The card around it takes `border-dashed border-warning-glyph bg-warning-soft` from the caller.
    Presentation only: who may write or see a note is unchanged.
--}}
<span data-internal-note class="inline-flex items-center gap-1.5 text-xs font-medium text-warning">
    <svg viewBox="0 0 24 24" class="size-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    <span>Internal Note</span>
</span>
