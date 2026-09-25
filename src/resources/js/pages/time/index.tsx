import { Head, Link, router, useForm } from '@inertiajs/react';
import { Clock3, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactElement } from 'react';

import { ContextSelector, type SelectedContext } from '@/components/time/context-selector';
import { TimerContextLink } from '@/components/time/timer-context-link';
import { useTimers } from '@/components/time/timer-provider';
import { FormFieldError } from '@/components/forms/form-field-error';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmationDialog } from '@/components/ui/confirmation-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Status } from '@/components/ui/status';
import { AppLayout } from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import {
    allocation,
    destroy as destroyEntry,
    index as timeIndex,
    store,
    update,
} from '@/routes/time';
import type { Paginated } from '@/types/pagination';
import type { TimeEntryData } from '@/types/time';

type ProjectOption = { id: number; name: string };

type Filters = {
    project_id: string | null;
    ticket_id: string | null;
    from: string | null;
    to: string | null;
};

type PageProps = {
    entries: Paginated<TimeEntryData>;
    projects: ProjectOption[];
    filters: Filters;
    totalMinutes: number;
};

type EntryFormData = {
    date: string;
    hours: string;
    project_id: number | null;
    task_id: number | null;
    ticket_id: number | null;
    description: string;
    billable: boolean;
};

function contextFromEntry(entry?: TimeEntryData): SelectedContext {
    return entry?.context
        ? { kind: entry.context.kind, id: entry.context.id, label: entry.context.label }
        : null;
}

function setFormContext(
    setData: <Key extends keyof EntryFormData>(key: Key, value: EntryFormData[Key]) => void,
    context: SelectedContext,
) {
    setData('project_id', context?.kind === 'project' ? context.id : null);
    setData('task_id', context?.kind === 'task' ? context.id : null);
    setData('ticket_id', context?.kind === 'ticket' ? context.id : null);
}

function formatDuration(totalMinutes: number) {
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return hours ? `${hours}h ${minutes ? `${minutes}m` : ''}`.trim() : `${minutes}m`;
}

function TimerStartForm() {
    const { startTimer, starting } = useTimers();
    const [context, setContext] = useState<SelectedContext>(null);
    const [description, setDescription] = useState('');
    const [billable, setBillable] = useState(true);
    const [error, setError] = useState<string | null>(null);

    async function submit(event: FormEvent) {
        event.preventDefault();
        setError(null);

        try {
            await startTimer({
                project_id: context?.kind === 'project' ? context.id : null,
                task_id: context?.kind === 'task' ? context.id : null,
                ticket_id: context?.kind === 'ticket' ? context.id : null,
                description: description.trim() || null,
                billable,
            });
            setContext(null);
            setDescription('');
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : 'Unable to start timer.');
        }
    }

    return (
        <form onSubmit={submit} className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <ContextSelector
                idPrefix="timer"
                value={context}
                onChange={setContext}
                disabled={starting}
            />
            <div className="space-y-2 md:col-span-2">
                <Label htmlFor="timer-description">Description</Label>
                <Input
                    id="timer-description"
                    value={description}
                    maxLength={500}
                    placeholder="What are you working on?"
                    disabled={starting}
                    onChange={(event) => setDescription(event.target.value)}
                />
            </div>
            <div className="flex items-end gap-4 md:col-span-2 xl:col-span-4">
                <label className="flex min-h-9 items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={billable}
                        disabled={starting}
                        onChange={(event) => setBillable(event.target.checked)}
                    />
                    Billable
                </label>
                <Button type="submit" disabled={starting}>
                    <Clock3 aria-hidden="true" />
                    {starting ? 'Starting...' : 'Start timer'}
                </Button>
            </div>
            {error ? (
                <p className="text-sm text-destructive md:col-span-2 xl:col-span-4" role="alert">
                    {error}
                </p>
            ) : null}
        </form>
    );
}

