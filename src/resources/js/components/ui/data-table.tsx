import { useEffect, useImperativeHandle, useRef } from 'react';
import type { KeyboardEvent, ReactNode, Ref } from 'react';

import { focusRing } from '@/components/ui/control-metrics';
import { cn } from '@/lib/utils';

/**
 * EPIC-014 WP4 — the Direction D `DataTable` convention (§18, §9 canvas surface, §14 keyboard, D9).
 *
 * **Presentation only.** It renders rows and columns, an optional selection column and the
 * row-scoped keys, and knows nothing about what a row is: no domain types, routes, permissions or
 * data fetching. Sorting, filtering and paging are the server's (EPIC-014 §9), so a header here is a
 * label, never a sort control, and a page of rows is whatever the caller hands in.
 *
 * Structure: flat, rule-bounded and dense (Direction D §4.4 level 0: no card, no shadow, no rounded
 * container). It is a real `<table>` with **explicit ARIA roles on every part**, because the small
 * width (D9) reflows rows with `display: flex`, and a table whose parts change `display` can lose
 * its table semantics in some browser/AT pairs. The roles keep the table a table at every width.
 *
 * Small-width reflow (D9, `max-md:`, the same 768px as the shell's S class): each row becomes a
 * wrapping line: `lead` cells (the selection checkbox, a state control) and the `title` cell on the
 * first line, then a forced break, then the `meta` cells on the second. A `detail` cell is visually
 * hidden there but stays in the accessibility tree. The header row is visually hidden (`sr-only`)
 * and stays for assistive technology, except its select-all checkbox, which is hidden outright there:
 * a control nobody can see must not be a Tab stop (WCAG 2.4.7), and rows stay selectable one by one.
 * Nothing scrolls the document sideways; at M and up the table
 * scrolls inside its own wrapper rather than being clipped by it.
 *
 * Keyboard (EPIC-014 §15.1, Direction D §14.3), only while focus is inside the table and not in a
 * text field, and never with Ctrl/Meta/Alt held (WCAG 2.1.4; browser and AT shortcuts stay theirs):
 * `J`/`K` move the row focus, `X` toggles the row's selection, and `rowShortcuts` binds any other key
 * to a row. `Enter` is bound only when the *row itself* is focused, so a link or button inside the row
 * keeps its native Enter.
 */
type Key = string | number;

export type DataTableColumn<T> = {
    id: string;
    /** Header text. Always present for assistive technology, even when `hideHeader` hides it. */
    header: string;
    hideHeader?: boolean;
    cell: (row: T) => ReactNode;
    /**
     * Where the cell goes in the small-width reflow. `lead`/`title` share the first line, `meta` the
     * second; `detail` is visually hidden there. Default `meta`.
     */
    area?: 'lead' | 'title' | 'meta' | 'detail';
    className?: string;
};

export type DataTableSelection<T, K extends Key> = {
    selected: ReadonlySet<K>;
    onChange: (next: Set<K>) => void;
    rowLabel: (row: T) => string;
    allLabel: string;
};

export type DataTableHandle<K extends Key = Key> = {
    /** Focus a row by key. Returns whether such a row is rendered. */
    focusRow(key: K): boolean;
    focusFirstRow(): void;
    /** The key of the row that holds focus (the row itself or anything inside it), or `null`. */
    focusedRowKey(): K | null;
};

const areaClass: Record<NonNullable<DataTableColumn<unknown>['area']>, string> = {
    lead: 'max-md:order-1',
    title: 'max-md:order-2 max-md:min-w-0 max-md:flex-1',
    meta: 'max-md:order-4',
    detail: 'max-md:sr-only',
};

function isTextEntry(element: EventTarget | null) {
    if (!(element instanceof HTMLElement)) return false;
    if (element.isContentEditable) return true;
    if (element.tagName === 'TEXTAREA' || element.tagName === 'SELECT') return true;
    if (element instanceof HTMLInputElement) {
        return !['checkbox', 'radio', 'button', 'submit', 'reset'].includes(element.type);
    }

    return false;
}

