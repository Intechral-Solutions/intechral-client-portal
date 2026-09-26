import { useId, useMemo } from 'react';

import compactSource from '../../../images/brand/intechral-logo-compact.svg?raw';
import fullSource from '../../../images/brand/intechral-logo.svg?raw';

/**
 * The canonical Intechral mark, inlined and instance-scoped (EPIC-013 §10.3, gate G2 / A1.5).
 *
 * The committed SVGs are authoritative source artwork and are never redrawn or edited (L16). Three
 * things make them unsafe to inline as-is, all fixed here by rewriting presentation attributes
 * only — path data is never touched, which Direction D §17.1 explicitly permits:
 *
 * 1. Both files use `id="title"`/`id="desc"` and a document-global `.s` class, so two marks on one
 *    page collide. Every id and the class are prefixed with an instance-unique token.
 * 2. The gradient hard-codes the brand cyan, which is right on dark. The token layer flattens it to
 *    solid ink in light by overriding the stops (`.brand-mark stop` in app.css) — CSS beats SVG
 *    presentation attributes.
 * 3. `intechral-logo.svg` carries `vector-effect: non-scaling-stroke`, which pins the stroke at 8px
 *    at any size and fills the mark in below ~256px — measured at 74% ink coverage at 28px (A1.5).
 *    It is removed here; the compact file never had it.
 */
export function scopeBrandSvg(source: string, token: string, label: string): string {
    let svg = source
        .replace(/<title[^>]*>[\s\S]*?<\/title>/g, '')
        .replace(/<desc[^>]*>[\s\S]*?<\/desc>/g, '')
        .replace(/\s*aria-labelledby="[^"]*"/g, '')
        // A1.5: the defect that makes the full mark unusable at rail and auth sizes.
        .replace(/\s*vector-effect\s*:\s*non-scaling-stroke\s*;?/g, '');

    for (const id of new Set(Array.from(svg.matchAll(/id="([^"]+)"/g), (match) => match[1]))) {
        if (!id) {
            continue;
        }

        svg = svg
            .split(`id="${id}"`)
            .join(`id="${token}-${id}"`)
            .split(`url(#${id})`)
            .join(`url(#${token}-${id})`);
    }

    // The embedded stylesheet's single class is document-global once inlined into HTML.
    svg = svg
        .replace(/\.s\s*\{/g, `.${token}-s{`)
        .split('class="s"')
        .join(`class="${token}-s"`);

    return svg.replace(
        /<svg\b/,
        `<svg class="brand-mark" aria-label="${label.replace(/"/g, '&quot;')}"`,
    );
}

type BrandMarkProps = {
    /** `compact` is the 28px rail mark; `full` needs horizontal room (auth pages). */
    variant?: 'compact' | 'full';
    label: string;
    className?: string;
};

export function BrandMark({ variant = 'compact', label, className }: BrandMarkProps) {
    // React's id contains colons, which are legal in an id attribute but break both CSS selectors
    // and `url(#…)` references, so it is reduced to an identifier-safe token.
    const token = `bm${useId().replace(/[^a-zA-Z0-9]/g, '')}`;
    const source = variant === 'compact' ? compactSource : fullSource;
    const svg = useMemo(() => scopeBrandSvg(source, token, label), [source, token, label]);

    return <span className={className} dangerouslySetInnerHTML={{ __html: svg }} />;
}
