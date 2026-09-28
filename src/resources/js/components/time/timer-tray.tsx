import { Link } from '@inertiajs/react';
import * as Popover from '@radix-ui/react-popover';
import { RefreshCw, Square } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';

import { TimerContextLink } from '@/components/time/timer-context-link';
import { useTimers } from '@/components/time/timer-provider';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    contextLine,
    elapsedSeconds,
    formatElapsed,
    newestFirst,
    trayHeading,
} from '@/lib/timer-state';
import { index as timeIndex } from '@/routes/time';
import type { ActiveTimer } from '@/types/time';

/**
 * EPIC-013 WP6 — the timer tray (Direction D §12.2): a **non-modal** Radix Popover anchored to the
 * pill, 408px, level 2 elevation.
 *
 * Non-modal is the deliberate choice, not the convenient one: a running timer is ambient state, so
 * opening the tray must not take the page hostage behind a scrim the way `dialog-shell` would. Radix
 * supplies the popover semantics this needs and WP6 could not get from the already-installed
 * primitives — `DropdownMenu` is a composite menu whose roving focus and typeahead would eat the
 * keystrokes meant for the inline description inputs below.
 *
 * Foundation contents only (§12.0). There is **no** Stop all (no endpoint exists, and fanning out
 * individual stops is explicitly not a substitute), no "Today N logged" (not in the active-timer
 * payload) and no "Start another…" search. Those parts of mockup D7 are NEXT.
 */

/** The tray's inline description editor: saves on Enter or blur, reverts on Escape (§12.2). */
function DescriptionField({ timer }: { timer: ActiveTimer }) {
    const { updateDescription, updating } = useTimers();
    // The server's value, and the only thing a revert reverts to.
    const committed = timer.description ?? '';
    const [value, setValue] = useState(committed);
    const [error, setError] = useState<string | null>(null);
    const processing = updating.includes(timer.id);

    const field = useRef<HTMLInputElement>(null);
    const synced = useRef(committed);

    // The server is authoritative: when a reconciliation brings a different description (edited in
    // another tab, or a failed save re-read), adopt it — but never while the user is mid-edit, which
    // is what the focus check protects. This is the RunningTimerBar draft-seeding lesson, kept.
    useEffect(() => {
        if (committed === synced.current) return;

        synced.current = committed;

        if (document.activeElement !== field.current) {
            setValue(committed);
        }
    }, [committed]);

    async function save() {
        const next = value.trim() || null;

        if (next === (committed || null)) {
            setError(null);

            return;
        }

        setError(null);

        try {
            await updateDescription(timer.id, next);
        } catch (reason) {
            // The draft is kept so the user's words are not thrown away by a failed request.
            setError(reason instanceof Error ? reason.message : 'Unable to save description.');
        }
    }

    function onSubmit(event: FormEvent) {
        event.preventDefault();
        field.current?.blur();
    }

    // Read by the content's `onEscapeKeyDown` veto above: only a field with something to lose gets
    // to swallow Escape. Derived from the prop, so nothing reads a ref during render.
    const hasDraft = value !== committed;

    return (
        <form onSubmit={onSubmit} className="min-w-0">
            <Input
                ref={field}
                data-draft={hasDraft ? 'true' : 'false'}
                value={value}
                maxLength={500}
                disabled={processing}
                aria-label={`Description for ${contextLine(timer) ?? 'this timer'}`}
                aria-invalid={error ? true : undefined}
                placeholder="Add a description"
                // `aria-invalid` already turns the edge `danger` (see `Input`), so the error needs no
                // second class here.
                className="h-8 w-full text-xs"
                onChange={(event) => setValue(event.target.value)}
                onBlur={() => void save()}
                onKeyDown={(event) => {
                    if (event.key === 'Escape') {
                        // Revert. Keeping the tray open is the content's `onEscapeKeyDown` veto, not
                        // this handler's: Radix's listener is on the document, out of reach here.
                        setValue(committed);
                        setError(null);
                    }
                }}
            />
            {error ? (
                <p role="alert" className="mt-1 text-xs text-danger">
                    {error}
                </p>
            ) : null}
        </form>
    );
}

