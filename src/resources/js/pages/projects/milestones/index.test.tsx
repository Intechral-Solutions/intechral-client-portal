import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { MilestonesIndexPage, type MilestonesIndexProps } from '@/pages/projects/milestones/index';
import { resetInertiaMock, setPageProps, submitted } from '@/test/inertia';
import type { MilestoneItem } from '@/types/projects';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

beforeEach(() => setPageProps({}));
afterEach(resetInertiaMock);

const milestone = (overrides: Partial<MilestoneItem> = {}): MilestoneItem => ({
    id: 1,
    name: 'Beta launch',
    description: null,
    dueDate: '2026-06-30',
    taskCount: 2,
    doneCount: 1,
    completion: 50,
    overdue: false,
    ...overrides,
});

const project = { id: 7, name: 'Portal rebuild' };

function renderPage(props: Partial<MilestonesIndexProps> = {}) {
    return render(
        <MilestonesIndexPage
            project={project}
            milestones={[]}
            abilities={{ manage: true }}
            {...props}
        />,
    );
}

it('shows an empty state with a create action for a manager, and none for a read-only viewer', () => {
    const { unmount } = renderPage({ abilities: { manage: true } });
    expect(screen.getByText('No milestones yet.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Create the first milestone' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'New milestone' })).toBeInTheDocument();
    unmount();

    renderPage({ abilities: { manage: false } });
    expect(screen.getByText('No milestones yet.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /milestone/i })).not.toBeInTheDocument();
});

it('renders one card per milestone with the project context and a link back to the board', () => {
    renderPage({
        milestones: [milestone(), milestone({ id: 2, name: 'GA release', completion: 100 })],
    });

    expect(screen.getByRole('article', { name: 'Beta launch' })).toBeInTheDocument();
    expect(screen.getByRole('article', { name: 'GA release' })).toBeInTheDocument();
    expect(screen.getAllByRole('link', { name: 'Portal rebuild' })[0]).toHaveAttribute(
        'href',
        '/projects/7/board',
    );
    expect(screen.getByRole('link', { name: 'Back to board' })).toHaveAttribute(
        'href',
        '/projects/7/board',
    );
});

it('hides edit and delete on every card for a read-only viewer', () => {
    renderPage({
        milestones: [milestone()],
        abilities: { manage: false },
    });

    expect(screen.queryByRole('button', { name: /Edit Beta launch/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Delete Beta launch/ })).not.toBeInTheDocument();
});

it('opens the create dialog from either the header button or the empty-state button', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(screen.getByRole('button', { name: 'Create the first milestone' }));
    expect(screen.getByRole('dialog', { name: 'New milestone' })).toBeInTheDocument();
    await user.keyboard('{Escape}');

    await user.click(screen.getByRole('button', { name: 'New milestone' }));
    expect(screen.getByRole('dialog', { name: 'New milestone' })).toBeInTheDocument();
});

it('opens the edit dialog prefilled for the row that was clicked, independent of other rows', async () => {
    const user = userEvent.setup();
    renderPage({
        milestones: [milestone(), milestone({ id: 2, name: 'GA release', dueDate: '2026-09-01' })],
    });

    await user.click(screen.getByRole('button', { name: 'Edit Beta launch' }));
    expect(screen.getByRole('dialog', { name: 'Edit milestone' })).toBeInTheDocument();
    expect(screen.getByLabelText(/Name/)).toHaveValue('Beta launch');
    await user.keyboard('{Escape}');

    await user.click(screen.getByRole('button', { name: 'Edit GA release' }));
    expect(screen.getByLabelText(/Name/)).toHaveValue('GA release');
    expect(screen.getByLabelText(/Due date/)).toHaveValue('2026-09-01');
});

it('creates a milestone through the dialog and closes it on success', async () => {
    const user = userEvent.setup();
    renderPage();
    const opener = screen.getByRole('button', { name: 'New milestone' });

    await user.click(opener);
    await user.type(screen.getByLabelText(/Name/), 'Launch week');
    await user.type(screen.getByLabelText(/Due date/), '2026-09-01');
    await user.click(screen.getByRole('button', { name: 'Create milestone' }));

    expect(submitted()).toEqual([
        expect.objectContaining({ method: 'post', url: '/projects/7/milestones' }),
    ]);

    // The dialog's own onSuccess closes it; simulate that success callback firing. It triggers a
    // state update outside any user-event interaction, so it needs its own act() to flush.
    //
    // Focus-return on this specific path (a close driven by a callback invoked outside React's
    // synthetic event system, exactly how Inertia's real onSuccess fires) is not reliably
    // observable under jsdom even against an unmodified, already-proven FormDialog: Radix's own
    // onCloseAutoFocus fires and calls .focus(), but document.activeElement does not update in
    // this environment. The dismiss-gesture path (Escape, Cancel) is unaffected and is covered
    // by MilestoneFormDialog's own "closes and returns focus" test; this exact path is covered
    // for real by the projects-migration-style Playwright flow (§24) in a real browser.
    act(() => submitted()[0]!.options.onSuccess?.());
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

it('deletes a milestone from its own card without disturbing the others', async () => {
    const user = userEvent.setup();
    renderPage({
        milestones: [milestone(), milestone({ id: 2, name: 'GA release' })],
    });

    await user.click(screen.getByRole('button', { name: 'Delete Beta launch' }));
    await user.click(
        within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete milestone' }),
    );

    expect(submitted()).toEqual([
        expect.objectContaining({ method: 'delete', url: '/projects/7/milestones/1' }),
    ]);
    // Not optimistic: both cards remain until the server confirms.
    // (getByText, not getByRole('article'): the still-open confirmation dialog legitimately
    // marks the rest of the page aria-hidden while it has focus.)
    expect(screen.getByText('Beta launch')).toBeInTheDocument();
    expect(screen.getByText('GA release')).toBeInTheDocument();
});

it('renders milestone names as text, never as markup', () => {
    renderPage({
        milestones: [milestone({ name: '<img src=x onerror=alert(1)>' })],
    });

    expect(document.querySelector('img')).toBeNull();
    expect(screen.getByText('<img src=x onerror=alert(1)>')).toBeInTheDocument();
});
