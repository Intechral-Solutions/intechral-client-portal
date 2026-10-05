import { render, screen, within } from '@testing-library/react';

import { navigation, projects, shellUser } from '@/components/shell/shell-fixtures';
import {
    ProjectTimePage,
    type ProjectTimeProps,
    type ProjectTimeRow,
} from '@/pages/projects/time/index';
import { resetInertiaMock, setPageProps } from '@/test/inertia';
import type { Paginated } from '@/types/pagination';

/*
 * EPIC-015 WP5 (optional S3) — the project Time tab. Everything is the server's: scope, total,
 * rows, whose name appears and whether the tab exists. These tests pin that the page renders exactly
 * what it is given, says the scope in words, and adds no person, money or mutation of its own.
 */

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());
vi.mock('@/routes', () => ({ logout: { url: () => '/logout' } }));
vi.mock('@/routes/profile', () => ({ show: { url: () => '/profile' } }));
vi.mock('@/components/time/timer-pill', () => ({
    TimerPill: () => <span data-testid="timer-pill" />,
}));

beforeEach(() => setPageProps({}));
afterEach(resetInertiaMock);

function paginate(
    data: ProjectTimeRow[],
    overrides: Partial<Paginated<ProjectTimeRow>> = {},
): Paginated<ProjectTimeRow> {
    return {
        data,
        current_page: 1,
        last_page: 1,
        from: data.length ? 1 : null,
        to: data.length,
        total: data.length,
        links: [],
        prev_page_url: null,
        next_page_url: null,
        ...overrides,
    };
}

const taskRow: ProjectTimeRow = {
    id: 11,
    date: '2026-09-03',
    durationMinutes: 95,
    context: { kind: 'task', id: 4, title: 'Design review', url: '/projects/7/tasks/4' },
    userName: 'Worker Wren',
};

const directRow: ProjectTimeRow = {
    id: 10,
    date: '2026-09-01',
    durationMinutes: 30,
    context: { kind: 'project' },
    userName: 'Colleague Carl',
};

function allProps(overrides: Partial<ProjectTimeProps> = {}): ProjectTimeProps {
    return {
        project: { id: 7, name: 'Apollo', status: 'active' },
        summary: { scope: 'all', totalMinutes: 125 },
        entries: paginate([taskRow, directRow]),
        tabs: { time: true },
        abilities: {},
        ...overrides,
    };
}

function ownProps(overrides: Partial<ProjectTimeProps> = {}): ProjectTimeProps {
    // An own-scope row has no `userName` key at all (the server never sends it).
    const mine: ProjectTimeRow = {
        id: taskRow.id,
        date: taskRow.date,
        durationMinutes: taskRow.durationMinutes,
        context: taskRow.context,
    };

    return allProps({
        summary: { scope: 'own', totalMinutes: 95 },
        entries: paginate([mine]),
        ...overrides,
    });
}

function table() {
    return screen.getByRole('table', { name: 'Time entries' });
}

