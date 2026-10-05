import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { navigation, projects, shellUser } from '@/components/shell/shell-fixtures';
import { MilestonesIndexPage, type MilestonesIndexProps } from '@/pages/projects/milestones/index';
import { resetInertiaMock, setPageProps, submitted } from '@/test/inertia';
import type { MilestoneItem } from '@/types/projects';

/**
 * EPIC-015 WP4 — the Milestones tab (§11.1, §14.1). FLIPPED IN EPIC-015 WP4: the page drew its own
 * "{project} / Milestones" line, a "Milestones" PageHeader, cards and a "Back to project" button; it is
 * now inside the shared project header with the four tabs (Milestones current), the shell's trail, a
 * full StagePath and ruled milestone rows. The dialog, create and delete cases are carried over.
 */

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());
vi.mock('@/routes', () => ({ logout: { url: () => '/logout' } }));
vi.mock('@/routes/profile', () => ({ show: { url: () => '/profile' } }));
vi.mock('@/components/time/timer-pill', () => ({
    TimerPill: () => <span data-testid="timer-pill" />,
}));

beforeEach(() => setPageProps({}));
afterEach(resetInertiaMock);

const milestone = (overrides: Partial<MilestoneItem> = {}): MilestoneItem => ({
    id: 1,
    name: 'Beta launch',
    description: null,
    dueDate: '2026-06-30',
    taskCount: 2,
    doneCount: 1,
    openTaskCount: 1,
    completion: 50,
    completedAt: null,
    completedBy: null,
    overdue: false,
    ...overrides,
});

const project = { id: 7, name: 'Portal rebuild', status: 'active' as const };

function renderPage(props: Partial<MilestonesIndexProps> = {}) {
    return render(
        <MilestonesIndexPage
            project={project}
            milestones={[]}
            currentId={null}
            abilities={{ manage: true }}
            {...props}
        />,
    );
}

const three = [
    milestone({
        id: 1,
        name: 'Kickoff',
        dueDate: '2026-05-01',
        completedAt: '2026-05-01T10:00:00+00:00',
        completedBy: { id: 4, name: 'Mona Manager' },
    }),
    // Every linked task done, never completed, and late: still current, still overdue.
    milestone({
        id: 2,
        name: 'Design sign-off',
        dueDate: '2026-06-01',
        taskCount: 3,
        doneCount: 3,
        openTaskCount: 0,
        completion: 100,
        overdue: true,
    }),
    milestone({
        id: 3,
        name: 'Go-live',
        dueDate: '2026-09-01',
        taskCount: 0,
        doneCount: 0,
        openTaskCount: 0,
        completion: 0,
    }),
];

