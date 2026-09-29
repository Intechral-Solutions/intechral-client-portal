import { render, screen } from '@testing-library/react';

import { Progress } from '@/components/ui/progress';

function bar() {
    return screen.getByRole('progressbar');
}

function fill() {
    return bar().firstElementChild as HTMLElement;
}

it('exposes a named progressbar with its value, range, and fill', () => {
    render(<Progress value={40} label="Project completion" />);

    expect(screen.getByRole('progressbar', { name: 'Project completion' })).toBe(bar());
    expect(bar()).toHaveAttribute('aria-valuemin', '0');
    expect(bar()).toHaveAttribute('aria-valuemax', '100');
    expect(bar()).toHaveAttribute('aria-valuenow', '40');
    expect(fill().style.width).toBe('40%');
});

it('supports count-based progress with spoken text', () => {
    render(<Progress value={3} max={5} label="Checklist" valueText="3 of 5 items" />);

    expect(bar()).toHaveAttribute('aria-valuemax', '5');
    expect(bar()).toHaveAttribute('aria-valuenow', '3');
    expect(bar()).toHaveAttribute('aria-valuetext', '3 of 5 items');
    expect(fill().style.width).toBe('60%');
});

it.each([
    ['above the maximum', 250, 100, 100, '100%'],
    ['below zero', -5, 100, 0, '0%'],
    ['not a number', Number.NaN, 100, 0, '0%'],
    ['against an empty total', 0, 0, 0, '0%'],
    ['against a negative total', 3, -1, 0, '0%'],
])('never renders a broken bar for a value %s', (_case, value, max, now, width) => {
    render(<Progress value={value} max={max} label="Anything" />);

    expect(bar()).toHaveAttribute('aria-valuenow', String(now));
    expect(fill().style.width).toBe(width);
});

it('merges class names onto the track', () => {
    render(<Progress value={10} label="Slim" className="mt-2 h-3" />);

    expect(bar()).toHaveClass('mt-2', 'h-3');
    expect(bar()).not.toHaveClass('h-1.5');
});

it('draws a thin bar from the progress tokens: 6px summary by default, 4px inline', () => {
    const { rerender } = render(<Progress value={10} label="Summary" />);

    expect(bar()).toHaveClass('h-1.5', 'rounded-full', 'bg-progress-track');
    expect(fill()).toHaveClass('bg-progress-fill');
    // Never brand cyan (live) and never the legacy indigo: a quantity is not "live".
    expect(fill().className).not.toMatch(/bg-(live|accent|primary)/);

    rerender(<Progress value={10} label="Inline" size="sm" />);
    expect(bar()).toHaveClass('h-1');
});

it('is a progressbar, not a meter: it measures completion', () => {
    render(<Progress value={2} max={4} label="Done" />);

    expect(screen.queryByRole('meter')).not.toBeInTheDocument();
    expect(bar()).toHaveAttribute('aria-valuenow', '2');
});
