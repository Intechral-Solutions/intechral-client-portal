import { render, screen, within } from '@testing-library/react';

import { navigation, projects, shellUser } from '@/components/shell/shell-fixtures';
import { keyMilestones, ProjectShowPage } from '@/pages/projects/show';
import { resetInertiaMock, setPageProps } from '@/test/inertia';
import type { MilestoneItem, ProjectHealthDto, ProjectOverviewProps } from '@/types/projects';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());
vi.mock('@/routes', () => ({ logout: { url: () => '/logout' } }));
vi.mock('@/routes/profile', () => ({ show: { url: () => '/profile' } }));
vi.mock('@/components/time/timer-pill', () => ({
    TimerPill: () => <span data-testid="timer-pill" />,
}));

beforeEach(() => setPageProps({}));
afterEach(resetInertiaMock);

function milestone(overrides: Partial<MilestoneItem> = {}): MilestoneItem {
    return {
        id: 1,
        name: 'Kickoff',
        description: null,
        dueDate: '2026-03-01',
        taskCount: 0,
        doneCount: 0,
        openTaskCount: 0,
        completion: 0,
        completedAt: null,
        completedBy: null,
        overdue: false,
        ...overrides,
    };
}

const onTrack: ProjectHealthDto = { state: 'on_track', label: 'On track', reasons: [] };

/** A plain member's DTO: no gated key at all. */
function overview(overrides: Partial<ProjectOverviewProps> = {}): ProjectOverviewProps {
    return {
        project: {
            id: 7,
            name: 'Portal rebuild',
            description: null,
            status: 'active',
            startDate: null,
            targetDate: null,
        },
        health: onTrack,
        tasks: { total: 10, done: 4, open: 6, overdue: 2, completion: 40 },
        milestones: {
            total: 0,
            completed: 0,
            overdue: 0,
            currentId: null,
            nextId: null,
            items: [],
        },
        abilities: {},
        ...overrides,
    };
}

function renderPage(props: ProjectOverviewProps = overview()) {
    return render(<ProjectShowPage {...props} />);
}

function section(name: string) {
    return screen.getByRole('heading', { level: 2, name }).closest('section')!;
}

