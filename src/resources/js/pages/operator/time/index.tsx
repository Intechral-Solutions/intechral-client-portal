import { Head, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactElement } from 'react';

import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Progress } from '@/components/ui/progress';
import { AppLayout } from '@/layouts/app-layout';
import { exportMethod, index } from '@/routes/operator/time';
import type { Paginated } from '@/types/pagination';

type ReportEntry = {
    id: number;
    date: string;
    userName: string;
    projectName: string | null;
    description: string | null;
    durationMinutes: number;
    billable: boolean;
    billed: boolean;
    locked: boolean;
};

type Summary = {
    id: number | null;
    name: string;
    totalMinutes: number;
    billableMinutes: number;
};

type Option = { id: number; name: string };

type Filters = {
    user_id: string | null;
    project_id: string | null;
    from: string | null;
    to: string | null;
    billable: string | null;
};

export type TimeReportPageProps = {
    entries: Paginated<ReportEntry>;
    byProject: Summary[];
    byUser: Summary[];
    totalMinutes: number;
    users: Option[];
    projects: Option[];
    filters: Filters;
};

function hours(minutes: number) {
    return `${(minutes / 60).toFixed(1)}h`;
}

export function ReportPage({
    entries,
    byProject,
    byUser,
    totalMinutes,
    users,
    projects,
    filters,
}: TimeReportPageProps) {
    const [userId, setUserId] = useState(filters.user_id ?? '');
    const [projectId, setProjectId] = useState(filters.project_id ?? '');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [billable, setBillable] = useState(filters.billable ?? '');

    // Export mirrors the server-confirmed filters the table is showing; the draft
    // controls below only take effect once Apply round-trips through Laravel.
    const appliedQuery = {
        user_id: filters.user_id || undefined,
        project_id: filters.project_id || undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
        billable: filters.billable || undefined,
    };

    function submit(event: FormEvent) {
        event.preventDefault();
        router.get(
            index.url(),
            {
                user_id: userId || undefined,
                project_id: projectId || undefined,
                from: from || undefined,
                to: to || undefined,
                billable: billable || undefined,
            },
            { preserveState: true, replace: true },
        );
    }

    return (
        <>
            <Head title="Time Reports" />
            <div className="mx-auto max-w-7xl space-y-7 px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader
                    title="Time Reports"
                    description="Review completed time across users and projects."
                    actions={
                        <a
                            href={exportMethod.url({ query: appliedQuery })}
                            className={buttonVariants({ variant: 'outline' })}
                        >
                            <Download aria-hidden="true" />
                            Export CSV
                        </a>
                    }
                />

                <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                    <div className="space-y-1">
                        <Label htmlFor="report-user">User</Label>
                        <NativeSelect
                            id="report-user"
                            value={userId}
                            onChange={(event) => setUserId(event.target.value)}
                        >
                            <option value="">All users</option>
                            {users.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="report-project">Project</Label>
                        <NativeSelect
                            id="report-project"
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
                        <Label htmlFor="report-from">From</Label>
                        <Input
                            id="report-from"
                            type="date"
                            value={from}
                            onChange={(event) => setFrom(event.target.value)}
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="report-to">To</Label>
                        <Input
                            id="report-to"
                            type="date"
                            value={to}
                            onChange={(event) => setTo(event.target.value)}
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="report-billable">Billing</Label>
                        <NativeSelect
                            id="report-billable"
                            value={billable}
                            onChange={(event) => setBillable(event.target.value)}
                        >
                            <option value="">All entries</option>
                            <option value="1">Billable only</option>
                            <option value="0">Non-billable only</option>
                        </NativeSelect>
                    </div>
                    <Button type="submit">Apply filters</Button>
                </form>

                <dl className="grid grid-cols-3 divide-x divide-border border-y border-border py-5 text-center">
                    <div>
                        <dt className="text-xs text-muted-foreground uppercase">Total hours</dt>
                        <dd className="mt-1 text-xl font-semibold">{hours(totalMinutes)}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground uppercase">Users</dt>
                        <dd className="mt-1 text-xl font-semibold">{byUser.length}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground uppercase">Projects</dt>
                        <dd className="mt-1 text-xl font-semibold">{byProject.length}</dd>
                    </div>
                </dl>

                <div className="grid gap-8 lg:grid-cols-2">
                    <SummaryList title="By project" rows={byProject} totalMinutes={totalMinutes} />
                    <SummaryList title="By user" rows={byUser} totalMinutes={totalMinutes} />
                </div>

                <section className="space-y-4" aria-labelledby="report-entries-heading">
                    <h2 id="report-entries-heading" className="text-base font-semibold">
                        Time entries
                    </h2>
                    {entries.data.length === 0 ? (
                        <Alert>No completed time entries match these filters.</Alert>
                    ) : (
                        <div className="overflow-x-auto border-y border-border">
                            <table className="w-full min-w-[48rem] text-sm">
                                <thead>
                                    <tr className="border-b border-border text-left text-xs text-muted-foreground uppercase">
                                        <th className="px-3 py-3">Date</th>
                                        <th className="px-3 py-3">User</th>
                                        <th className="px-3 py-3">Project</th>
                                        <th className="px-3 py-3">Description</th>
                                        <th className="px-3 py-3 text-right">Duration</th>
                                        <th className="px-3 py-3">Billing</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {entries.data.map((entry) => (
                                        <tr
                                            key={entry.id}
                                            className="border-b border-border align-top"
                                        >
                                            <td className="px-3 py-3 whitespace-nowrap">
                                                {entry.date}
                                            </td>
                                            <td className="px-3 py-3">{entry.userName}</td>
                                            <td className="px-3 py-3">
                                                {entry.projectName ?? 'None'}
                                            </td>
                                            <td className="max-w-80 px-3 py-3">
                                                {entry.description ?? 'None'}
                                            </td>
                                            <td className="px-3 py-3 text-right font-mono">
                                                {hours(entry.durationMinutes)}
                                            </td>
                                            <td className="px-3 py-3">
                                                {entry.billed ? (
                                                    <Badge variant="neutral">Billed</Badge>
                                                ) : entry.billable ? (
                                                    <Badge variant="success">Billable</Badge>
                                                ) : (
                                                    <Badge>Non-billable</Badge>
                                                )}
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

function SummaryList({
    title,
    rows,
    totalMinutes,
}: {
    title: string;
    rows: Summary[];
    totalMinutes: number;
}) {
    return (
        <section className="space-y-3">
            <h2 className="text-base font-semibold">{title}</h2>
            {rows.length ? (
                <dl className="divide-y divide-border border-y border-border">
                    {rows.map((row) => {
                        const percentage = totalMinutes
                            ? Math.round((row.totalMinutes / totalMinutes) * 100)
                            : 0;

                        return (
                            <div key={`${row.id ?? 'none'}-${row.name}`} className="py-3">
                                <div className="flex items-center justify-between gap-4 text-sm">
                                    <dt className="font-medium">{row.name}</dt>
                                    <dd className="text-muted-foreground">
                                        {hours(row.totalMinutes)} ({hours(row.billableMinutes)}{' '}
                                        billable)
                                    </dd>
                                </div>
                                <Progress
                                    className="mt-2"
                                    value={percentage}
                                    label={`${row.name} share of tracked time`}
                                    valueText={`${percentage}% of tracked time`}
                                />
                            </div>
                        );
                    })}
                </dl>
            ) : (
                <p className="text-sm text-muted-foreground">No matching data.</p>
            )}
        </section>
    );
}

ReportPage.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;

export default ReportPage;
