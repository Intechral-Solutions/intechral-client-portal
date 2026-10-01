import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createRef, useState } from 'react';

import { DataTable, type DataTableColumn, type DataTableHandle } from '@/components/ui/data-table';

/**
 * EPIC-014 WP4 — the Direction D `DataTable` convention (§18, §9, §14.3, D9). Presentation only: it
 * knows rows, columns, an optional selection and the row-scoped keys, and nothing about what a row is.
 * Sorting, filtering and paging belong to the server, so none of them is tested (or built) here.
 */
type Item = { id: number; name: string; note: string };

const rows: Item[] = [
    { id: 1, name: 'Alpha', note: 'first' },
    { id: 2, name: 'Bravo', note: 'second' },
    { id: 3, name: 'Charlie', note: 'third' },
];

const columns: DataTableColumn<Item>[] = [
    { id: 'name', header: 'Name', area: 'title', cell: (row) => <a href={`/items/${row.id}`}>{row.name}</a> },
    { id: 'note', header: 'Note', area: 'meta', cell: (row) => row.note },
    { id: 'extra', header: 'Extra', area: 'detail', cell: () => 'x' },
];

function Table(props: Partial<React.ComponentProps<typeof DataTable<Item, number>>> = {}) {
    return (
        <DataTable<Item, number>
            label="Items"
            columns={columns}
            rows={rows}
            getRowKey={(row) => row.id}
            {...props}
        />
    );
}

describe('DataTable semantics', () => {
    it('is a named table with column headers and one row per item', () => {
        render(<Table />);

        const table = screen.getByRole('table', { name: 'Items' });
        expect(within(table).getAllByRole('columnheader').map((cell) => cell.textContent)).toEqual([
            'Name',
            'Note',
            'Extra',
        ]);
        // header row + 3 data rows
        expect(within(table).getAllByRole('row')).toHaveLength(4);
        expect(within(table).getAllByRole('cell')).toHaveLength(9);
    });

    it('keeps the table roles explicit, so the small-width reflow cannot strip them', () => {
        render(<Table />);

        expect(screen.getByRole('table').tagName).toBe('TABLE');
        expect(screen.getByRole('table')).toHaveAttribute('role', 'table');
        expect(screen.getAllByRole('row')[1]!).toHaveAttribute('role', 'row');
        expect(screen.getAllByRole('cell')[0]!).toHaveAttribute('role', 'cell');
    });

    it('can hide a header visually while keeping it for assistive technology', () => {
        render(<Table columns={[{ id: 'a', header: 'Complete', hideHeader: true, cell: () => 'x' }]} />);

        expect(screen.getByRole('columnheader', { name: 'Complete' })).toHaveClass('sr-only');
    });

    it('renders an empty row set as a table with headers only', () => {
        render(<Table rows={[]} />);

        expect(screen.getAllByRole('row')).toHaveLength(1);
    });

    it('puts a column on the line the small-width reflow names (D9)', () => {
        render(<Table />);
        const [title, meta, detail] = screen.getAllByRole('row')[1]!.querySelectorAll('td');

        expect(title).toHaveClass('max-md:order-2');
        expect(meta).toHaveClass('max-md:order-4');
        expect(detail).toHaveClass('max-md:sr-only');
    });

    it('draws no card: no shadow and no rounded container around the rows', () => {
        const { container } = render(<Table />);

        expect(container.innerHTML).not.toMatch(/shadow-|rounded-(md|lg|overlay)/);
    });

    it('applies the caller\'s row class', () => {
        render(<Table rowClassName={(row) => (row.id === 2 ? 'is-special' : undefined)} />);

        expect(screen.getAllByRole('row')[2]!).toHaveClass('is-special');
    });
});