function TrayRow({ timer, nowMs }: { timer: ActiveTimer; nowMs: number }) {
    const { stopTimer, stopping } = useTimers();
    const [error, setError] = useState<string | null>(null);
    const processing = stopping.includes(timer.id);
    const seconds = elapsedSeconds(timer.started_at, nowMs);
    const context = contextLine(timer);
    const label = context ?? timer.description ?? 'Untitled timer';

    return (
        <li className="border-b border-rule px-3 py-2.5 last:border-b-0">
            <div className="flex items-start gap-2">
                <span
                    data-timer-dot
                    className="mt-1.5 size-2 shrink-0 rounded-full bg-live"
                    aria-hidden="true"
                />

                <div className="min-w-0 flex-1">
                    {context ? (
                        <TimerContextLink
                            kind={timer.context!.type}
                            url={timer.context!.url}
                            label={timer.context!.label}
                            className="block truncate text-sm font-medium text-text hover:underline"
                        >
                            {context}
                        </TimerContextLink>
                    ) : (
                        <p className="truncate text-sm font-medium text-text">{label}</p>
                    )}

                    <div className="mt-1.5">
                        <DescriptionField timer={timer} />
                    </div>
                </div>

                <div className="flex shrink-0 items-center gap-1.5">
                    {/* Not in a live region: this changes every second (§13, Direction D §12.1). */}
                    <span className="font-mono text-sm text-live-text tabular-nums">
                        {seconds === null ? '—' : formatElapsed(seconds)}
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={processing}
                        aria-label={`Stop timer: ${label}`}
                        onClick={() => {
                            setError(null);
                            void stopTimer(timer.id).catch((reason: unknown) => {
                                setError(
                                    reason instanceof Error
                                        ? reason.message
                                        : 'Unable to stop timer.',
                                );
                            });
                        }}
                    >
                        <Square
                            className="size-3"
                            fill="currentColor"
                            strokeWidth={0}
                            aria-hidden="true"
                        />
                        {processing ? 'Stopping…' : 'Stop'}
                    </Button>
                </div>
            </div>

            {error ? (
                <p role="alert" className="mt-1.5 pl-4 text-xs text-danger">
                    {error}
                </p>
            ) : null}
        </li>
    );
}

export function TimerTray({ nowMs }: { nowMs: number }) {
    const { timers, status, error, refreshTimers } = useTimers();
    const rows = newestFirst(timers);
    const content = useRef<HTMLDivElement>(null);

    return (
        <Popover.Portal>
            <Popover.Content
                ref={content}
                data-shell-timer-tray
                align="end"
                sideOffset={6}
                collisionPadding={8}
                onOpenAutoFocus={(event) => {
                    // Radix would otherwise focus the first focusable child, which is a row's
                    // description field: opening the tray to press Stop would drop the caret into a
                    // text box, and a screen reader would announce an editable field before the list
                    // it belongs to. Focus the tray itself so Tab walks the rows in order instead.
                    event.preventDefault();
                    content.current?.focus();
                }}
                onEscapeKeyDown={(event) => {
                    // Escape inside a description field that holds an UNSAVED DRAFT reverts that
                    // draft instead of closing the tray, so one keystroke cannot silently discard
                    // typing. With no draft pending, Escape means what it means everywhere else and
                    // the tray closes. Radix listens for Escape on the document, so a synthetic
                    // `stopPropagation` in the field never reaches it — the veto has to happen here.
                    if (document.activeElement?.getAttribute('data-draft') === 'true') {
                        event.preventDefault();
                    }
                }}
                // 408px sits inside the 400–420px band (§12.2); `max-w` keeps it on a 390px screen,
                // where CSS reshapes it into a near-full-width sheet.
                className="z-50 w-[408px] max-w-[calc(100vw-16px)] rounded-overlay bg-surface text-text shadow-overlay data-[state=closed]:animate-menu-out data-[state=open]:animate-menu-in"
                aria-label="Running timers"
            >
                <div className="flex items-center justify-between border-b border-rule px-3 py-2">
                    <p className="text-xs font-semibold text-text-muted uppercase">
                        {trayHeading(timers.length)}
                    </p>
                    {status === 'error' ? (
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            onClick={() => void refreshTimers()}
                        >
                            <RefreshCw className="size-3.5" aria-hidden="true" />
                            Retry
                        </Button>
                    ) : null}
                </div>

                {status === 'error' && timers.length === 0 ? (
                    <p role="alert" className="px-3 py-4 text-sm text-danger">
                        {error ?? 'Active timers are temporarily unavailable.'}
                    </p>
                ) : timers.length === 0 ? (
                    <div className="px-3 py-4">
                        <p className="text-sm text-text">No timers running.</p>
                        <p className="mt-1 text-xs text-text-muted">
                            Start one from a task or ticket, or from the Time screen.
                        </p>
                    </div>
                ) : (
                    <ul className="max-h-[60vh] overflow-y-auto overscroll-contain">
                        {rows.map((timer) => (
                            <TrayRow key={timer.id} timer={timer} nowMs={nowMs} />
                        ))}
                    </ul>
                )}

                {/* The zero state links to the existing start flow rather than embedding a second
                    one: "Start another…" search is NEXT (§12.0). */}
                <div className="border-t border-rule px-3 py-2">
                    <Link
                        href={timeIndex().url}
                        className="rounded-control text-sm font-medium text-text-secondary hover:text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    >
                        Open Time →
                    </Link>
                </div>
            </Popover.Content>
        </Popover.Portal>
    );
}