it('sits in the shared project header: one h1, lifecycle, the four tabs with Milestones current', () => {
    renderPage({ milestones: three, currentId: 2 });

    expect(screen.getAllByRole('heading', { level: 1 }).map((h) => h.textContent)).toEqual([
        'Portal rebuild',
    ]);
    expect(screen.getByText('Active')).toBeInTheDocument();
    const nav = screen.getByRole('navigation', { name: 'Project' });
    expect(
        within(nav)
            .getAllByRole('link')
            .map((link) => link.textContent),
    ).toEqual(['Overview', 'Board', 'Tasks', 'Milestones']);
    expect(within(nav).getByRole('link', { name: 'Milestones' })).toHaveAttribute(
        'aria-current',
        'page',
    );
    expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 2, name: 'Milestones' })).toBeInTheDocument();
    // No page-owned trail or back button: the shell's breadcrumb and the tabs replace them.
    expect(screen.queryByRole('navigation', { name: 'Breadcrumb' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Back to project' })).not.toBeInTheDocument();
});

it("offers Settings and every mutation only with the server's manage ability", () => {
    const { unmount } = renderPage({ milestones: three, currentId: 2 });
    expect(screen.getByRole('link', { name: 'Settings' })).toHaveAttribute(
        'href',
        '/projects/7/edit',
    );
    expect(screen.getByRole('button', { name: 'New milestone' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Complete Design sign-off' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Reopen Kickoff' })).toBeInTheDocument();
    unmount();

    renderPage({ milestones: three, currentId: 2, abilities: { manage: false } });
    expect(screen.queryByRole('link', { name: 'Settings' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

it("draws the full StagePath from explicit completion and the server's current milestone", () => {
    renderPage({ milestones: three, currentId: 2 });

    const path = screen.getByRole('list', { name: 'Milestones: 1 of 3 complete' });
    const stages = within(path).getAllByRole('listitem');
    expect(stages.map((stage) => stage.textContent)).toEqual([
        expect.stringMatching(/Kickoff.*Done/),
        expect.stringMatching(/Design sign-off.*Current.*Overdue/),
        expect.stringMatching(/Go-live.*Planned/),
    ]);
    // All three of its linked tasks are done, but nobody completed it: current, not done.
    expect(stages[1]).toHaveAttribute('aria-current', 'step');
});

it('summarizes explicit completion and overdue milestones, never task progress', () => {
    renderPage({ milestones: three, currentId: 2 });

    expect(screen.getByTestId('milestone-summary')).toHaveTextContent(
        '1 of 3 milestones complete · 1 overdue',
    );
    const rows = screen.getByRole('list', { name: 'Milestones' });
    const design = within(rows).getByRole('listitem', { name: 'Design sign-off' });
    expect(within(design).getByText('3 of 3 linked tasks done')).toBeInTheDocument();
    expect(within(design).getByText('Overdue')).toBeInTheDocument();
    expect(within(rows).getByRole('listitem', { name: 'Go-live' })).toHaveTextContent(
        'No linked tasks',
    );
    expect(within(rows).getByRole('listitem', { name: 'Kickoff' })).toHaveTextContent(
        'by Mona Manager',
    );
    expect(screen.queryByText(/% complete/)).not.toBeInTheDocument();
});

it('shows an empty state with a create action for a manager, and none for a read-only viewer', () => {
    const { unmount } = renderPage();
    expect(screen.getByText('No milestones yet')).toBeInTheDocument();
    expect(screen.queryByRole('list', { name: /Milestones:/ })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Create the first milestone' })).toBeInTheDocument();
    unmount();

    renderPage({ abilities: { manage: false } });
    expect(screen.getByText('No milestones yet')).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Create the first milestone' }),
    ).not.toBeInTheDocument();
});

it("names the project, linking to its Overview, then Milestones, in the shell's one breadcrumb", () => {
    const props: MilestonesIndexProps = {
        project,
        milestones: three,
        currentId: 2,
        abilities: { manage: true },
    };
    setPageProps({
        auth: { user: shellUser, permissions: [] },
        shell: { presentation: 'operational' },
        navigation: navigation([projects], 'projects'),
        ...props,
    });
    const layout = MilestonesIndexPage.layout as (page: React.ReactElement) => React.ReactElement;

    render(layout(<MilestonesIndexPage {...props} />));

    const crumbs = screen.getAllByRole('navigation', { name: 'Breadcrumb' });
    expect(crumbs).toHaveLength(1);
    expect(within(crumbs[0]!).getByRole('link', { name: 'Portal rebuild' })).toHaveAttribute(
        'href',
        '/projects/7',
    );
    expect(within(crumbs[0]!).getByText('Milestones')).toHaveAttribute('aria-current', 'page');
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

it('deletes a milestone from its own row without disturbing the others', async () => {
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
    // Not optimistic: both rows remain until the server confirms. (Text, not roles: the open
    // confirmation dialog marks the rest of the page aria-hidden; each name also labels its stage.)
    expect(screen.getAllByText('Beta launch').length).toBeGreaterThan(0);
    expect(screen.getAllByText('GA release').length).toBeGreaterThan(0);
});

it('renders milestone names as text, never as markup', () => {
    renderPage({
        milestones: [milestone({ name: '<img src=x onerror=alert(1)>' })],
    });

    expect(document.querySelector('img')).toBeNull();
    expect(screen.getAllByText('<img src=x onerror=alert(1)>').length).toBeGreaterThan(0);
});
