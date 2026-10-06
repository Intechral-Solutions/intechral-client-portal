{{--
    EPIC-016 WP1: the Blade twin of resources/js/components/ui/button.tsx (Direction D §2.3.5, §14).

    <x-ui.button type="submit">Save</x-ui.button>
    <x-ui.button variant="secondary" tone="danger">Delete</x-ui.button>
    <x-ui.button href="..." variant="secondary" size="sm">Edit</x-ui.button>

    - `variant`: `primary` (default; `ink`), `secondary` (`surface` + `control-edge`), `ghost`, `destructive`
      (solid `danger`, the confirming action only; EPIC-016 has no Blade consumer).
    - `tone="danger"` on `secondary` / `ghost` only: the destructive TRIGGER (secondary + danger edge and
      text), which is not the destructive confirm. The confirmation stays the native confirm().
    - `size`: `sm`, `md` (default), `lg`, `icon` (an icon button must carry an `aria-label`).
    - `href` renders an <a> with the same classes. `disabled` renders the `disabled` attribute on a
      <button>, and `aria-disabled="true"` with no `href` on a link.
    - `type` defaults to `button`; pass `type="submit"` explicitly.

    Colour, border, radius, height, padding and focus belong to this component. Callers add layout classes
    only (width, margin, alignment); Blade cannot merge conflicting Tailwind utilities (EPIC-016 §7.2).
    Every other attribute (`name`, `value`, `form`, `data-*`, `aria-*`, `onclick`, `rel`, `target`) is
    forwarded unchanged.
--}}
@props([
    'variant' => 'primary',
    'tone' => null,
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'disabled' => false,
])

@php
    $focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';
    $base = 'inline-flex items-center justify-center gap-2 border font-medium transition-[color,background-color,border-color] duration-motion-fast ease-motion disabled:pointer-events-none '.$focusRing;

    $variants = [
        'primary' => 'border-transparent bg-ink text-on-ink hover:bg-ink/90 active:bg-ink/80 disabled:border-rule disabled:bg-surface-sunken disabled:text-text-muted',
        'secondary' => 'border-control-edge bg-surface text-text hover:bg-surface-hover active:bg-surface-sunken disabled:border-rule disabled:bg-surface-sunken disabled:text-text-muted',
        'ghost' => 'border-transparent text-text hover:bg-surface-hover active:bg-surface-sunken disabled:text-text-muted',
        'destructive' => 'border-transparent bg-destructive text-destructive-foreground hover:bg-destructive/90 active:bg-destructive/80 disabled:border-rule disabled:bg-surface-sunken disabled:text-text-muted',
    ];

    // The destructive TRIGGER: the same variant with the danger edge/text in place of the neutral ones.
    $dangerTones = [
        'secondary' => 'border-danger bg-surface text-danger hover:bg-surface-hover active:bg-surface-sunken disabled:border-rule disabled:bg-surface-sunken disabled:text-text-muted',
        'ghost' => 'border-transparent text-danger hover:bg-surface-hover active:bg-surface-sunken disabled:text-text-muted',
    ];

    $sizes = [
        'sm' => 'h-8 pointer-coarse:h-10 rounded-control px-2.5 text-xs',
        'md' => 'h-9 pointer-coarse:h-11 rounded-control px-3 text-sm',
        'lg' => 'h-10 pointer-coarse:h-12 rounded-control-lg px-4 text-sm',
        'icon' => 'size-9 pointer-coarse:size-11 rounded-control p-0 text-sm',
    ];

    if (! isset($variants[$variant])) {
        throw new InvalidArgumentException("Unknown x-ui.button variant \"{$variant}\".");
    }
    if (! isset($sizes[$size])) {
        throw new InvalidArgumentException("Unknown x-ui.button size \"{$size}\".");
    }
    if ($tone !== null && ($tone !== 'danger' || ! isset($dangerTones[$variant]))) {
        throw new InvalidArgumentException('tone="danger" is only valid on the secondary and ghost variants.');
    }
    if ($size === 'icon' && ! $attributes->has('aria-label')) {
        throw new InvalidArgumentException('An x-ui.button with size="icon" needs an aria-label.');
    }

    $variantClasses = $tone === 'danger' ? $dangerTones[$variant] : $variants[$variant];
    $disabledLink = 'border-rule bg-surface-sunken text-text-muted cursor-not-allowed pointer-events-none';
    $classes = $base.' '.$sizes[$size].' '.($href !== null && $disabled ? $disabledLink : $variantClasses);
@endphp

@if ($href !== null && ! $disabled)
<a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@elseif ($href !== null)
<a role="link" aria-disabled="true" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
<button type="{{ $type }}" @disabled($disabled) {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
