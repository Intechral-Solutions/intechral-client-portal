import { Head, Link } from '@inertiajs/react';
import { CalendarClock, Plus } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';

import { PageHeader } from '@/components/page-header';
import { MilestoneCard } from '@/components/projects/milestone-card';
import { MilestoneFormDialog } from '@/components/projects/milestone-form-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import { AppLayout } from '@/layouts/app-layout';
import { board } from '@/routes/projects';
import type { MilestoneItem } from '@/types/projects';

export type MilestonesIndexProps = {
    project: { id: number; name: string };
    milestones: MilestoneItem[];
    abilities: { manage: boolean };
};

type DialogState = { mode: 'create' } | { mode: 'edit'; milestone: MilestoneItem } | null;

export function MilestonesIndexPage({ project, milestones, abilities }: MilestonesIndexProps) {
    const [dialog, setDialog] = useState<DialogState>(null);

    return (
        <>
            <Head title={`${project.name} — Milestones`} />
            <div className="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
                <p className="mb-2 text-sm text-muted-foreground">
                    {/* The board is a React page as of WP5 (EPIC-011E §21): an Inertia Link. */}
                    <Link href={board.url(project.id)} className="hover:underline">
                        {project.name}
                    </Link>{' '}
                    / Milestones
                </p>
                <PageHeader
                    title="Milestones"
                    description="Group tasks under a target date to track progress toward it."
                    actions={
                        abilities.manage ? (
                            <Button type="button" onClick={() => setDialog({ mode: 'create' })}>
                                <Plus aria-hidden="true" />
                                New milestone
                            </Button>
                        ) : undefined
                    }
                />

                {milestones.length === 0 ? (
                    <div className="mt-8 flex flex-col items-center gap-3 rounded-md border border-dashed border-border px-4 py-14 text-center">
                        <CalendarClock
                            className="h-10 w-10 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p className="text-sm font-medium text-muted-foreground">
                            No milestones yet.
                        </p>
                        {abilities.manage ? (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setDialog({ mode: 'create' })}
                            >
                                Create the first milestone
                            </Button>
                        ) : null}
                    </div>
                ) : (
                    <section aria-label="Milestones" className="mt-8 space-y-4">
                        {milestones.map((milestone) => (
                            <MilestoneCard
                                key={milestone.id}
                                milestone={milestone}
                                projectId={project.id}
                                canManage={abilities.manage}
                                onEdit={() => setDialog({ mode: 'edit', milestone })}
                            />
                        ))}
                    </section>
                )}

                <div className="mt-8">
                    <Link
                        href={board.url(project.id)}
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        Back to board
                    </Link>
                </div>
            </div>

            {abilities.manage ? (
                <MilestoneFormDialog
                    // Always mounted (never conditionally torn down on close): Radix's own
                    // close lifecycle, and the WP2 focus-return fix built on it, needs one
                    // stable Dialog.Root to run against. It resyncs its fields from `milestone`
                    // whenever it opens, so switching targets never carries a stale draft.
                    open={dialog !== null}
                    onOpenChange={(open) => {
                        if (!open) setDialog(null);
                    }}
                    projectId={project.id}
                    milestone={dialog?.mode === 'edit' ? dialog.milestone : undefined}
                />
            ) : null}
        </>
    );
}

MilestonesIndexPage.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;

export default MilestonesIndexPage;
