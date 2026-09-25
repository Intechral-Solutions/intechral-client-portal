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
    render(<Progress value={10} label="Slim" className="h-1" />);

    expect(bar()).toHaveClass('h-1');
    expect(bar()).not.toHaveClass('h-2');
});
