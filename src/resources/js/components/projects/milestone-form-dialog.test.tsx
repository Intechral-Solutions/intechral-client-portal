import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';

import { MilestoneFormDialog } from '@/components/projects/milestone-form-dialog';
import { Button } from '@/components/ui/button';
import { resetInertiaMock, setFormErrors, submitted } from '@/test/inertia';
import type { MilestoneItem } from '@/types/projects';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

const milestone: MilestoneItem = {
    id: 9,
    name: 'Beta launch',
    description: 'Ship the beta build.',
    dueDate: '2026-06-30',
    taskCount: 4,
    doneCount: 2,
    openTaskCount: 2,
    completion: 50,
    completedAt: null,
    completedBy: null,
    overdue: false,
};

type HarnessProps = { initialTarget?: MilestoneItem };

/** Mirrors the page: one always-mounted dialog, an opener per row, an opener for "create". */
function Harness({ initialTarget }: HarnessProps) {
    const [dialog, setDialog] = useState<{ milestone?: MilestoneItem } | null>(
        initialTarget ? { milestone: initialTarget } : null,
    );

    return (
        <>
            <Button type="button" onClick={() => setDialog({})}>
                New milestone
            </Button>
            <Button type="button" onClick={() => setDialog({ milestone })}>
                Edit Beta launch
            </Button>
            <Button
                type="button"
                onClick={() => setDialog({ milestone: { ...milestone, id: 10, name: 'GA' } })}
            >
                Edit GA
            </Button>
            <MilestoneFormDialog
                open={dialog !== null}
                onOpenChange={(open) => {
                    if (!open) setDialog(null);
                }}
                projectId={7}
                milestone={dialog?.milestone}
            />
        </>
    );
}

it('opens empty for create and submits to projects.milestones.store', async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.click(screen.getByRole('button', { name: 'New milestone' }));
    const dialog = screen.getByRole('dialog', { name: 'New milestone' });
    expect(screen.getByLabelText(/Name/)).toHaveValue('');
    expect(screen.getByLabelText(/Due date/)).toHaveValue('');
    expect(screen.getByLabelText('Description')).toHaveValue('');

    await user.type(screen.getByLabelText(/Name/), 'Launch week');
    await user.type(screen.getByLabelText(/Due date/), '2026-09-01');
    await user.click(within(dialog).getByRole('button', { name: 'Create milestone' }));

    expect(submitted()).toEqual([
        expect.objectContaining({
            method: 'post',
            url: '/projects/7/milestones',
            options: expect.objectContaining({ errorBag: 'createMilestone' }),
            data: { name: 'Launch week', description: '', due_date: '2026-09-01' },
        }),
    ]);
});

it('prefills from the target milestone when opened to edit, and submits to projects.milestones.update', async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.click(screen.getByRole('button', { name: 'Edit Beta launch' }));

    expect(screen.getByRole('dialog', { name: 'Edit milestone' })).toBeInTheDocument();
    expect(screen.getByLabelText(/Name/)).toHaveValue('Beta launch');
    expect(screen.getByLabelText(/Due date/)).toHaveValue('2026-06-30');
    expect(screen.getByLabelText('Description')).toHaveValue('Ship the beta build.');

    await user.click(screen.getByRole('button', { name: 'Save changes' }));

    expect(submitted()).toEqual([
        expect.objectContaining({
            method: 'put',
            url: '/projects/7/milestones/9',
            options: expect.objectContaining({ errorBag: 'updateMilestone' }),
            data: {
                name: 'Beta launch',
                description: 'Ship the beta build.',
                due_date: '2026-06-30',
            },
        }),
    ]);
});

it('resyncs to a different target between closes instead of keeping the previous draft', async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.click(screen.getByRole('button', { name: 'Edit Beta launch' }));
    await user.clear(screen.getByLabelText(/Name/));
    await user.type(screen.getByLabelText(/Name/), 'Unsaved edit');
    await user.keyboard('{Escape}');

    await user.click(screen.getByRole('button', { name: 'Edit GA' }));
    expect(screen.getByLabelText(/Name/)).toHaveValue('GA');

    await user.keyboard('{Escape}');
    await user.click(screen.getByRole('button', { name: 'New milestone' }));
    expect(screen.getByLabelText(/Name/)).toHaveValue('');
});

it('shows field errors in the dialog and keeps values after a failed submission', () => {
    // Real Inertia never remounts the page on a failed submission: the dialog stays open and
    // `form.errors` updates in place, so the accurate way to exercise this is a dialog that is
    // already open (not one opened fresh after the errors were set).
    setFormErrors({
        name: 'The name field is required.',
        due_date: 'The due date field is required.',
    });
    render(<Harness initialTarget={milestone} />);

    expect(screen.getByLabelText(/Name/)).toHaveAccessibleDescription(
        'The name field is required.',
    );
    expect(screen.getByLabelText(/Due date/)).toHaveAccessibleDescription(
        'The due date field is required.',
    );
    expect(screen.getByLabelText(/Name/)).toHaveValue('Beta launch');
});

it('closes and returns focus to whichever button opened it', async () => {
    const user = userEvent.setup();
    render(<Harness />);
    const opener = screen.getByRole('button', { name: 'Edit Beta launch' });

    await user.click(opener);
    await user.keyboard('{Escape}');

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(opener).toHaveFocus();
});

it('does not submit a form that happens to contain the dialog', async () => {
    const user = userEvent.setup();
    const outer = vi.fn((event: React.FormEvent) => event.preventDefault());
    render(
        <form onSubmit={outer}>
            <Harness />
        </form>,
    );

    await user.click(screen.getByRole('button', { name: 'New milestone' }));
    await user.type(screen.getByLabelText(/Name/), 'x');
    await user.type(screen.getByLabelText(/Due date/), '2026-01-01');
    await user.click(screen.getByRole('button', { name: 'Create milestone' }));

    expect(submitted()).toHaveLength(1);
    expect(outer).not.toHaveBeenCalled();
});
