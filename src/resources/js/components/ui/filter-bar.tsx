import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent, MouseEvent, ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { controlHeight, focusRing } from '@/components/ui/control-metrics';
import { Input } from '@/components/ui/input';
import { focusIsLost } from '@/lib/focus';
import { cn } from '@/lib/utils';

/**
 * EPIC-014 WP4 — `FilterBar` and its three small pieces (Direction D §18). Presentation only: it lays
 * labelled controls out, hosts the active chips and offers one "Clear filters". It owns **no** filter
 * state, builds **no** URL and fetches nothing — a caller reports each change and the server returns
 * the canonical state, so there is never a second filter truth in the browser.
 *
 * Removing a chip, or pressing Clear, unmounts the very control that was pressed once the server
 * answers. The bar then puts focus where it can be used again: the chip now in the same place, else the
 * last one, else the bar's first control (the search). It only does so if focus was actually lost, so it
 * never takes focus from where the user has moved.
 *
 * It is a named `group`, not a landmark: a page already has the shell's navigation, `main` and its
 * own regions, and a filter row does not earn another landmark to tab past.
 */
export function FilterBar({
    label,
    children,
    chips,
    onClear,
    clearLabel = 'Clear filters',
    clearable = false,
}: {
    /** The group's accessible name, e.g. "Filter tasks". */
    label: string;
    /** The controls: `FilterSearch`, `FilterField`s and `FilterToggleGroup`s. */
    children: ReactNode;
    /** The active filters as `FilterChip`s. Nothing is rendered for an empty or absent list. */
    chips?: ReactNode[] | null;
    onClear?: () => void;
    clearLabel?: string;
    /** Offer Clear even with no chip, for narrowing that has none of its own (an active search). */
    clearable?: boolean;
}) {
    const hasChips = Boolean(chips && chips.length > 0);
    const group = useRef<HTMLDivElement>(null);
    // The pressed control and its position among the chips (-1: Clear), until the response removes it.
    const pressed = useRef<{ button: Element; index: number } | null>(null);

    function rememberPress(event: MouseEvent<HTMLDivElement>) {
        const button = (event.target as HTMLElement).closest('button');
        if (!button) return;

        const item = button.closest('li');
        const items = [...(item?.parentElement?.children ?? [])];
        pressed.current = { button, index: item ? items.indexOf(item) : -1 };
    }

    useEffect(() => {
        const press = pressed.current;
        if (!press || press.button.isConnected) return;

        pressed.current = null;
        if (!focusIsLost() || !group.current) return;

        const remaining = group.current.querySelectorAll<HTMLElement>('ul[aria-label="Active filters"] button');
        const next = press.index >= 0 ? remaining[Math.min(press.index, remaining.length - 1)] : undefined;
        (next ?? group.current.querySelector<HTMLElement>('input, select, button'))?.focus();
    });

    return (
        <div ref={group} role="group" aria-label={label} className="flex flex-col gap-3">
            <div className="flex flex-wrap items-end gap-x-3 gap-y-3 max-md:grid max-md:grid-cols-2">{children}</div>

            {hasChips || (clearable && onClear) ? (
                <div className="flex flex-wrap items-center gap-2" onClickCapture={rememberPress}>
                    {hasChips ? (
                        <ul aria-label="Active filters" className="flex flex-wrap items-center gap-2">
                            {chips!.map((chip, index) => (
                                <li key={index}>{chip}</li>
                            ))}
                        </ul>
                    ) : null}
                    {onClear ? (
                        <Button type="button" variant="ghost" size="sm" onClick={onClear}>
                            {clearLabel}
                        </Button>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}

/** A visible label over one control (a select). The label wraps the control, so it names it. */
export function FilterField({
    label,
    children,
    className,
}: {
    label: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <label className={cn('flex min-w-0 flex-col gap-1 text-xs font-medium text-text-secondary', className)}>
            <span>{label}</span>
            {children}
        </label>
    );
}

/**
 * The search field. Explicit, not as-you-type (the Time page's Apply convention): it commits on
 * Enter or the Search button, so typing fires no request and nothing is sent per keystroke. The
 * draft is local, but the canonical value is whatever the server last returned: when `value` changes
 * (a visit settled, or Clear filters) the draft takes it.
 */
export function FilterSearch({
    label,
    value,
    onSearch,
    maxLength,
    className,
}: {
    label: string;
    value: string;
    onSearch: (value: string) => void;
    maxLength?: number;
    className?: string;
}) {
    const [draft, setDraft] = useState(value);
    const [seen, setSeen] = useState(value);

    // Adopt the canonical value during render (the documented alternative to an effect).
    if (value !== seen) {
        setSeen(value);
        setDraft(value);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        onSearch(draft.trim());
    }

    return (
        <form onSubmit={submit} className={cn('flex min-w-0 items-end gap-2 max-md:col-span-2', className)}>
            <label className="flex min-w-0 flex-1 flex-col gap-1 text-xs font-medium text-text-secondary">
                <span>{label}</span>
                <Input
                    type="search"
                    value={draft}
                    onChange={(event) => setDraft(event.target.value)}
                    maxLength={maxLength}
                    autoComplete="off"
                    className="min-w-0 md:w-64"
                />
            </label>
            <Button type="submit" variant="secondary" aria-label="Search" className="shrink-0">
                <Search className="size-4" aria-hidden="true" />
                <span className="max-md:sr-only">Search</span>
            </Button>
        </form>
    );
}

/**
 * A small named group of pressed-state toggles for a **multi**-value filter. Native buttons with
 * `aria-pressed`, so every toggle is Tab/Space reachable with no composite-widget keyboard model to
 * get wrong. The next selection is reported in option order, so the caller's canonical order holds.
 */
export function FilterToggleGroup<T extends string>({
    label,
    options,
    selected,
    onChange,
}: {
    label: string;
    options: readonly { value: T; label: string }[];
    selected: readonly T[];
    onChange: (next: T[]) => void;
}) {
    return (
        <div role="group" aria-label={label} className="flex min-w-0 flex-col gap-1 max-md:col-span-2">
            <span aria-hidden="true" className="text-xs font-medium text-text-secondary">
                {label}
            </span>
            <div className="flex flex-wrap gap-1">
                {options.map((option) => {
                    const pressed = selected.includes(option.value);

                    return (
                        <button
                            key={option.value}
                            type="button"
                            aria-pressed={pressed}
                            onClick={() =>
                                onChange(
                                    options
                                        .map((candidate) => candidate.value)
                                        .filter((value) =>
                                            value === option.value ? !pressed : selected.includes(value),
                                        ),
                                )
                            }
                            className={cn(
                                'rounded-control border px-2.5 text-xs font-medium transition-[color,background-color,border-color] duration-motion-fast',
                                controlHeight.sm,
                                focusRing,
                                pressed
                                    ? 'border-ink bg-ink text-on-ink'
                                    : 'border-control-edge bg-surface text-text hover:bg-surface-hover',
                            )}
                        >
                            {option.label}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
