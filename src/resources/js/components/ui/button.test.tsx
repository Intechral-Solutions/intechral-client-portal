import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createRef } from 'react';

import { Button, buttonVariants } from '@/components/ui/button';
import { controlHeight } from '@/components/ui/control-metrics';

it('is a native button that activates from the keyboard and pointer', async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    render(<Button onClick={onClick}>Save</Button>);

    const button = screen.getByRole('button', { name: 'Save' });
    expect(button.tagName).toBe('BUTTON');

    button.focus();
    await user.keyboard('{Enter}');
    await user.keyboard(' ');
    await user.click(button);

    expect(onClick).toHaveBeenCalledTimes(3);
});

it('is disabled programmatically and cannot be activated', async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    render(
        <Button disabled onClick={onClick}>
            Save
        </Button>,
    );

    const button = screen.getByRole('button', { name: 'Save' });
    expect(button).toBeDisabled();
    await user.click(button);

    expect(onClick).not.toHaveBeenCalled();
});

it('forwards its ref and merges consumer classes over the variant', () => {
    const ref = createRef<HTMLButtonElement>();
    render(
        <Button ref={ref} className="h-12 w-full">
            Wide
        </Button>,
    );

    const button = screen.getByRole('button', { name: 'Wide' });
    expect(ref.current).toBe(button);
    expect(button).toHaveClass('w-full', 'h-12');
    expect(button).not.toHaveClass('h-9');
});

it('renders a link with the button treatment through asChild, not a nested button', () => {
    render(
        <Button asChild variant="secondary">
            <a href="/projects">Projects</a>
        </Button>,
    );

    const link = screen.getByRole('link', { name: 'Projects' });
    expect(link).toHaveAttribute('href', '/projects');
    expect(link).toHaveClass('border-control-edge', 'bg-surface');
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
});

// Styling contract, not a class snapshot: the Direction D rules that must not drift.
describe('variant contract', () => {
    it('fills the primary action with ink, and never with accent or live (cyan is not a CTA fill)', () => {
        const classes = buttonVariants({ variant: 'primary' });

        expect(classes).toContain('bg-ink');
        expect(classes).toContain('text-on-ink');
        expect(classes).not.toMatch(/bg-(accent|live|primary|brand)/);
    });

    it('keeps the pre-Direction D names working as aliases', () => {
        expect(buttonVariants({ variant: 'default' })).toBe(buttonVariants({ variant: 'primary' }));
        expect(buttonVariants({ variant: 'outline' })).toBe(buttonVariants({ variant: 'secondary' }));
        expect(buttonVariants()).toBe(buttonVariants({ variant: 'primary', size: 'md' }));
    });

    it('draws secondary as a surface with the control edge, and destructive from the danger alias', () => {
        expect(buttonVariants({ variant: 'secondary' })).toContain('border-control-edge');
        expect(buttonVariants({ variant: 'secondary' })).not.toContain('border-rule-control');
        expect(buttonVariants({ variant: 'secondary' })).toContain('bg-surface');
        expect(buttonVariants({ variant: 'destructive' })).toContain('bg-destructive');
    });

    it('never carries two classes for the same property, since links use the raw string', () => {
        for (const variant of ['primary', 'secondary', 'ghost', 'destructive'] as const) {
            for (const size of ['sm', 'md', 'lg', 'icon'] as const) {
                const classes = buttonVariants({ variant, size }).split(' ');
                const of = (pattern: RegExp) => classes.filter((c) => pattern.test(c));

                expect(of(/^text-(xs|sm|base)$/)).toHaveLength(1);
                expect(of(/^border-(?!rule$)[a-z-]+$/)).toHaveLength(1);
                expect(of(/^h-\d/)).toHaveLength(size === 'icon' ? 0 : 1);
            }
        }
    });

    it('keeps a disabled label readable: muted text, never the faint token', () => {
        for (const variant of ['primary', 'secondary', 'ghost', 'destructive'] as const) {
            expect(buttonVariants({ variant })).toContain('disabled:text-text-muted');
            expect(buttonVariants({ variant })).not.toContain('text-faint');
        }
    });

    it('takes its height from the shared control scale', () => {
        expect(buttonVariants({ size: 'sm' })).toContain(controlHeight.sm);
        expect(buttonVariants({ size: 'md' })).toContain(controlHeight.md);
        expect(buttonVariants({ size: 'lg' })).toContain(controlHeight.lg);
    });
});
