import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';

import { FilterChip } from '@/components/ui/chip';
import { FilterBar, FilterField, FilterSearch, FilterToggleGroup } from '@/components/ui/filter-bar';
import { NativeSelect } from '@/components/ui/native-select';

/**
 * EPIC-014 WP4 — `FilterBar` (Direction D §18). Presentation only: it lays controls out, hosts the
 * active chips and one "Clear filters" action. It owns no filter state and builds no URL.
 */
describe('FilterBar', () => {
    it('is a named group of its controls', () => {
        render(
            <FilterBar label="Filter tasks">
                <FilterField label="Due">
                    <NativeSelect>
                        <option value="">Any</option>
                    </NativeSelect>
                </FilterField>
            </FilterBar>,
        );

        const group = screen.getByRole('group', { name: 'Filter tasks' });
        expect(within(group).getByLabelText('Due')).toBeVisible();
    });

    it('lists the active chips and offers Clear filters only while there are any', async () => {
        const user = userEvent.setup();
        const onClear = vi.fn();
        const { rerender } = render(
            <FilterBar label="Filter tasks" chips={null} onClear={onClear} clearLabel="Clear filters">
                <span />
            </FilterBar>,
        );
        expect(screen.queryByRole('button', { name: 'Clear filters' })).not.toBeInTheDocument();
        expect(screen.queryByRole('list', { name: 'Active filters' })).not.toBeInTheDocument();

        rerender(
            <FilterBar
                label="Filter tasks"
                chips={[<FilterChip key="a" label="Due: Overdue" onRemove={() => {}} />]}
                onClear={onClear}
                clearLabel="Clear filters"
            >
                <span />
            </FilterBar>,
        );

        const list = screen.getByRole('list', { name: 'Active filters' });
        expect(within(list).getAllByRole('listitem')).toHaveLength(1);
        await user.click(screen.getByRole('button', { name: 'Clear filters' }));
        expect(onClear).toHaveBeenCalledTimes(1);
    });

    it('offers Clear filters for an active search even with no chips', () => {
        render(
            <FilterBar label="Filter tasks" chips={null} onClear={() => {}} clearLabel="Clear filters" clearable>
                <span />
            </FilterBar>,
        );

        expect(screen.getByRole('button', { name: 'Clear filters' })).toBeVisible();
    });
});

describe('FilterSearch', () => {
    it('submits its trimmed draft on Enter and on the Search button, never per keystroke', async () => {
        const user = userEvent.setup();
        const onSearch = vi.fn();
        render(<FilterSearch label="Search tasks" value="" onSearch={onSearch} />);

        await user.type(screen.getByRole('searchbox', { name: 'Search tasks' }), '  report ');
        expect(onSearch).not.toHaveBeenCalled();

        await user.keyboard('{Enter}');
        expect(onSearch).toHaveBeenLastCalledWith('report');

        await user.click(screen.getByRole('button', { name: 'Search' }));
        expect(onSearch).toHaveBeenCalledTimes(2);
    });

    it('shows the canonical server value when it changes', () => {
        const { rerender } = render(<FilterSearch label="Search tasks" value="a" onSearch={() => {}} />);
        expect(screen.getByRole('searchbox')).toHaveValue('a');

        rerender(<FilterSearch label="Search tasks" value="b" onSearch={() => {}} />);
        expect(screen.getByRole('searchbox')).toHaveValue('b');
    });

    it('bounds the input at the length the server accepts', () => {
        render(<FilterSearch label="Search tasks" value="" onSearch={() => {}} maxLength={100} />);

        expect(screen.getByRole('searchbox')).toHaveAttribute('maxlength', '100');
    });
});

describe('FilterToggleGroup', () => {
    const options = [
        { value: 'low', label: 'Low' },
        { value: 'high', label: 'High' },
    ];

    it('is a named group of pressed-state toggles', () => {
        render(<FilterToggleGroup label="Priority" options={options} selected={['high']} onChange={() => {}} />);

        const group = screen.getByRole('group', { name: 'Priority' });
        expect(within(group).getByRole('button', { name: 'Low' })).toHaveAttribute('aria-pressed', 'false');
        expect(within(group).getByRole('button', { name: 'High' })).toHaveAttribute('aria-pressed', 'true');
    });

    it('reports the next selection in option order', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        render(<FilterToggleGroup label="Priority" options={options} selected={['high']} onChange={onChange} />);

        await user.click(screen.getByRole('button', { name: 'Low' }));
        expect(onChange).toHaveBeenLastCalledWith(['low', 'high']);

        await user.click(screen.getByRole('button', { name: 'High' }));
        expect(onChange).toHaveBeenLastCalledWith([]);
    });
});

describe('FilterBar focus after a removal', () => {
    // Removing a chip drops it at once here; the real page drops it when the server answers. Either
    // way the pressed button unmounts and focus would fall to the document.
    function Removing({ initial = ['A', 'B', 'C'] }: { initial?: string[] }) {
        const [labels, setLabels] = useState(initial);

        return (
            <FilterBar
                label="Filter tasks"
                chips={labels.map((label) => (
                    <FilterChip
                        key={label}
                        label={label}
                        onRemove={() => setLabels((current) => current.filter((value) => value !== label))}
                    />
                ))}
                onClear={() => setLabels([])}
                clearable={labels.length > 0}
            >
                <input aria-label="Search" />
                <button type="button">Elsewhere</button>
            </FilterBar>
        );
    }

    it('moves to the chip that took its place, then the previous one at the end', async () => {
        const user = userEvent.setup();
        render(<Removing />);

        await user.click(screen.getByRole('button', { name: 'Remove filter: B' }));
        expect(screen.getByRole('button', { name: 'Remove filter: C' })).toHaveFocus();

        await user.click(screen.getByRole('button', { name: 'Remove filter: C' }));
        expect(screen.getByRole('button', { name: 'Remove filter: A' })).toHaveFocus();
    });

    it('moves to the first control of the bar when the last chip or Clear filters goes', async () => {
        const user = userEvent.setup();
        const { unmount } = render(<Removing initial={['A']} />);

        await user.click(screen.getByRole('button', { name: 'Remove filter: A' }));
        expect(screen.getByLabelText('Search')).toHaveFocus();
        unmount();

        render(<Removing />);
        await user.click(screen.getByRole('button', { name: 'Clear filters' }));
        expect(screen.getByLabelText('Search')).toHaveFocus();
    });

    it('does not take focus the user has since moved elsewhere', async () => {
        const user = userEvent.setup();
        let remove: () => void = () => {};
        function Slow() {
            const [labels, setLabels] = useState(['A', 'B']);
            remove = () => setLabels(['B']);

            return (
                <FilterBar
                    label="Filter tasks"
                    chips={labels.map((label) => (
                        <FilterChip key={label} label={label} onRemove={() => {}} />
                    ))}
                >
                    <button type="button">Elsewhere</button>
                </FilterBar>
            );
        }
        render(<Slow />);

        await user.click(screen.getByRole('button', { name: 'Remove filter: A' }));
        await user.click(screen.getByRole('button', { name: 'Elsewhere' }));
        act(() => remove());

        expect(screen.getByRole('button', { name: 'Elsewhere' })).toHaveFocus();
    });
});
