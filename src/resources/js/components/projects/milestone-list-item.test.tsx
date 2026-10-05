import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { MilestoneListItem } from '@/components/projects/milestone-list-item';
import {
    inertiaSpies,
    resetInertiaMock,
    setFormErrors,
    setFormProcessing,
    submitted,
} from '@/test/inertia';
import type { MilestoneItem } from '@/types/projects';

/**
 * EPIC-015 WP4 — one milestone row on the Milestones page. FLIPPED IN EPIC-015 WP4: this file pinned
 * `MilestoneCard`, which read linked-task progress as "50% complete" on a progress bar named
 * "{milestone} completion". The row now keeps explicit completion and linked-task progress apart
 * (Q2, the carried WP1 finding) and adds Complete/Reopen. The delete and edit cases are carried over.
 */

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

const milestone = (overrides: Partial<MilestoneItem> = {}): MilestoneItem => ({
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
    ...overrides,
});

function renderRow(overrides: Partial<MilestoneItem> = {}, canManage = true, onEdit = vi.fn()) {
    return render(
        <ul>
            <MilestoneListItem
                milestone={milestone(overrides)}
                projectId={7}
                canManage={canManage}
                onEdit={onEdit}
            />
        </ul>,
    );
}

/** The options of the last `router.put`, so a test can play the server's answer. */
function lastPut() {
    const call = inertiaSpies.router.put.mock.calls.at(-1)!;

    return {
        url: call[0] as string,
        options: call[2] as {
            onError?: (errors: Record<string, string>) => void;
            onFinish?: () => void;
            preserveScroll?: boolean;
            preserveState?: boolean;
        },
    };
}

it('renders the name, description, due date and an open completion state', () => {
    renderRow();

    const item = screen.getByRole('listitem', { name: 'Beta launch' });
    expect(
        within(item).getByRole('heading', { level: 3, name: 'Beta launch' }),
    ).toBeInTheDocument();
    expect(within(item).getByText('Ship the beta build.')).toBeInTheDocument();
    expect(within(item).getByText('Not completed')).toBeInTheDocument();
    expect(within(item).getByText(/Due/)).toHaveTextContent('Due Jun 30, 2026');
    expect(item).toHaveAttribute('data-milestone-state', 'open');
});

it('words linked-task progress as task progress, never as milestone completion', () => {
    renderRow();

    const item = screen.getByRole('listitem', { name: 'Beta launch' });
    expect(within(item).getByText('2 of 4 linked tasks done')).toBeInTheDocument();
    expect(within(item).queryByText(/% complete/)).not.toBeInTheDocument();
    const bar = within(item).getByRole('progressbar', {
        name: 'Beta launch: linked task progress',
    });
    expect(bar).toHaveAttribute('aria-valuenow', '2');
    expect(bar).toHaveAttribute('aria-valuemax', '4');
    expect(bar).toHaveAttribute('aria-valuetext', '2 of 4 linked tasks done');
});

it('keeps a milestone with every linked task done but not completed explicitly open (and overdue when late)', () => {
    renderRow({ doneCount: 4, openTaskCount: 0, completion: 100, overdue: true });

    const item = screen.getByRole('listitem', { name: 'Beta launch' });
    expect(within(item).getByText('4 of 4 linked tasks done')).toBeInTheDocument();
    expect(within(item).getByText('Overdue')).toBeInTheDocument();
    expect(within(item).queryByText('Completed')).not.toBeInTheDocument();
    expect(within(item).queryByText(/100%/)).not.toBeInTheDocument();
    expect(item).toHaveAttribute('data-milestone-state', 'overdue');
});

it('says when and by whom a milestone was completed, and that a zero-task one has no linked tasks', () => {
    renderRow({
        taskCount: 0,
        doneCount: 0,
        openTaskCount: 0,
        completion: 0,
        completedAt: '2026-06-28T15:04:00+00:00',
        completedBy: { id: 3, name: 'Mona Manager' },
    });

    const item = screen.getByRole('listitem', { name: 'Beta launch' });
    expect(item).toHaveAttribute('data-milestone-state', 'completed');
    expect(within(item).getByText('Completed')).toBeInTheDocument();
    expect(item).toHaveTextContent('Completed Jun 28, 2026 by Mona Manager');
    expect(within(item).getByText('No linked tasks')).toBeInTheDocument();
    expect(within(item).queryByRole('progressbar')).not.toBeInTheDocument();
});

it('offers no Complete, Reopen, Edit or Delete to a viewer who cannot manage', () => {
    renderRow({}, false);

    expect(screen.queryByRole('button')).not.toBeInTheDocument();
});

it('completes through projects.milestones.complete and leaves the state to the server', async () => {
    const user = userEvent.setup();
    renderRow();

    await user.click(screen.getByRole('button', { name: 'Complete Beta launch' }));

    const { url, options } = lastPut();
    expect(url).toBe('/projects/7/milestones/9/complete');
    expect(options.preserveScroll).toBe(true);
    expect(options.preserveState).toBe(true);
    // Not optimistic: nothing changes until the server answers with new props.
    expect(screen.getByText('Not completed')).toBeInTheDocument();
});