describe('DataTable selection', () => {
    function Selecting({ onChange = vi.fn() }: { onChange?: (keys: Set<number>) => void }) {
        const [selected, setSelected] = useState<Set<number>>(new Set());

        return (
            <Table
                selection={{
                    selected,
                    onChange: (next) => {
                        setSelected(next);
                        onChange(next);
                    },
                    rowLabel: (row) => `Select ${row.name}`,
                    allLabel: 'Select all items',
                }}
                rowNavigation
            />
        );
    }

    it('adds a named checkbox per row and one for the page', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        render(<Selecting onChange={onChange} />);

        await user.click(screen.getByRole('checkbox', { name: 'Select Bravo' }));
        expect(onChange).toHaveBeenLastCalledWith(new Set([2]));

        await user.click(screen.getByRole('checkbox', { name: 'Select all items' }));
        expect(onChange).toHaveBeenLastCalledWith(new Set([1, 2, 3]));
    });

    it('hides the page select-all at S, where its header is invisible, so it is not a Tab stop there', () => {
        render(<Selecting />);

        expect(screen.getByRole('checkbox', { name: 'Select all items' })).toHaveClass('max-md:hidden');
        expect(screen.getByRole('checkbox', { name: 'Select Alpha' })).not.toHaveClass('max-md:hidden');
    });

    it('reflects a partial selection on the page checkbox and clears it from the page checkbox', async () => {
        const user = userEvent.setup();
        render(<Selecting />);
        const all = screen.getByRole('checkbox', { name: 'Select all items' });

        await user.click(screen.getByRole('checkbox', { name: 'Select Alpha' }));
        expect(all).toHaveProperty('indeterminate', true);

        await user.click(all);
        expect(screen.getAllByRole('checkbox', { checked: true })).toHaveLength(4);
        await user.click(all);
        expect(screen.queryAllByRole('checkbox', { checked: true })).toHaveLength(0);
    });

    it('marks selected rows for sighted users too', async () => {
        const user = userEvent.setup();
        render(<Selecting />);

        await user.click(screen.getByRole('checkbox', { name: 'Select Alpha' }));

        expect(screen.getAllByRole('row')[1]!).toHaveAttribute('data-selected', 'true');
        expect(screen.getAllByRole('row')[2]!).not.toHaveAttribute('data-selected');
    });
});

