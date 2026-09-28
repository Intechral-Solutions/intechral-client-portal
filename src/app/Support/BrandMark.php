<?php

namespace App\Support;

/**
 * The canonical Intechral mark, inlined and instance-scoped for Blade (EPIC-013 §10.3, §14.4).
 *
 * A line-for-line port of `scopeBrandSvg()` in `resources/js/components/shell/brand-mark.tsx`, which
 * is the reference implementation (A8.19). The committed SVGs are authoritative artwork and are never
 * edited (L16); only presentation attributes are rewritten, never path data:
 *
 * 1. `<title>`/`<desc>` and `aria-labelledby` are stripped, and every id plus its `url(#…)`
 *    references are prefixed with an instance token, so two marks on one page cannot collide.
 * 2. The embedded stylesheet's document-global `.s` class is scoped to the same token.
 * 3. `vector-effect: non-scaling-stroke` is removed (A1.5: it fills the full mark in at rail sizes).
 * 4. The root gains `class="brand-mark"` — which app.css uses to flatten the cyan fade to ink in the
 *    light theme — and an `aria-label`.
 *
 * Both renderers therefore emit the same markup for the same token, and a Blade page and a React page
 * render the identical mark.
 */
final class BrandMark
{
    private static int $instances = 0;

    public static function inline(string $variant, string $label): string
    {
        $file = $variant === 'full' ? 'intechral-logo.svg' : 'intechral-logo-compact.svg';

        return self::scope(
            (string) file_get_contents(resource_path('images/brand/'.$file)),
            'bmb'.(++self::$instances),
            $label,
        );
    }

    public static function scope(string $source, string $token, string $label): string
    {
        $svg = (string) preg_replace('/<title[^>]*>[\s\S]*?<\/title>/', '', $source);
        $svg = (string) preg_replace('/<desc[^>]*>[\s\S]*?<\/desc>/', '', $svg);
        $svg = (string) preg_replace('/\s*aria-labelledby="[^"]*"/', '', $svg);
        $svg = (string) preg_replace('/\s*vector-effect\s*:\s*non-scaling-stroke\s*;?/', '', $svg);

        preg_match_all('/id="([^"]+)"/', $svg, $matches);

        foreach (array_unique($matches[1]) as $id) {
            $svg = str_replace(
                ['id="'.$id.'"', 'url(#'.$id.')'],
                ['id="'.$token.'-'.$id.'"', 'url(#'.$token.'-'.$id.')'],
                $svg,
            );
        }

        $svg = (string) preg_replace('/\.s\s*\{/', '.'.$token.'-s{', $svg);
        $svg = str_replace('class="s"', 'class="'.$token.'-s"', $svg);

        return (string) preg_replace(
            '/<svg\b/',
            '<svg class="brand-mark" aria-label="'.str_replace('"', '&quot;', $label).'"',
            $svg,
            1,
        );
    }
}