function EntryForm({ entry, onSaved }: { entry?: TimeEntryData; onSaved?: () => void }) {
    const form = useForm<EntryFormData>({
        date: entry?.date ?? new Date().toISOString().slice(0, 10),
        hours: entry ? String(entry.hours) : '',
        project_id: entry?.context?.kind === 'project' ? entry.context.id : null,
        task_id: entry?.context?.kind === 'task' ? entry.context.id : null,
        ticket_id: entry?.context?.kind === 'ticket' ? entry.context.id : null,
        description: entry?.description ?? '',
        billable: entry?.billable ?? true,
    });
    const [context, setContext] = useState<SelectedContext>(contextFromEntry(entry));
    const prefix = entry ? `edit-${entry.id}` : 'create';
    const contextError = form.errors.project_id ?? form.errors.task_id ?? form.errors.ticket_id;

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            errorBag: entry ? `updateTimeEntry${entry.id}` : 'createTimeEntry',
            onError: (errors: Record<string, string>) => {
                const first = [
                    'date',
                    'hours',
                    'project_id',
                    'task_id',
                    'ticket_id',
                    'description',
                ].find((field) => errors[field]);
                const target =
                    first && ['project_id', 'task_id', 'ticket_id'].includes(first)
                        ? `${prefix}-context-record`
                        : first
                          ? `${prefix}-${first}`
                          : null;

                if (target) document.getElementById(target)?.focus();
            },
            onSuccess: () => {
                if (!entry) {
                    form.reset();
                    setContext(null);
                }
                onSaved?.();
            },
        };

        if (entry) {
            form.put(update.url(entry.id), options);
        } else {
            form.post(store.url(), options);
        }
    }

    return (
        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div className="space-y-2">
                <Label htmlFor={`${prefix}-date`}>Date</Label>
                <Input
                    id={`${prefix}-date`}
                    type="date"
                    required
                    max={new Date().toISOString().slice(0, 10)}
                    value={form.data.date}
                    onChange={(event) => form.setData('date', event.target.value)}
                    aria-invalid={Boolean(form.errors.date)}
                    aria-describedby={form.errors.date ? `${prefix}-date-error` : undefined}
                />
                <FormFieldError id={`${prefix}-date-error`} message={form.errors.date} />
            </div>
            <div className="space-y-2">
                <Label htmlFor={`${prefix}-hours`}>Hours</Label>
                <Input
                    id={`${prefix}-hours`}
                    type="number"
                    required
                    // Manual entry keeps its 15-minute floor and quarter-hour step. An existing
                    // entry may hold any whole-minute timer duration (7 min -> 0.12h, 50 min ->
                    // 0.83h), so editing only requires at least one stored minute (0.01h -> 1).
                    min={entry ? '0.01' : '0.25'}
                    max="24"
                    step={entry ? 'any' : '0.25'}
                    value={form.data.hours}
                    onChange={(event) => form.setData('hours', event.target.value)}
                    aria-invalid={Boolean(form.errors.hours)}
                    aria-describedby={form.errors.hours ? `${prefix}-hours-error` : undefined}
                />
                <FormFieldError id={`${prefix}-hours-error`} message={form.errors.hours} />
            </div>
            <ContextSelector
                idPrefix={prefix}
                value={context}
                error={contextError}
                disabled={form.processing}
                onChange={(next) => {
                    setContext(next);
                    setFormContext(form.setData, next);
                }}
            />
            <div className="space-y-2 sm:col-span-2 lg:col-span-4">
                <Label htmlFor={`${prefix}-description`}>Description</Label>
                <Input
                    id={`${prefix}-description`}
                    value={form.data.description}
                    maxLength={500}
                    onChange={(event) => form.setData('description', event.target.value)}
                    aria-invalid={Boolean(form.errors.description)}
                    aria-describedby={
                        form.errors.description ? `${prefix}-description-error` : undefined
                    }
                />
                <FormFieldError
                    id={`${prefix}-description-error`}
                    message={form.errors.description}
                />
            </div>
            <div className="flex items-center gap-4 sm:col-span-2 lg:col-span-4">
                <label className="flex min-h-9 items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={form.data.billable}
                        onChange={(event) => form.setData('billable', event.target.checked)}
                    />
                    Billable
                </label>
                <Button type="submit" disabled={form.processing}>
                    <Plus aria-hidden="true" />
                    {form.processing ? 'Saving...' : entry ? 'Save entry' : 'Log time'}
                </Button>
            </div>
            <FormFieldError
                id={`${prefix}-lock-error`}
                message={(form.errors as Record<string, string>).time_entry}
            />
        </form>
    );
}

export function EntryActions({ entry }: { entry: TimeEntryData }) {
    const [editing, setEditing] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (entry.locked) return <Badge>Locked</Badge>;
    if (entry.running) return <Status tone="live">Running</Status>;

    return (
        <div className="flex flex-col items-end gap-2">
            <div className="flex gap-1">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={`Edit entry from ${entry.date}`}
                    onClick={() => setEditing((value) => !value)}
                >
                    <Pencil aria-hidden="true" />
                </Button>
                <ConfirmationDialog
                    open={confirming}
                    onOpenChange={setConfirming}
                    title="Delete time entry?"
                    description="This removes the entry permanently."
                    confirmLabel="Delete entry"
                    processing={deleting}
                    onConfirm={() => {
                        setDeleting(true);
                        setError(null);
                        router.delete(destroyEntry.url(entry.id), {
                            preserveScroll: true,
                            onSuccess: () => setConfirming(false),
                            onError: (errors) =>
                                setError(errors.time_entry ?? 'Unable to delete entry.'),
                            onFinish: () => setDeleting(false),
                        });
                    }}
                >
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={`Delete entry from ${entry.date}`}
                    >
                        <Trash2 aria-hidden="true" />
                    </Button>
                </ConfirmationDialog>
            </div>
            {error ? (
                <span className="text-xs text-destructive" role="alert">
                    {error}
                </span>
            ) : null}
            {editing ? (
                <div className="w-[min(48rem,calc(100vw-3rem))] rounded-md border border-border bg-muted p-4 text-left">
                    <EntryForm entry={entry} onSaved={() => setEditing(false)} />
                </div>
            ) : null}
        </div>
    );
}