describe('DataTable row keys (EPIC-014 §15.1)', () => {
    function Keyed({ onKey = vi.fn() }: { onKey?: (row: Item) => void }) {
        const [selected, setSelected] = useState<Set<number>>(new Set());

        return (
            <>
                <label>
                    Filter
                    <input type="text" />
                </label>
                <Table
                    rowNavigation
                    selection={{
                        selected,
                        onChange: setSelected,
                        rowLabel: (row) => `Select ${row.name}`,
                        allLabel: 'Select all items',
                    }}
                    rowShortcuts={{ e: onKey, Enter: onKey }}
                />
            </>
        );
    }

    const row = (name: string) => screen.getByText(name).closest('tr') as HTMLElement;

    it('moves the row focus with J and K, stopping at the ends', async () => {
        const user = userEvent.setup();
        render(<Keyed />);
        row('Alpha').focus();

        await user.keyboard('j');
        expect(row('Bravo')).toHaveFocus();
        await user.keyboard('j');
        await user.keyboard('j');
        expect(row('Charlie')).toHaveFocus();
        await user.keyboard('k');
        expect(row('Bravo')).toHaveFocus();
        await user.keyboard('kk');
        expect(row('Alpha')).toHaveFocus();
    });

    it('works from a control inside the row, not only from the row itself', async () => {
        const user = userEvent.setup();
        render(<Keyed />);
        screen.getByRole('link', { name: 'Alpha' }).focus();

        await user.keyboard('j');

        expect(row('Bravo')).toHaveFocus();
    });

    it('toggles selection with X', async () => {
        const user = userEvent.setup();
        render(<Keyed />);
        row('Bravo').focus();

        await user.keyboard('x');
        expect(screen.getByRole('checkbox', { name: 'Select Bravo' })).toBeChecked();
        await user.keyboard('x');
        expect(screen.getByRole('checkbox', { name: 'Select Bravo' })).not.toBeChecked();
    });

    it('runs a row shortcut on the focused row', async () => {
        const user = userEvent.setup();
        const onKey = vi.fn();
        render(<Keyed onKey={onKey} />);
        row('Charlie').focus();

        await user.keyboard('e');

        expect(onKey).toHaveBeenCalledWith(rows[2]!);
    });

    it('opens on Enter only when the row itself has focus, so a link keeps its own Enter', async () => {
        const user = userEvent.setup();
        const onKey = vi.fn();
        render(<Keyed onKey={onKey} />);

        screen.getByRole('link', { name: 'Alpha' }).focus();
        await user.keyboard('{Enter}');
        expect(onKey).not.toHaveBeenCalled();

        row('Bravo').focus();
        await user.keyboard('{Enter}');
        expect(onKey).toHaveBeenCalledWith(rows[1]!);
    });

    it('is inactive while focus is in a text field outside the table', async () => {
        const user = userEvent.setup();
        const onKey = vi.fn();
        render(<Keyed onKey={onKey} />);

        await user.type(screen.getByLabelText('Filter'), 'jxe');

        expect(onKey).not.toHaveBeenCalled();
        expect(screen.getByLabelText('Filter')).toHaveValue('jxe');
        expect(screen.queryAllByRole('checkbox', { checked: true })).toHaveLength(0);
    });

    it('is inactive while a text field inside a row has focus', async () => {
        const user = userEvent.setup();
        const onKey = vi.fn();
        render(
            <DataTable<Item, number>
                label="Items"
                columns={[{ id: 'edit', header: 'Edit', cell: (row) => <input aria-label={`Edit ${row.name}`} /> }]}
                rows={rows}
                getRowKey={(row) => row.id}
                rowNavigation
                rowShortcuts={{ e: onKey }}
            />,
        );

        await user.type(screen.getByLabelText('Edit Alpha'), 'e');

        expect(onKey).not.toHaveBeenCalled();
    });

    it('ignores modified keys so browser and assistive shortcuts are untouched', async () => {
        const user = userEvent.setup();
        const onKey = vi.fn();
        render(<Keyed onKey={onKey} />);
        row('Alpha').focus();

        await user.keyboard('{Control>}e{/Control}');
        await user.keyboard('{Meta>}j{/Meta}');

        expect(onKey).not.toHaveBeenCalled();
        expect(row('Alpha')).toHaveFocus();
    });

    it('does nothing from the header row', async () => {
        const user = userEvent.setup();
        const onKey = vi.fn();
        render(<Keyed onKey={onKey} />);
        screen.getByRole('checkbox', { name: 'Select all items' }).focus();

        await user.keyboard('xje');

        expect(onKey).not.toHaveBeenCalled();
        expect(screen.queryAllByRole('checkbox', { checked: true })).toHaveLength(0);
    });
});

describe('DataTable focus handle', () => {
    it('focuses a row by key, and reports whether it existed', () => {
        const handle = createRef<DataTableHandle<number>>();
        render(<Table ref={handle} rowNavigation />);

        let found = false;
        act(() => {
            found = handle.current!.focusRow(2);
        });
        expect(found).toBe(true);
        expect(screen.getByText('Bravo').closest('tr')).toHaveFocus();

        act(() => {
            found = handle.current!.focusRow(99);
        });
        expect(found).toBe(false);
    });

    it('can focus the table\'s first row', () => {
        const handle = createRef<DataTableHandle<number>>();
        render(<Table ref={handle} rowNavigation />);

        act(() => {
            handle.current!.focusFirstRow();
        });

        expect(screen.getByText('Alpha').closest('tr')).toHaveFocus();
    });

    it('reports which row holds focus, whether the row or a control inside it', () => {
        const handle = createRef<DataTableHandle<number>>();
        render(<Table ref={handle} rowNavigation />);
        expect(handle.current!.focusedRowKey()).toBeNull();

        screen.getByRole('link', { name: 'Bravo' }).focus();
        expect(handle.current!.focusedRowKey()).toBe(2);

        screen.getByText('Charlie').closest('tr')!.focus();
        expect(handle.current!.focusedRowKey()).toBe(3);
    });
});