it('reopens a completed milestone through projects.milestones.reopen', async () => {
    const user = userEvent.setup();
    renderRow({ completedAt: '2026-06-28T15:04:00+00:00' });

    expect(screen.queryByRole('button', { name: /^Complete / })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Reopen Beta launch' }));

    expect(lastPut().url).toBe('/projects/7/milestones/9/reopen');
});

it('is busy and refuses a second press while the request is in flight, then is usable again', async () => {
    const user = userEvent.setup();
    renderRow();
    const button = screen.getByRole('button', { name: 'Complete Beta launch' });

    await user.click(button);
    // aria-disabled, never `disabled`: a disabled button would drop keyboard focus to the document.
    expect(button).toHaveAttribute('aria-disabled', 'true');
    expect(button).toHaveAttribute('aria-busy', 'true');
    expect(button).not.toBeDisabled();
    expect(button).toHaveFocus();
    await user.click(button);
    expect(inertiaSpies.router.put).toHaveBeenCalledTimes(1);

    act(() => lastPut().options.onFinish?.());
    expect(button).not.toHaveAttribute('aria-disabled');
    expect(button).not.toHaveAttribute('aria-busy');
});

it('dispatches one request when activated twice in the same tick, before any re-render', () => {
    renderRow();
    const button = screen.getByRole('button', { name: 'Complete Beta launch' });

    // Both activations run inside one act(), so React state has not updated between them: only the
    // synchronous in-flight ref can stop the second.
    act(() => {
        button.click();
        button.click();
    });

    expect(inertiaSpies.router.put).toHaveBeenCalledTimes(1);

    act(() => lastPut().options.onFinish?.());
    act(() => {
        button.click();
    });
    expect(inertiaSpies.router.put).toHaveBeenCalledTimes(2);
});

it('shows a refusal accessibly, naming the milestone, and clears it on the next attempt', async () => {
    const user = userEvent.setup();
    renderRow();

    await user.click(screen.getByRole('button', { name: 'Complete Beta launch' }));
    act(() => {
        lastPut().options.onError?.({ milestone: 'This milestone cannot be changed.' });
        lastPut().options.onFinish?.();
    });

    expect(screen.getByRole('alert')).toHaveTextContent(
        'Could not change “Beta launch”: This milestone cannot be changed.',
    );

    await user.click(screen.getByRole('button', { name: 'Complete Beta launch' }));
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

it('calls onEdit when the edit control is used', async () => {
    const user = userEvent.setup();
    const onEdit = vi.fn();
    renderRow({}, true, onEdit);

    await user.click(screen.getByRole('button', { name: 'Edit Beta launch' }));

    expect(onEdit).toHaveBeenCalledTimes(1);
});

it('asks for confirmation before deleting, states tasks are kept, and does nothing until confirmed', async () => {
    const user = userEvent.setup();
    renderRow();

    await user.click(screen.getByRole('button', { name: 'Delete Beta launch' }));

    const dialog = screen.getByRole('dialog', { name: 'Delete Beta launch?' });
    expect(dialog).toHaveAccessibleDescription(
        /Tasks on this milestone stay, but lose their milestone/,
    );
    expect(submitted()).toHaveLength(0);

    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(submitted()).toHaveLength(0);
});

it('deletes through projects.milestones.destroy without removing the row locally', async () => {
    const user = userEvent.setup();
    renderRow();

    await user.click(screen.getByRole('button', { name: 'Delete Beta launch' }));
    await user.click(
        within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete milestone' }),
    );

    expect(submitted()).toEqual([
        expect.objectContaining({
            method: 'delete',
            url: '/projects/7/milestones/9',
            options: expect.objectContaining({ errorBag: 'deleteMilestone' }),
        }),
    ]);
    // Not optimistic: the row stays until the server answers (the open dialog hides the rest of
    // the page from the accessibility tree, so this reads the text directly).
    expect(screen.getAllByText('Beta launch').length).toBeGreaterThan(0);
});

it('shows a refusal inside the delete dialog and clears it when the dialog is dismissed', async () => {
    const user = userEvent.setup();
    setFormErrors({ delete: 'Something blocked this delete.' });
    renderRow();

    await user.click(screen.getByRole('button', { name: 'Delete Beta launch' }));
    expect(within(screen.getByRole('dialog')).getByRole('alert')).toHaveTextContent(
        'Something blocked this delete.',
    );

    await user.keyboard('{Escape}');
    expect(inertiaSpies.form.clearErrors).toHaveBeenCalled();

    await user.click(screen.getByRole('button', { name: 'Delete Beta launch' }));
    expect(within(screen.getByRole('dialog')).queryByRole('alert')).not.toBeInTheDocument();
});

it('disables the delete dialog actions while the request is in flight', async () => {
    const user = userEvent.setup();
    setFormProcessing(true);
    renderRow();

    await user.click(screen.getByRole('button', { name: 'Delete Beta launch' }));

    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByRole('button', { name: 'Cancel' })).toBeDisabled();
    expect(within(dialog).getByRole('button', { name: 'Working...' })).toBeDisabled();
});

it('renders a hostile milestone name and description only as text', () => {
    const hostile = '<img src=x onerror="window.__xss=1">';
    renderRow({ name: hostile, description: hostile });

    expect(document.querySelector('img')).toBeNull();
    expect(screen.getAllByText(hostile).length).toBeGreaterThan(0);
    expect((window as unknown as { __xss?: number }).__xss).toBeUndefined();
});