export function TimePage({ entries, projects, filters, totalMinutes }: PageProps) {
    const [projectId, setProjectId] = useState(filters.project_id ?? '');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    function filter(event: FormEvent) {
        event.preventDefault();
        router.get(
            timeIndex.url(),
            {
                project_id: projectId || undefined,
                // No control edits ticket_id (it was backend-only in Blade too), but an
                // active one must survive Apply instead of being silently dropped.
                ticket_id: filters.ticket_id || undefined,
                from: from || undefined,
                to: to || undefined,
            },
            { preserveState: true, replace: true },
        );
    }

    return (
        <>
            <Head title="My Time" />
            <div className="mx-auto max-w-7xl space-y-7 px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader title="My Time" description="Log and review your tracked time." />

                <nav className="flex gap-1 border-b border-border" aria-label="Time views">
                    <Link
                        href={timeIndex.url()}
                        className="border-b-2 border-ink px-3 py-2 text-sm font-medium text-text"
                    >
                        Entries
                    </Link>
                    <Link
                        href={allocation.url()}
                        className="border-b-2 border-transparent px-3 py-2 text-sm text-muted-foreground"
                    >
                        Allocation
                    </Link>
                </nav>

                <section className="space-y-4 border-b border-border pb-6">
                    <h2 className="text-base font-semibold">Start a timer</h2>
                    <TimerStartForm />
                </section>

                <section className="space-y-4 border-b border-border pb-6">
                    <h2 className="text-base font-semibold">Log time manually</h2>
                    <EntryForm />
                </section>

                <section className="space-y-4">
                    <div className="flex flex-wrap items-end justify-between gap-4">
                        <form onSubmit={filter} className="flex flex-wrap items-end gap-3">
                            <div className="space-y-1">
                                <Label htmlFor="filter-project">Project</Label>
                                <NativeSelect
                                    id="filter-project"
                                    value={projectId}
                                    onChange={(event) => setProjectId(event.target.value)}
                                >
                                    <option value="">All projects</option>
                                    {projects.map((project) => (
                                        <option key={project.id} value={project.id}>
                                            {project.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="filter-from">From</Label>
                                <Input
                                    id="filter-from"
                                    type="date"
                                    value={from}
                                    onChange={(event) => setFrom(event.target.value)}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="filter-to">To</Label>
                                <Input
                                    id="filter-to"
                                    type="date"
                                    value={to}
                                    onChange={(event) => setTo(event.target.value)}
                                />
                            </div>
                            <Button type="submit" variant="outline">
                                Apply filters
                            </Button>
                        </form>
                        <p className="text-sm text-muted-foreground">
                            Filtered total:{' '}
                            <strong className="text-foreground">
                                {formatDuration(totalMinutes)}
                            </strong>
                        </p>
                    </div>

                    {entries.data.length === 0 ? (
                        <Alert>No time entries match these filters.</Alert>
                    ) : (
                        <div className="overflow-x-auto border-y border-border">
                            <table className="w-full min-w-[46rem] text-sm">
                                <thead>
                                    <tr className="border-b border-border text-left text-xs text-muted-foreground uppercase">
                                        <th className="px-3 py-3">Date</th>
                                        <th className="px-3 py-3">Context</th>
                                        <th className="px-3 py-3">Description</th>
                                        <th className="px-3 py-3 text-right">Duration</th>
                                        <th className="px-3 py-3">Billing</th>
                                        <th className="px-3 py-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {entries.data.map((entry) => (
                                        <tr
                                            key={entry.id}
                                            className="border-b border-border align-top"
                                        >
                                            <td className="px-3 py-3 whitespace-nowrap">
                                                {formatDate(entry.date)}
                                            </td>
                                            <td className="px-3 py-3">
                                                {entry.context ? (
                                                    <TimerContextLink
                                                        kind={entry.context.kind}
                                                        url={entry.context.url}
                                                        label={entry.context.label}
                                                        className={
                                                            entry.context.url
                                                                ? 'legacy-text-primary hover:underline'
                                                                : undefined
                                                        }
                                                    />
                                                ) : (
                                                    'None'
                                                )}
                                            </td>
                                            <td className="max-w-80 px-3 py-3">
                                                {entry.description ?? 'None'}
                                            </td>
                                            <td className="px-3 py-3 text-right font-mono">
                                                {entry.running ? 'Running' : entry.durationHuman}
                                            </td>
                                            <td className="px-3 py-3">
                                                {entry.locked ? (
                                                    <Badge variant="neutral">Locked</Badge>
                                                ) : entry.billable ? (
                                                    <Badge variant="success">Billable</Badge>
                                                ) : (
                                                    <Badge>Non-billable</Badge>
                                                )}
                                            </td>
                                            <td className="px-3 py-3 text-right">
                                                <EntryActions entry={entry} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <Pagination paginator={entries} />
                </section>
            </div>
        </>
    );
}

TimePage.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;

export default TimePage;
