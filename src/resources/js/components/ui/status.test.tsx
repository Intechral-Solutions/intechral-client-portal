import { render, screen } from '@testing-library/react';

import { Status, type StatusTone } from '@/components/ui/status';

const tones: StatusTone[] = ['neutral', 'info', 'success', 'warning', 'danger', 'live'];

function glyphOf(container: HTMLElement) {
    return container.querySelector('svg') as SVGElement;
}

/** A structural fingerprint of the glyph: the drawn shapes, never the colour. */
function shapeOf(container: HTMLElement) {
    return Array.from(glyphOf(container).children)
        .map((child) => `${child.tagName}:${child.getAttribute('d') ?? ''}:${child.getAttribute('fill')}:${child.getAttribute('stroke-dasharray') ?? ''}`)
        .join('|');
}

it.each(tones)('shows a visible text label with a decorative glyph (%s)', (tone) => {
    const { container } = render(<Status tone={tone}>Some label</Status>);

    expect(screen.getByText('Some label')).toBeVisible();
    expect(glyphOf(container)).toHaveAttribute('aria-hidden', 'true');
    // The accessible name is the label alone: the glyph adds no noise for a screen reader.
    expect(container.firstElementChild).toHaveTextContent(/^Some label$/);
});

it('never lets two tones differ by colour alone: every tone has its own glyph shape', () => {
    const shapes = tones.map((tone) => {
        const { container } = render(<Status tone={tone}>x</Status>);

        return shapeOf(container);
    });

    // neutral, info, success, warning, danger and live are all distinct shapes.
    expect(new Set(shapes).size).toBe(tones.length);
});

it('lets a domain pick a specific shape without losing the label or tone colour', () => {
    const { container } = render(
        <Status tone="warning" glyph="dashed">
            On hold
        </Status>,
    );

    expect(shapeOf(container)).toContain('2.2 1.6');
    expect(container.firstElementChild).toHaveClass('text-warning');
});

it('uses the text-safe token for the label and the shape token for the glyph', () => {
    const { container } = render(<Status tone="success">Done</Status>);

    expect(container.firstElementChild).toHaveClass('text-success');
    expect(glyphOf(container)).toHaveClass('text-success-glyph');

    const live = render(<Status tone="live">Running</Status>);
    expect(live.container.firstElementChild).toHaveClass('text-live-text');
    expect(glyphOf(live.container)).toHaveClass('text-live');
});

it('is not a pill and knows no domain vocabulary', () => {
    const { container } = render(<Status>Waiting on customer</Status>);

    expect(container.firstElementChild?.className).not.toMatch(/rounded|bg-/);
    expect(screen.getByText('Waiting on customer')).toBeInTheDocument();
});

it('renders a hostile label as text, never as markup', () => {
    const hostile = '<img src=x onerror="window.__pwned = true"><b>bold</b>';
    const { container } = render(<Status tone="danger">{hostile}</Status>);

    expect(screen.getByText(hostile)).toBeInTheDocument();
    expect(container.querySelector('img, b')).not.toBeInTheDocument();
});
