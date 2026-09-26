import { render } from '@testing-library/react';

import { BrandMark, scopeBrandSvg } from './brand-mark';

it('carries the brand-mark class so the token layer can theme its gradient stops', () => {
    const { container } = render(<BrandMark label="Intechral" />);
    const svg = container.querySelector('svg');

    // §10.3: light flattens the hard-coded cyan to solid ink by overriding `.brand-mark stop`, which
    // only works because CSS beats SVG presentation attributes.
    expect(svg).toHaveClass('brand-mark');
    expect(svg).toHaveAttribute('aria-label', 'Intechral');
    expect(svg).toHaveAttribute('role', 'img');
});

it('gives two marks on one page distinct ids and no duplicates', () => {
    const { container } = render(
        <>
            <BrandMark variant="compact" label="One" />
            <BrandMark variant="full" label="Two" />
        </>,
    );

    const ids = Array.from(container.querySelectorAll('[id]'), (node) => node.id);

    expect(ids.length).toBeGreaterThan(0);
    expect(new Set(ids).size).toBe(ids.length);

    // Each mark must resolve its OWN gradient, not whichever one won the document (A1.5).
    for (const svg of container.querySelectorAll('svg')) {
        const gradient = svg.querySelector('linearGradient')?.id;
        const style = svg.querySelector('style')?.textContent ?? '';

        expect(gradient).toBeTruthy();
        expect(style).toContain(`url(#${gradient})`);
    }
});

it('strips the embedded title and desc so the component owns the accessible name', () => {
    const { container } = render(<BrandMark label="Intechral" />);
    const svg = container.querySelector('svg');

    // Both canonical files use `id="title"`/`id="desc"`, which collide once inlined twice.
    expect(svg?.querySelector('title')).toBeNull();
    expect(svg?.querySelector('desc')).toBeNull();
    expect(svg).not.toHaveAttribute('aria-labelledby');
});

it('removes the non-scaling stroke that makes the full mark unusable at small sizes', () => {
    const { container } = render(<BrandMark variant="full" label="Intechral" />);

    // A1.5 measured 74% ink coverage at 28px with it in place: the mark became a filled triangle.
    expect(container.innerHTML).not.toContain('non-scaling-stroke');
});

it('scopes the document-global class the embedded stylesheet declares', () => {
    const scoped = scopeBrandSvg(
        '<svg><defs><linearGradient id="g"><stop/></linearGradient><style>.s{stroke:url(#g)}</style></defs><path class="s"/></svg>',
        'bm1',
        'Mark',
    );

    expect(scoped).toContain('.bm1-s{');
    expect(scoped).toContain('class="bm1-s"');
    expect(scoped).toContain('id="bm1-g"');
    expect(scoped).toContain('url(#bm1-g)');
    expect(scoped).not.toContain('url(#g)');
});

it('escapes a label so it cannot break out of the attribute', () => {
    const scoped = scopeBrandSvg('<svg></svg>', 'bm1', 'A "quoted" name');

    expect(scoped).toContain('aria-label="A &quot;quoted&quot; name"');
});
