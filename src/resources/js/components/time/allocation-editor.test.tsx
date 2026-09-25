import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';

import { AllocationEditor } from '@/components/time/allocation-editor';
import type { AllocationEntry } from '@/types/time';

function entriesWith(first: number, second: number): AllocationEntry[] {
    return [
        {
            id: 1,
            description: 'Implementation',
            locked: false,
            context: null,
            blocks: [{ id: 10, blockNumber: 36, allocationPct: first, isOverridden: false }],
        },
        {
            id: 2,
            description: 'Review',
            locked: false,
            context: null,
            blocks: [{ id: 11, blockNumber: 36, allocationPct: second, isOverridden: false }],
        },
    ];
}

const entries = entriesWith(60, 40);

it('offers a keyboard-operable adjustment for each block', async () => {
    const onAdjust = vi.fn();
    const user = userEvent.setup();
    render(<AllocationEditor entries={entries} processingSlots={new Set()} onAdjust={onAdjust} />);

    const input = screen.getByLabelText('Implementation percentage');
    await user.clear(input);
    await user.type(input, '75');
    await user.click(screen.getAllByRole('button', { name: 'Save' })[0]!);

    expect(onAdjust).toHaveBeenCalledWith(10, 75, 36);
    expect(screen.getByText('Total 100.0%')).toBeInTheDocument();
});

it('saves from the keyboard alone with Enter and ignores out-of-range values', async () => {
    const onAdjust = vi.fn();
    const user = userEvent.setup();
    render(<AllocationEditor entries={entries} processingSlots={new Set()} onAdjust={onAdjust} />);

    const input = screen.getByLabelText('Implementation percentage');
    await user.clear(input);
    await user.type(input, '150{Enter}');
    expect(onAdjust).not.toHaveBeenCalled();

    await user.clear(input);
    await user.type(input, '80{Enter}');
    expect(onAdjust).toHaveBeenCalledOnce();
    expect(onAdjust).toHaveBeenCalledWith(10, 80, 36);
});

it('disables an entire allocation slot when a sibling is locked', () => {
    render(
        <AllocationEditor
            entries={[entries[0]!, { ...entries[1]!, locked: true }]}
            processingSlots={new Set()}
            onAdjust={vi.fn()}
        />,
    );

    expect(screen.getByLabelText('Implementation percentage')).toBeDisabled();
    expect(screen.getByLabelText('Review percentage')).toBeDisabled();
    expect(screen.getByText('Locked')).toBeInTheDocument();
});

it('adopts authoritative values without remounting the controls or dropping focus', async () => {
    const user = userEvent.setup();
    const { rerender } = render(
        <AllocationEditor entries={entries} processingSlots={new Set()} onAdjust={vi.fn()} />,
    );
    const input = screen.getByLabelText('Implementation percentage');
    const save = screen.getAllByRole('button', { name: 'Save' })[0]!;
    const sibling = screen.getByLabelText('Review percentage');
    await user.clear(input);
    await user.type(input, '75');
    await user.click(save);
    expect(save).toHaveFocus();

    // Save in flight, then the server-redistributed values arrive.
    rerender(
        <AllocationEditor
            entries={entriesWith(75, 25)}
            processingSlots={new Set([36])}
            onAdjust={vi.fn()}
        />,
    );
    rerender(
        <AllocationEditor
            entries={entriesWith(75, 25)}
            processingSlots={new Set()}
            onAdjust={vi.fn()}
        />,
    );

    // The same DOM nodes survive, so keyboard focus is not destroyed.
    expect(screen.getByLabelText('Implementation percentage')).toBe(input);
    expect(screen.getAllByRole('button', { name: 'Save' })[0]).toBe(save);
    expect(save).toHaveFocus();
    expect(input).toHaveValue(75);
    expect(sibling).toHaveValue(25);
    expect(screen.getByText('Total 100.0%')).toBeInTheDocument();
});

it('keeps the focused control focusable while a slot save is in flight', async () => {
    const onAdjust = vi.fn();
    const user = userEvent.setup();
    const { rerender } = render(
        <AllocationEditor entries={entries} processingSlots={new Set()} onAdjust={onAdjust} />,
    );
    const input = screen.getByLabelText('Implementation percentage');
    input.focus();

    rerender(
        <AllocationEditor entries={entries} processingSlots={new Set([36])} onAdjust={onAdjust} />,
    );

    // Disabling a focused control makes browsers drop focus to <body>; read-only and
    // aria-disabled keep it in place while still blocking edits and repeat saves.
    expect(input).not.toBeDisabled();
    expect(input).toHaveFocus();
    expect(input).toHaveAttribute('readonly');
    const save = screen.getAllByRole('button', { name: 'Save' })[0]!;
    expect(save).toHaveAttribute('aria-disabled', 'true');
    await user.click(save);
    expect(onAdjust).not.toHaveBeenCalled();
});

it('retains an in-progress draft when a save fails and the server value is unchanged', async () => {
    const user = userEvent.setup();
    const { rerender } = render(
        <AllocationEditor entries={entries} processingSlots={new Set()} onAdjust={vi.fn()} />,
    );
    const input = screen.getByLabelText('Implementation percentage');
    await user.clear(input);
    await user.type(input, '75');

    rerender(
        <AllocationEditor entries={entries} processingSlots={new Set([36])} onAdjust={vi.fn()} />,
    );
    rerender(<AllocationEditor entries={entries} processingSlots={new Set()} onAdjust={vi.fn()} />);

    expect(screen.getByLabelText('Implementation percentage')).toBe(input);
    expect(input).toHaveValue(75);
});