describe('the Direction D entity grammar', () => {
    it('has exactly one h1, the project name, under the Project overline, with the strata', () => {
        const { container } = renderPage();

        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(
            screen.getByRole('heading', { level: 1, name: 'Portal rebuild' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Project')).toBeInTheDocument();
        expect(container.querySelectorAll('[data-strata]')).toHaveLength(1);
        expect(container.querySelector('[data-page-frame="grid"]')).not.toBeNull();
    });

    // FLIPPED IN EPIC-015 WP3: WP2 pinned "Overview · Board · Milestones, no Tasks yet"; WP3 adds
    // the Tasks tab (§11.3.1), so the strip is the final four.
    it('carries the project navigation as page links, Overview current, all four tabs', () => {
        renderPage();

        const nav = screen.getByRole('navigation', { name: 'Project' });
        expect(
            within(nav)
                .getAllByRole('link')
                .map((link) => [link.textContent, link.getAttribute('href')]),
        ).toEqual([
            ['Overview', '/projects/7'],
            ['Board', '/projects/7/board'],
            ['Tasks', '/projects/7/tasks'],
            ['Milestones', '/projects/7/milestones'],
        ]);
        expect(within(nav).getByRole('link', { name: 'Overview' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
        expect(screen.queryByRole('tab')).not.toBeInTheDocument();
    });

    it('draws no breadcrumb of its own', () => {
        renderPage();

        expect(screen.queryByRole('navigation', { name: 'Breadcrumb' })).not.toBeInTheDocument();
    });

    it('shows the start and target dates when set, and no date line when neither is', () => {
        const { unmount } = renderPage(
            overview({
                project: {
                    ...overview().project,
                    startDate: '2026-03-01',
                    targetDate: '2026-06-30',
                },
            }),
        );
        const header = screen.getByRole('heading', { level: 1 }).closest('header')!;
        expect(within(header).getByText('Mar 1, 2026')).toBeInTheDocument();
        expect(within(header).getByText('Jun 30, 2026')).toBeInTheDocument();
        unmount();

        renderPage();
        expect(screen.queryByText(/^Start/)).not.toBeInTheDocument();
        expect(screen.queryByText(/^Target/)).not.toBeInTheDocument();
    });
});

describe('lifecycle versus health', () => {
    it('shows lifecycle beside the name and derived health in its own section', () => {
        renderPage(overview({ health: { state: 'at_risk', label: 'At risk', reasons: [] } }));

        const header = screen.getByRole('heading', { level: 1 }).closest('header')!;
        expect(within(header).getByText('Active')).toBeInTheDocument();
        expect(within(header).queryByText('At risk')).not.toBeInTheDocument();
        expect(within(section('Health')).getByText('At risk')).toBeInTheDocument();
    });

    it.each([
        ['on_track', 'On track', 'success'],
        ['at_risk', 'At risk', 'warning'],
        ['off_track', 'Off track', 'danger'],
        ['not_started', 'Not started', 'muted'],
        ['complete', 'Complete', 'success'],
    ] as const)('renders %s with the server label and its status glyph', (state, label, tone) => {
        renderPage(overview({ health: { state, label, reasons: [] } }));

        const mark = within(section('Health')).getByText(label).parentElement!;
        expect(mark).toHaveAttribute('data-health-state', state);
        expect(mark.className).toContain(`text-${tone === 'muted' ? 'text-muted' : tone}`);
        expect(mark.querySelector('svg')).not.toBeNull();
    });

    it('renders "Not enough data" as muted text, not a health glyph', () => {
        renderPage(
            overview({
                health: {
                    state: 'insufficient_data',
                    label: 'Not enough data',
                    reasons: [{ code: 'no_tracked_work' }],
                },
            }),
        );

        const label = within(section('Health')).getByText('Not enough data');
        expect(label).toHaveClass('text-text-muted');
        expect(label.querySelector('svg')).toBeNull();
        expect(
            within(section('Health')).getByText('No tasks or milestones yet'),
        ).toBeInTheDocument();
    });

    it('invents no health when the server sends none (on hold, archived)', () => {
        renderPage(
            overview({ project: { ...overview().project, status: 'on_hold' }, health: null }),
        );

        expect(screen.queryByRole('heading', { name: 'Health' })).not.toBeInTheDocument();
        for (const label of [
            'On track',
            'At risk',
            'Off track',
            'Not started',
            'Not enough data',
        ]) {
            expect(screen.queryByText(label)).not.toBeInTheDocument();
        }
        expect(screen.getByText('On hold')).toBeInTheDocument();
    });

    it('lists structured reasons in the server order, by code', () => {
        renderPage(
            overview({
                health: {
                    state: 'off_track',
                    label: 'Off track',
                    reasons: [
                        { code: 'target_passed', openTaskCount: 3 },
                        {
                            code: 'milestones_overdue',
                            count: 2,
                            earliest: { id: 4, name: 'Beta', dueDate: '2026-02-01' },
                        },
                        { code: 'tasks_overdue', count: 1 },
                    ],
                },
            }),
        );

        const reasons = within(
            within(section('Health')).getByRole('list', { name: 'Health reasons' }),
        ).getAllByRole('listitem');
        expect(reasons.map((reason) => reason.getAttribute('data-reason'))).toEqual([
            'target_passed',
            'milestones_overdue',
            'tasks_overdue',
        ]);
        expect(reasons.map((reason) => reason.textContent)).toEqual([
            'Target date has passed with 3 open tasks',
            '2 milestones overdue, earliest “Beta” (due Feb 1, 2026)',
            '1 task overdue',
        ]);
    });

    it('names a single overdue milestone and words a future start', () => {
        const { unmount } = renderPage(
            overview({
                health: {
                    state: 'off_track',
                    label: 'Off track',
                    reasons: [
                        {
                            code: 'milestones_overdue',
                            count: 1,
                            earliest: { id: 4, name: 'Beta', dueDate: '2026-02-01' },
                        },
                    ],
                },
            }),
        );
        expect(
            screen.getByText('Milestone “Beta” is overdue (due Feb 1, 2026)'),
        ).toBeInTheDocument();
        unmount();

        renderPage(
            overview({
                health: {
                    state: 'not_started',
                    label: 'Not started',
                    reasons: [{ code: 'starts_in_future', date: '2026-11-02' }],
                },
            }),
        );
        expect(screen.getByText('Starts Nov 2, 2026')).toBeInTheDocument();
    });

    it('never renders a raw unknown reason code', () => {
        renderPage(
            overview({
                health: {
                    state: 'at_risk',
                    label: 'At risk',
                    reasons: [
                        { code: 'some_future_code' } as never,
                        { code: 'tasks_overdue', count: 2 },
                    ],
                },
            }),
        );

        expect(screen.queryByText(/some_future_code/)).not.toBeInTheDocument();
        expect(screen.getByText('2 tasks overdue')).toBeInTheDocument();
    });
});

describe('task progress and summary', () => {
    it('shows done of total with a named progressbar driven by the server counts', () => {
        renderPage();

        const tasks = section('Tasks');
        expect(within(tasks).getByText('4 of 10 tasks complete')).toBeInTheDocument();
        expect(within(tasks).getByText('40%')).toBeInTheDocument();

        const bar = within(tasks).getByRole('progressbar', { name: 'Task progress' });
        expect(bar).toHaveAttribute('aria-valuenow', '4');
        expect(bar).toHaveAttribute('aria-valuemax', '10');
        expect(bar).toHaveAttribute('aria-valuetext', '4 of 10 tasks complete, 40%');
    });

    it('uses the server completion figure rather than recomputing it', () => {
        // A deliberately inconsistent DTO: the page must show the server's number, not 4/10.
        renderPage(
            overview({ tasks: { total: 10, done: 4, open: 6, overdue: 0, completion: 41 } }),
        );

        expect(within(section('Tasks')).getByText('41%')).toBeInTheDocument();
    });

    // FLIPPED IN EPIC-015 WP3: WP2 sent both counts and "Open board" to the Board (§11.3.1), and
    // pinned that nothing pointed at /tasks. WP3 retargets them to the project Tasks tab with the
    // filter that matches what was counted; the empty state's explicit "Open board" stays (P8).
    it('shows open and overdue counts that open the filtered project Tasks tab', () => {
        renderPage();

        const tasks = section('Tasks');
        const open = within(tasks).getByRole('link', { name: '6 open tasks: view in Tasks' });
        const overdue = within(tasks).getByRole('link', {
            name: '2 overdue tasks: view in Tasks',
        });

        expect(open).toHaveAttribute('href', '/projects/7/tasks?completion=open');
        expect(overdue).toHaveAttribute('href', '/projects/7/tasks?completion=open&due=overdue');
        expect(within(tasks).getByRole('link', { name: 'View tasks' })).toHaveAttribute(
            'href',
            '/projects/7/tasks',
        );
        // A link that opens the task list is never labelled as the board.
        expect(tasks.querySelector('a[href$="/board"]')).toBeNull();
        expect(within(tasks).queryByText(/board/i)).not.toBeInTheDocument();
    });

    it('marks overdue in danger only when there is overdue work', () => {
        const { unmount } = renderPage();
        expect(screen.getByText('2', { selector: 'dd' })).toHaveClass('text-danger');
        unmount();

        renderPage(overview({ tasks: { total: 3, done: 1, open: 2, overdue: 0, completion: 33 } }));
        expect(screen.getByText('0', { selector: 'dd' })).not.toHaveClass('text-danger');
    });
});

describe('milestones', () => {
    const items = [
        milestone({
            id: 1,
            name: 'Kickoff',
            dueDate: '2026-01-10',
            completedAt: '2026-01-09T10:00:00+00:00',
        }),
        milestone({ id: 2, name: 'Design', dueDate: '2026-02-10', overdue: true }),
        milestone({
            id: 3,
            name: 'Build',
            dueDate: '2026-03-10',
            overdue: true,
            taskCount: 4,
            doneCount: 4,
            completion: 100,
        }),
        milestone({
            id: 4,
            name: 'Launch',
            dueDate: '2026-12-10',
            taskCount: 2,
            doneCount: 1,
            openTaskCount: 1,
            completion: 50,
        }),
        milestone({ id: 5, name: 'Review', dueDate: '2027-01-10' }),
    ];
    const milestones = { total: 5, completed: 1, overdue: 2, currentId: 2, nextId: 4, items };

    it('summarises completion and maps explicit completion onto a compact StagePath', () => {
        renderPage(overview({ milestones }));

        const ms = section('Milestones');
        expect(within(ms).getByText(/1 of 5 milestones complete/)).toBeInTheDocument();

        const path = within(ms).getByRole('list', { name: 'Milestones: 1 of 5 complete' });
        const stages = within(path).getAllByRole('listitem');
        expect(stages.map((stage) => stage.getAttribute('data-stage-state'))).toEqual([
            'done',
            'current',
            // "Build" has every task done but is NOT complete: task progress never completes a milestone.
            'planned',
            'planned',
            'planned',
        ]);
        expect(stages[1]).toHaveAttribute('aria-current', 'step');
        expect(
            within(path).getByText('Design: Current, Overdue · due Feb 10, 2026'),
        ).toBeInTheDocument();
    });

    it('names the current, overdue and next milestones once each, in server order', () => {
        renderPage(overview({ milestones }));

        const rows = within(
            within(section('Milestones')).getByRole('list', { name: 'Key milestones' }),
        ).getAllByRole('listitem');
        expect(
            rows.map((row) => [
                row.getAttribute('data-milestone-role'),
                row.querySelector('.font-medium')?.textContent,
            ]),
        ).toEqual([
            ['current', 'Design'],
            ['overdue', 'Build'],
            ['next', 'Launch'],
        ]);
    });

    it('renders a milestone that is both current and next only once (nextId === currentId)', () => {
        const same = {
            total: 2,
            completed: 0,
            overdue: 0,
            currentId: 4,
            nextId: 4,
            items: [items[3]!, items[4]!],
        };
        renderPage(overview({ milestones: same }));

        expect(keyMilestones(same).map((row) => [row.item.id, row.role])).toEqual([[4, 'current']]);
        const list = within(section('Milestones')).getByRole('list', { name: 'Key milestones' });
        expect(within(list).getAllByText('Launch')).toHaveLength(1);
        expect(within(list).queryByText('Next')).not.toBeInTheDocument();
    });

    it('words milestone task progress as task progress, never as completion', () => {
        renderPage(overview({ milestones }));

        const list = within(section('Milestones')).getByRole('list', { name: 'Key milestones' });
        // 100% task progress on an explicitly incomplete, overdue milestone.
        expect(within(list).getByText('4 of 4 linked tasks done')).toBeInTheDocument();
        expect(within(list).getByText('1 of 2 linked tasks done')).toBeInTheDocument();
        expect(within(list).getByText('No linked tasks')).toBeInTheDocument();
        expect(within(list).queryByText(/complete/i)).not.toBeInTheDocument();
        expect(within(list).queryByText(/%/)).not.toBeInTheDocument();
    });

    it('marks overdue milestones in text as well as colour', () => {
        renderPage(overview({ milestones }));

        const build = within(section('Milestones')).getByText('Build').closest('li')!;
        expect(within(build).getByText(/Overdue ·/)).toHaveClass('text-danger');
    });

    it('shows a completed path without inventing a current milestone', () => {
        const done = {
            total: 2,
            completed: 2,
            overdue: 0,
            currentId: null,
            nextId: null,
            items: [
                milestone({ id: 1, completedAt: '2026-01-01T00:00:00+00:00' }),
                milestone({ id: 2, name: 'Go-live', completedAt: '2026-02-01T00:00:00+00:00' }),
            ],
        };
        renderPage(overview({ milestones: done }));

        const ms = section('Milestones');
        const stages = within(
            within(ms).getByRole('list', { name: 'Milestones: 2 of 2 complete' }),
        ).getAllByRole('listitem');
        expect(stages.map((stage) => stage.getAttribute('data-stage-state'))).toEqual([
            'done',
            'done',
        ]);
        expect(stages.some((stage) => stage.hasAttribute('aria-current'))).toBe(false);
        expect(within(ms).getByText('All milestones complete')).toBeInTheDocument();
        expect(within(ms).queryByRole('list', { name: 'Key milestones' })).not.toBeInTheDocument();
    });

    it('windows more than seven milestones with caller-written summaries', () => {
        const many = Array.from({ length: 12 }, (_, index) =>
            milestone({
                id: index + 1,
                name: `M${index + 1}`,
                dueDate: `2026-${String(index + 1).padStart(2, '0')}-01`,
                completedAt: index < 10 ? '2026-01-01T00:00:00+00:00' : null,
            }),
        );
        renderPage(
            overview({
                milestones: {
                    total: 12,
                    completed: 10,
                    overdue: 0,
                    currentId: 11,
                    nextId: 11,
                    items: many,
                },
            }),
        );

        const path = screen.getByRole('list', { name: 'Milestones: 10 of 12 complete' });
        expect(within(path).getAllByRole('listitem')).toHaveLength(7);
        expect(screen.getByText('+5 earlier')).toBeInTheDocument();
        expect(screen.queryByText(/later$/)).not.toBeInTheDocument();
    });

    it('caps the overdue rows and points at the Milestones page for the rest', () => {
        const overdue = Array.from({ length: 8 }, (_, index) =>
            milestone({ id: index + 1, name: `Late ${index + 1}`, overdue: true }),
        );
        renderPage(
            overview({
                milestones: {
                    total: 8,
                    completed: 0,
                    overdue: 8,
                    currentId: 1,
                    nextId: null,
                    items: overdue,
                },
            }),
        );

        const list = within(section('Milestones')).getByRole('list', { name: 'Key milestones' });
        // The current one plus five other overdue ones.
        expect(within(list).getAllByRole('listitem')).toHaveLength(6);
        expect(screen.getByText(/2 more overdue milestones on the/)).toBeInTheDocument();
    });

    it('shows a short empty state, not an empty StagePath shell, with no milestones', () => {
        renderPage();

        const ms = section('Milestones');
        expect(within(ms).getByText('No milestones yet')).toBeInTheDocument();
        expect(within(ms).queryByRole('list')).not.toBeInTheDocument();
        expect(within(ms).getByRole('link', { name: 'Open milestones' })).toHaveAttribute(
            'href',
            '/projects/7/milestones',
        );
    });
});

describe('gated fields render only when their key is present', () => {
    it('shows nothing gated to a viewer whose DTO carries no gated key', () => {
        renderPage();

        for (const name of ['People', 'Details', 'About']) {
            expect(screen.queryByRole('heading', { name })).not.toBeInTheDocument();
        }
        expect(screen.queryByText('Budget')).not.toBeInTheDocument();
        expect(screen.queryByText(/time/i)).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Settings' })).not.toBeInTheDocument();
        expect(screen.queryByRole('complementary')).not.toBeInTheDocument();
    });

    it('treats an empty abilities array (PHP [] serialization) as no ability', () => {
        renderPage(overview({ abilities: [] as unknown as ProjectOverviewProps['abilities'] }));

        expect(screen.queryByRole('link', { name: 'Settings' })).not.toBeInTheDocument();
    });

    it('offers Settings only from abilities.openSettings', () => {
        renderPage(overview({ abilities: { openSettings: true } }));

        expect(screen.getByRole('link', { name: 'Settings' })).toHaveAttribute(
            'href',
            '/projects/7/edit',
        );
    });

    it('renders the roster as names and project roles, owner first as sent', () => {
        renderPage(
            overview({
                members: [
                    { id: 1, name: 'Olive Owner', role: 'manager', isOwner: true },
                    { id: 2, name: 'Max Member', role: 'member', isOwner: false },
                ],
            }),
        );

        const people = section('People');
        expect(
            within(people)
                .getAllByRole('listitem')
                .map((item) => item.textContent),
        ).toEqual(['Olive OwnerOwner · Manager', 'Max MemberMember']);
        expect(screen.getByRole('complementary', { name: 'Project details' })).toContainElement(
            people,
        );
    });

    it('shows an authorized null budget as "Not set", and a value as metadata', () => {
        const { unmount } = renderPage(overview({ budget: null }));
        expect(within(section('Details')).getByText('Budget')).toBeInTheDocument();
        expect(within(section('Details')).getByText('Not set')).toBeInTheDocument();
        unmount();

        renderPage(overview({ budget: '1234567.50' }));
        expect(within(section('Details')).getByText('$1,234,567.50')).toBeInTheDocument();
        expect(screen.queryByText(/burn|remaining|spent|forecast/i)).not.toBeInTheDocument();
    });

    it('labels all-user time as the project total', () => {
        renderPage(overview({ time: { scope: 'all', totalMinutes: 200 } }));

        const details = section('Details');
        expect(within(details).getByText('Time logged')).toBeInTheDocument();
        expect(within(details).getByText('3h 20m')).toBeInTheDocument();
        expect(within(details).getByText('All team members')).toBeInTheDocument();
        expect(within(details).queryByText('Your time')).not.toBeInTheDocument();
    });

    it("labels own time as the viewer's, never as the project total", () => {
        renderPage(overview({ time: { scope: 'own', totalMinutes: 45 } }));

        const details = section('Details');
        expect(within(details).getByText('Your time')).toBeInTheDocument();
        expect(within(details).getByText('45m')).toBeInTheDocument();
        expect(within(details).getByText('Your entries only')).toBeInTheDocument();
        expect(within(details).queryByText('Time logged')).not.toBeInTheDocument();
        expect(within(details).queryByText('Budget')).not.toBeInTheDocument();
    });

    it('renders the description as plain text', () => {
        renderPage(
            overview({
                project: { ...overview().project, description: '<b>bold</b>\nSecond line' },
            }),
        );

        const about = section('About');
        expect(within(about).getByText(/<b>bold<\/b>/)).toBeInTheDocument();
        expect(about.querySelector('b')).toBeNull();
    });
});

describe('a new, sparse project', () => {
    it('reads as new rather than broken: no data, short empty states, nothing gated', () => {
        const { container } = renderPage(
            overview({
                health: {
                    state: 'insufficient_data',
                    label: 'Not enough data',
                    reasons: [{ code: 'no_tracked_work' }],
                },
                tasks: { total: 0, done: 0, open: 0, overdue: 0, completion: 0 },
            }),
        );

        expect(within(section('Tasks')).getByText('No tasks yet')).toBeInTheDocument();
        expect(within(section('Tasks')).getByRole('link', { name: 'Open board' })).toHaveAttribute(
            'href',
            '/projects/7/board',
        );
        expect(within(section('Milestones')).getByText('No milestones yet')).toBeInTheDocument();
        expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
        // Without a rail the grid frame has no split column.
        expect(container.querySelector('[data-page-split]')).toBeNull();
    });
});

describe('the shell trail seam (A13.12)', () => {
    function renderInShell() {
        const props = overview();
        setPageProps({
            auth: { user: shellUser, permissions: [] },
            shell: { presentation: 'operational' },
            navigation: navigation([projects], 'projects'),
            ...props,
        });
        const layout = ProjectShowPage.layout as (page: React.ReactElement) => React.ReactElement;

        return render(layout(<ProjectShowPage {...props} />));
    }

    it("ends the shell's one breadcrumb on the project, with none in the page body", () => {
        renderInShell();

        const crumbs = screen.getAllByRole('navigation', { name: 'Breadcrumb' });
        expect(crumbs).toHaveLength(1);
        expect(within(crumbs[0]!).getByText('Portal rebuild')).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(
            within(screen.getByRole('main')).queryByRole('navigation', { name: 'Breadcrumb' }),
        ).toBeNull();
        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    });

    it('survives Inertia 3 probing the layout function with the raw props object', () => {
        const layout = ProjectShowPage.layout as (page: unknown) => React.ReactElement;

        expect(() => layout({ project: { id: 7 } })).not.toThrow();
    });
});