export function DataTable<T, K extends Key = Key>({
    label,
    columns,
    rows,
    getRowKey,
    rowClassName,
    selection,
    rowNavigation = false,
    rowShortcuts,
    ref,
}: {
    /** The table's accessible name. */
    label: string;
    columns: DataTableColumn<T>[];
    rows: readonly T[];
    getRowKey: (row: T) => K;
    rowClassName?: (row: T) => string | undefined;
    selection?: DataTableSelection<T, K>;
    /** Turn on `J`/`K` (and `X` when there is a selection). */
    rowNavigation?: boolean;
    /** Extra single-key row bindings, by `event.key` (letters lower-case), e.g. `{ e: …, Enter: … }`. */
    rowShortcuts?: Record<string, (row: T) => void>;
    ref?: Ref<DataTableHandle<K>>;
}) {
    const rowElements = useRef(new Map<string, HTMLTableRowElement>());
    const allBox = useRef<HTMLInputElement>(null);

    const selectedCount = selection ? rows.filter((row) => selection.selected.has(getRowKey(row))).length : 0;

    useEffect(() => {
        if (allBox.current) {
            allBox.current.indeterminate = selectedCount > 0 && selectedCount < rows.length;
        }
    }, [selectedCount, rows.length]);

    useImperativeHandle(
        ref,
        () => ({
            focusRow(key) {
                const element = rowElements.current.get(String(key));
                element?.focus();

                return Boolean(element);
            },
            focusFirstRow() {
                const first = rows[0];
                if (first !== undefined) rowElements.current.get(String(getRowKey(first)))?.focus();
            },
            focusedRowKey() {
                const active = document.activeElement?.closest('tr[data-row-key]');
                const row = active ? rows.find((candidate) => rowElements.current.get(String(getRowKey(candidate))) === active) : undefined;

                return row === undefined ? null : getRowKey(row);
            },
        }),
        [rows, getRowKey],
    );

    function toggle(row: T) {
        if (!selection) return;

        const next = new Set(selection.selected);
        const key = getRowKey(row);
        if (next.has(key)) next.delete(key);
        else next.add(key);
        selection.onChange(next);
    }

    function onKeyDown(event: KeyboardEvent<HTMLTableElement>) {
        if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) return;
        if (isTextEntry(event.target)) return;

        const rowElement = (event.target as HTMLElement).closest<HTMLTableRowElement>('tr[data-row-key]');
        if (!rowElement) return;

        const index = rows.findIndex((row) => String(getRowKey(row)) === rowElement.dataset.rowKey);
        if (index < 0) return;
        const row = rows[index];
        if (row === undefined) return;
        const key = event.key.length === 1 ? event.key.toLowerCase() : event.key;

        if (rowNavigation && (key === 'j' || key === 'k')) {
            const target = rows[index + (key === 'j' ? 1 : -1)];
            if (target) rowElements.current.get(String(getRowKey(target)))?.focus();
            event.preventDefault();

            return;
        }

        if (rowNavigation && selection && key === 'x') {
            toggle(row);
            event.preventDefault();

            return;
        }

        const shortcut = rowShortcuts?.[key];
        if (!shortcut) return;
        // Enter belongs to a link or button inside the row unless the row itself has focus.
        if (key === 'Enter' && event.target !== rowElement) return;

        shortcut(row);
        event.preventDefault();
    }

    return (
        <div className="md:overflow-x-auto">
            <table
                role="table"
                aria-label={label}
                onKeyDown={onKeyDown}
                className="w-full border-collapse text-sm max-md:block"
            >
                <thead role="rowgroup" className="max-md:sr-only">
                    <tr role="row" className="border-b border-rule-control">
                        {selection ? (
                            <th role="columnheader" scope="col" className="w-10 px-3 py-2 text-left">
                                <input
                                    ref={allBox}
                                    type="checkbox"
                                    aria-label={selection.allLabel}
                                    checked={rows.length > 0 && selectedCount === rows.length}
                                    onChange={(event) =>
                                        selection.onChange(
                                            event.target.checked ? new Set(rows.map(getRowKey)) : new Set(),
                                        )
                                    }
                                    className={cn('size-4 rounded-sm accent-ink max-md:hidden', focusRing)}
                                />
                            </th>
                        ) : null}
                        {columns.map((column) => (
                            <th
                                key={column.id}
                                role="columnheader"
                                scope="col"
                                className={cn(
                                    'px-3 py-2 text-left text-xs font-semibold tracking-wide text-text-muted uppercase',
                                    column.hideHeader && 'sr-only',
                                )}
                            >
                                {column.header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody role="rowgroup" className="max-md:block">
                    {rows.map((row) => {
                        const key = getRowKey(row);
                        const selected = selection?.selected.has(key) ?? false;

                        return (
                            <tr
                                key={key}
                                role="row"
                                tabIndex={-1}
                                data-row-key={String(key)}
                                data-selected={selected ? 'true' : undefined}
                                ref={(element) => {
                                    if (element) rowElements.current.set(String(key), element);
                                    else rowElements.current.delete(String(key));
                                }}
                                className={cn(
                                    'border-b border-rule hover:bg-surface-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus data-[selected=true]:bg-surface-selected',
                                    'max-md:flex max-md:min-h-16 max-md:flex-wrap max-md:items-center max-md:gap-x-2 max-md:gap-y-1 max-md:py-2 max-md:before:order-3 max-md:before:basis-full max-md:before:content-[""]',
                                    rowClassName?.(row),
                                )}
                            >
                                {selection ? (
                                    <td role="cell" className={cn('w-10 px-3 py-2 max-md:px-1.5 max-md:py-0', areaClass.lead)}>
                                        <input
                                            type="checkbox"
                                            aria-label={selection.rowLabel(row)}
                                            checked={selected}
                                            onChange={() => toggle(row)}
                                            className={cn('size-4 rounded-sm accent-ink', focusRing)}
                                        />
                                    </td>
                                ) : null}
                                {columns.map((column) => (
                                    <td
                                        key={column.id}
                                        role="cell"
                                        className={cn(
                                            'px-3 py-2 align-middle max-md:px-1.5 max-md:py-0',
                                            areaClass[column.area ?? 'meta'],
                                            column.className,
                                        )}
                                    >
                                        {column.cell(row)}
                                    </td>
                                ))}
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
