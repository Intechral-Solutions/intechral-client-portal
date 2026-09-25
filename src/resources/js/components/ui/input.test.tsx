import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createRef } from 'react';

import { buttonVariants } from '@/components/ui/button';
import { controlHeight } from '@/components/ui/control-metrics';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';

it('is a native input named by its label, accepting typing and long content', async () => {
    const user = userEvent.setup();
    const long = 'a'.repeat(400);
    render(
        <>
            <Label htmlFor="name">Name</Label>
            <Input id="name" />
        </>,
    );

    const input = screen.getByLabelText('Name');
    expect(input.tagName).toBe('INPUT');
    await user.click(input);
    await user.paste(long);

    expect(input).toHaveValue(long);
});

it('forwards its ref, merges consumer classes and honours disabled', async () => {
    const user = userEvent.setup();
    const ref = createRef<HTMLInputElement>();
    render(<Input ref={ref} aria-label="Email" className="max-w-xs" disabled />);

    const input = screen.getByLabelText('Email');
    expect(ref.current).toBe(input);
    expect(input).toBeDisabled();
    expect(input).toHaveClass('max-w-xs');

    await user.type(input, 'x');
    expect(input).toHaveValue('');
});

it('exposes invalidity to assistive technology and styles it from the attribute', () => {
    render(
        <>
            <Input aria-label="Email" aria-invalid="true" aria-describedby="email-error" />
            <p id="email-error">Enter a valid address.</p>
        </>,
    );

    const input = screen.getByLabelText('Email');
    expect(input).toBeInvalid();
    expect(input).toHaveAccessibleDescription('Enter a valid address.');
    // The invalid edge is driven by the attribute, so no consumer has to remember a class.
    expect(input).toHaveClass('aria-invalid:border-danger');
});

it('gives Input, NativeSelect and Button the same explicit control height', () => {
    render(
        <>
            <Input aria-label="Field" />
            <NativeSelect aria-label="Choice">
                <option>One</option>
            </NativeSelect>
            <Textarea aria-label="Notes" />
        </>,
    );

    expect(screen.getByLabelText('Field')).toHaveClass(controlHeight.md);
    expect(screen.getByLabelText('Choice')).toHaveClass(controlHeight.md);
    expect(buttonVariants({ size: 'md' })).toContain(controlHeight.md);
    // A Textarea stays naturally multi-line: a minimum, never a fixed height.
    expect(screen.getByLabelText('Notes')).toHaveClass('min-h-24');
    expect(screen.getByLabelText('Notes').className).not.toMatch(/(^| )h-\d/);
});

it.each([
    ['Input', <Input key="i" aria-label="Field" />],
    ['Textarea', <Textarea key="t" aria-label="Field" />],
    ['NativeSelect', <NativeSelect key="s" aria-label="Field"><option>One</option></NativeSelect>],
])('%s draws its resting boundary with the interactive-control edge, not the structural hairline', (_name, element) => {
    render(element);

    const field = screen.getByLabelText('Field');
    expect(field).toHaveClass('border', 'border-control-edge');
    expect(field.className.split(' ')).not.toContain('border-rule-control');
    // A 1px edge, so the explicit control heights do not move.
    expect(field.className.split(' ')).not.toEqual(expect.arrayContaining(['border-2']));
    // Hover strengthens, invalid recolours: both stay distinct from rest.
    expect(field).toHaveClass('hover:border-text-muted', 'aria-invalid:border-danger');
    // A disabled field keeps its value legible.
    expect(field).toHaveClass('disabled:text-text-muted');
    expect(field.className).not.toContain('text-faint');
});
