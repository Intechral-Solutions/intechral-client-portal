{{--
    EPIC-016 WP2: the Blade twin of resources/js/components/ui/tag.tsx (Direction D §18 `Tag`).

    <x-ui.tag>Built-in</x-ui.tag>

    The small mono, uppercase marker for a KIND or CATEGORY (a role's type, an organization member's role).
    Plain text with a structural edge: never a control, never a lifecycle status (use x-ui.status for
    state), and never accent-coloured. Callers add layout classes only.
--}}
<span {{ $attributes->merge(['class' => 'inline-block rounded-tag whitespace-nowrap border border-rule-control px-1.5 font-mono text-[10px] leading-4 tracking-wide text-text-muted uppercase']) }}>{{ $slot }}</span>
