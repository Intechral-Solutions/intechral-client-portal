import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { MilestoneCard } from '@/components/projects/milestone-card';
import {
    inertiaSpies,
    resetInertiaMock,
    setFormErrors,
    setFormProcessing,
    submitted,
} from '@/test/inertia';
import type { MilestoneItem } from '@/types/projects';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

const milestone = (overrides: Partial<MilestoneItem> = {}): MilestoneItem => ({
    id: 9,
    name: 'Beta launch',
    description: 'Ship the beta build.',
    dueDate: '2026-06-30',
    taskCount: 4,
    doneCount: 2,
    completion: 50,
    overdue: false,
    ...overrides,
});

function renderCard(overrides: Partial<MilestoneItem> = {}, canManage = true) {
    return render(
        <MilestoneCard
            milestone={milestone(overrides)}
            projectId={7}
            canManage={canManage}
            onEdit={vi.fn()}
        />,
    );
}

it('renders the name, description, due date, task count and progress', () => {
    renderCard();

    const article = screen.getByRole('article', { name: 'Beta launch' });
    expect(within(article).getByText('Ship the beta build.')).toBeInTheDocument();
    expect(within(article).getByText('Due Jun 30, 2026')).toBeInTheDocument();
    expect(within(article).getByText('4 tasks')).toBeInTheDocument();
    expect(within(article).getByText('50% complete')).toBeInTheDocument();
    expect(
        within(article).getByRole('progressbar', { name: 'Beta launch completion' }),
    ).toHaveAttribute('aria-valuenow', '50');
});

it('omits the description paragraph when there is none, and uses singular "task" for one', () => {
    renderCard({ description: null, taskCount: 1, doneCount: 0, completion: 0 });

    expect(screen.queryByText('Ship the beta build.')).not.toBeInTheDocument();
    expect(screen.getByText('1 task')).toBeInTheDocument();
});

it('marks an overdue due date distinctly from an on-time one', () => {
    const { unmount } = renderCard({ overdue: true });
    expect(screen.getByText('Due Jun 30, 2026')).toHaveClass('text-[var(--text-danger)]');
    unmount();

    renderCard({ overdue: false });
    expect(screen.getByText('Due Jun 30, 2026')).not.toHaveClass('text-[var(--text-danger)]');
});

it('offers no edit or delete control to a viewer who cannot manage', () => {
    renderCard({}, false);

    expect(screen.queryByRole('button', { name: /Edit/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Delete/ })).not.toBeInTheDocument();
});

it('calls onEdit when the edit control is used', async () => {
    const user = userEvent.setup();
    const onEdit = vi.fn();
    render(<MilestoneCard milestone={milestone()} projectId={7} canManage onEdit={onEdit} />);

    await user.click(screen.getByRole('button', { name: 'Edit Beta launch' }));

    expect(onEdit).toHaveBeenCalledTimes(1);
});

it('asks for confirmation before deleting, states tasks are kept, and does nothing until confirmed', async () => {
    const user = userEvent.setup();
    renderCard();

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

it('deletes through projects.milestones.destroy without removing the card locally', async () => {
    const user = userEvent.setup();
    renderCard();

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
    // Not optimistic: the card and its data are still on screen until the server answers.
    // (getByText, not getByRole('article'): the still-open confirmation dialog legitimately
    // marks the rest of the page aria-hidden while it has focus.)
    expect(screen.getByText('Beta launch')).toBeInTheDocument();
});

it('shows a refusal inside the delete dialog and clears it when the dialog is dismissed', async () => {
    const user = userEvent.setup();
    setFormErrors({ delete: 'Something blocked this delete.' });
    renderCard();

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
    renderCard();

    await user.click(screen.getByRole('button', { name: 'Delete Beta launch' }));

    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByRole('button', { name: 'Cancel' })).toBeDisabled();
    expect(within(dialog).getByRole('button', { name: 'Working...' })).toBeDisabled();
});

it('renders a hostile milestone name and description only as text', () => {
    const hostile = '<img src=x onerror="window.__xss=1">';
    renderCard({ name: hostile, description: hostile });

    expect(document.querySelector('img')).toBeNull();
    expect(screen.getAllByText(hostile).length).toBeGreaterThan(0);
    expect((window as unknown as { __xss?: number }).__xss).toBeUndefined();
});