describe('composition', () => {
    it('is a project workspace page: one h1, the Time tab current, a hidden section heading', () => {
        render(<ProjectTimePage {...allProps()} />);

        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Apollo');
        expect(screen.getByRole('heading', { level: 2, name: 'Time' })).toHaveClass('sr-only');

        const nav = screen.getByRole('navigation', { name: 'Project' });
        expect(
            within(nav)
                .getAllByRole('link')
                .map((link) => link.textContent),
        ).toEqual(['Overview', 'Board', 'Tasks', 'Milestones', 'Time']);
        expect(within(nav).getByRole('link', { name: 'Time' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.queryByRole('tab')).not.toBeInTheDocument();
    });

    it('offers Settings only when the server says so, and never a timer, log or edit action', () => {
        const { rerender } = render(<ProjectTimePage {...allProps()} />);
        expect(screen.queryByRole('link', { name: 'Settings' })).not.toBeInTheDocument();

        rerender(<ProjectTimePage {...allProps({ abilities: { openSettings: true } })} />);
        expect(screen.getByRole('link', { name: 'Settings' })).toHaveAttribute(
            'href',
            '/projects/7/edit',
        );

        expect(screen.queryByRole('button')).toBeNull();
        expect(screen.queryByText(/start timer|log time|edit|delete/i)).toBeNull();
    });

    it('draws no breadcrumb of its own: the shell owns the trail', () => {
        render(<ProjectTimePage {...allProps()} />);

        expect(screen.queryByRole('navigation', { name: /breadcrumb/i })).not.toBeInTheDocument();
    });
});

describe('scope and total', () => {
    it('names all-team time as such, in words', () => {
        render(<ProjectTimePage {...allProps()} />);

        const summary = screen.getByLabelText('Project time summary');
        expect(within(summary).getByText('Time logged')).toBeInTheDocument();
        expect(within(summary).getByText('2h 5m')).toBeInTheDocument();
        expect(within(summary).getByText('All team members')).toBeInTheDocument();
        expect(within(summary).queryByText('Your entries only')).not.toBeInTheDocument();
    });

    it("names own time as the viewer's, never as the project total", () => {
        render(<ProjectTimePage {...ownProps()} />);

        expect(screen.getByText('Your time')).toBeInTheDocument();
        expect(screen.getByText('Your entries only')).toBeInTheDocument();
        expect(screen.getByText('1h 35m', { selector: 'dd' })).toBeInTheDocument();
        expect(screen.queryByText('Time logged')).not.toBeInTheDocument();
        expect(screen.queryByText('All team members')).not.toBeInTheDocument();
    });

    it('renders the server total as given, never a sum of the visible rows', () => {
        // Page 1 of several: the total covers rows this page does not show.
        render(
            <ProjectTimePage
                {...allProps({
                    summary: { scope: 'all', totalMinutes: 6000 },
                    entries: paginate([taskRow], { last_page: 3, total: 60 }),
                })}
            />,
        );

        expect(screen.getByText('100h', { selector: 'dd' })).toBeInTheDocument();
    });
});

describe('rows', () => {
    it('lists date, person, task context and duration for an all-team viewer', () => {
        render(<ProjectTimePage {...allProps()} />);

        expect(
            within(table())
                .getAllByRole('columnheader')
                .map((header) => header.textContent),
        ).toEqual(['Date', 'Person', 'Logged against', 'Duration']);

        const [first, second] = within(table()).getAllByRole('row').slice(1);
        expect(within(first!).getByText('Worker Wren')).toBeInTheDocument();
        expect(within(first!).getByRole('link', { name: 'Design review' })).toHaveAttribute(
            'href',
            '/projects/7/tasks/4',
        );
        expect(within(first!).getByText('1h 35m')).toBeInTheDocument();
        expect(within(first!).getByText(/2026/).closest('time')).toHaveAttribute(
            'datetime',
            '2026-09-03',
        );
        expect(within(second!).getByText('Project (no task)')).toBeInTheDocument();
        expect(within(second!).queryByRole('link')).toBeNull();
    });

    it('has no person column and no name at all for an own-scope viewer', () => {
        render(<ProjectTimePage {...ownProps()} />);

        expect(
            within(table())
                .getAllByRole('columnheader')
                .map((header) => header.textContent),
        ).toEqual(['Date', 'Logged against', 'Duration']);
        expect(screen.queryByText('Worker Wren')).not.toBeInTheDocument();
    });

    it('shows no money, billing or description, whatever a row might carry', () => {
        const smuggled = {
            ...taskRow,
            description: 'SECRET NOTE',
            billable: true,
            amount: '$500.00',
        } as unknown as ProjectTimeRow;
        render(<ProjectTimePage {...allProps({ entries: paginate([smuggled]) })} />);

        expect(screen.queryByText('SECRET NOTE')).not.toBeInTheDocument();
        expect(screen.queryByText(/\$|billable|invoice/i)).not.toBeInTheDocument();
    });

    it('paginates only when there is more than one page', () => {
        const { rerender } = render(<ProjectTimePage {...allProps()} />);
        expect(screen.queryByRole('navigation', { name: /pagination/i })).toBeNull();

        rerender(
            <ProjectTimePage
                {...allProps({
                    entries: paginate([taskRow], {
                        last_page: 2,
                        total: 26,
                        next_page_url: '/projects/7/time?page=2',
                    }),
                })}
            />,
        );
        expect(screen.getByRole('link', { name: /next/i })).toHaveAttribute(
            'href',
            '/projects/7/time?page=2',
        );
    });
});

describe('empty states', () => {
    it('says the project has no time yet for an all-team viewer', () => {
        render(
            <ProjectTimePage
                {...allProps({
                    summary: { scope: 'all', totalMinutes: 0 },
                    entries: paginate([]),
                })}
            />,
        );

        expect(screen.getByText('No time logged on this project yet')).toBeInTheDocument();
        expect(screen.queryByRole('table')).toBeNull();
        expect(screen.getByText('0m', { selector: 'dd' })).toBeInTheDocument();
    });

    it("speaks only of the viewer's own time for an own-scope viewer", () => {
        render(
            <ProjectTimePage
                {...ownProps({ summary: { scope: 'own', totalMinutes: 0 }, entries: paginate([]) })}
            />,
        );

        // Never "no time on this project": others may have logged time the viewer cannot see.
        expect(
            screen.getByText('You have not logged time on this project yet'),
        ).toBeInTheDocument();
        expect(screen.queryByText('No time logged on this project yet')).toBeNull();
    });
});

describe('a page past the end of the list', () => {
    it('keeps the truthful total and never says the project has no time', () => {
        // `?page=99`: Laravel answers an empty page with the real total, and with only one page of
        // entries no pagination bar is rendered, so the way back has to be on the page itself.
        render(
            <ProjectTimePage
                {...allProps({
                    summary: { scope: 'all', totalMinutes: 300 },
                    entries: paginate([], { current_page: 99, last_page: 1, total: 5 }),
                })}
            />,
        );

        expect(screen.getByText('5h', { selector: 'dd' })).toBeInTheDocument();
        expect(screen.queryByText('No time logged on this project yet')).toBeNull();
        expect(screen.getByText('No entries on this page')).toBeInTheDocument();
        expect(screen.queryByRole('table')).toBeNull();
        expect(screen.getByRole('link', { name: 'Back to the first page' })).toHaveAttribute(
            'href',
            '/projects/7/time',
        );
    });

    it('speaks only of the page for an own-scope viewer too', () => {
        render(
            <ProjectTimePage
                {...ownProps({
                    summary: { scope: 'own', totalMinutes: 95 },
                    entries: paginate([], { current_page: 9, last_page: 2, total: 30 }),
                })}
            />,
        );

        expect(screen.queryByText('You have not logged time on this project yet')).toBeNull();
        expect(screen.getByText('No entries on this page')).toBeInTheDocument();
    });
});

describe('the shell trail', () => {
    it("ends the shell's one breadcrumb on Time, the project segment opening the Overview", () => {
        const props = allProps();
        setPageProps({
            auth: { user: shellUser, permissions: [] },
            shell: { presentation: 'operational' },
            navigation: navigation([projects], 'projects'),
            ...props,
        });
        const layout = ProjectTimePage.layout as (page: React.ReactElement) => React.ReactElement;

        render(layout(<ProjectTimePage {...props} />));

        const crumbs = screen.getAllByRole('navigation', { name: 'Breadcrumb' });
        expect(crumbs).toHaveLength(1);
        expect(within(crumbs[0]!).getByRole('link', { name: 'Apollo' })).toHaveAttribute(
            'href',
            '/projects/7',
        );
        expect(within(crumbs[0]!).getByText('Time')).toHaveAttribute('aria-current', 'page');
        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    });

    it('survives Inertia 3 probing the layout function with the raw props object', () => {
        const layout = ProjectTimePage.layout as (page: unknown) => React.ReactElement;

        expect(() => layout({ project: { id: 7 } })).not.toThrow();
    });
});
