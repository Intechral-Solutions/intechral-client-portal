{{--
    EPIC-013 §10.3 / §14.4 — the canonical mark, inlined and instance-scoped by App\Support\BrandMark,
    a port of scopeBrandSvg() in components/shell/brand-mark.tsx. Never redrawn (L16).

    @param string $variant 'compact' | 'full'
    @param string $label
    @param string $class
--}}
<span class="{{ $class ?? '' }}">{!! \App\Support\BrandMark::inline($variant ?? 'compact', $label) !!}</span>
