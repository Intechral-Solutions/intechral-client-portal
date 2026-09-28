import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { PageFrame } from '@/components/page-frame';

/**
 * EPIC-013 WP7 — `PageFrame`'s contract (§25.2: "each width variant applies the documented
 * constraint; `canvas` applies no `max-w`").
 *
 * What these assert is which geometry the frame *names*, not what the geometry measures: the widths
 * live in `app.css` and jsdom loads no stylesheet, so a test claiming to check 640px here would be
 * asserting nothing. The measurements belong to the browser suite, which runs against real CSS at
 * real viewports. Splitting it that way keeps both halves honest.
 */
describe('PageFrame', () => {
    it('names the canvas geometry and constrains nothing itself', () => {
        const { container } = render(
            <PageFrame width="canvas">
                <p>Board</p>
            </PageFrame>,
        );

        const frame = container.querySelector('[data-page-frame]')!;

        expect(frame).toHaveAttribute('data-page-frame', 'canvas');
        // Canvas is the one width that must never acquire a max-width — the whole point is the full
        // viewport minus the gutters. A `max-w-*` utility appearing here would be the regression.
        expect(frame.className).not.toMatch(/max-w-/);
        expect(frame).not.toHaveAttribute('data-page-measure');
        expect(screen.getByText('Board')).toBeInTheDocument();
    });

    it('defaults the reading measure to the account column and names the chosen one', () => {
        const { container, rerender } = render(
            <PageFrame width="reading">
                <p>Form</p>
            </PageFrame>,
        );

        expect(container.querySelector('[data-page-frame]')).toHaveAttribute(
            'data-page-measure',
            'account',
        );

        rerender(
            <PageFrame width="reading" measure="forms">
                <p>Form</p>
            </PageFrame>,
        );

        expect(container.querySelector('[data-page-frame]')).toHaveAttribute(
            'data-page-measure',
            'forms',
        );
    });

    it('marks a grid frame as split only when it is actually given a supporting column', () => {
        const { container, rerender } = render(
            <PageFrame width="grid">
                <p>Main</p>
            </PageFrame>,
        );

        // No aside means one column, so the split must not be asserted — otherwise the content would
        // stop 340px short of the frame at XL for no reason.
        expect(container.querySelector('[data-page-frame]')).not.toHaveAttribute('data-page-split');
        expect(container.querySelector('[data-page-aside]')).toBeNull();

        rerender(
            <PageFrame width="grid" aside={<p>Shortcuts</p>} asideLabel="Shortcuts">
                <p>Main</p>
            </PageFrame>,
        );

        expect(container.querySelector('[data-page-frame]')).toHaveAttribute('data-page-split');
        expect(screen.getByRole('complementary', { name: 'Shortcuts' })).toBeInTheDocument();
    });

    it('spans the header across the content region rather than nesting it in a column', () => {
        const { container } = render(
            <PageFrame
                width="grid"
                header={<h1>Home</h1>}
                aside={<p>Shortcuts</p>}
                asideLabel="Shortcuts"
            >
                <p>Main</p>
            </PageFrame>,
        );

        const header = container.querySelector('[data-page-header]')!;

        // A direct child of the frame, so the grid can span it; inside the main column it would only
        // reach as far as the supporting column begins.
        expect(header.parentElement).toHaveAttribute('data-page-frame', 'grid');
        expect(header).toContainElement(screen.getByRole('heading', { name: 'Home' }));
        expect(header.querySelector('[data-page-aside]')).toBeNull();
    });

    it('draws no aside, and no landmark, for the single-column widths', () => {
        render(
            <PageFrame width="canvas">
                <p>Board</p>
            </PageFrame>,
        );

        expect(screen.queryByRole('complementary')).not.toBeInTheDocument();
    });
});
