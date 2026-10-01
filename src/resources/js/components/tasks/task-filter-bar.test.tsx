import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TaskFilterBar } from '@/components/tasks/task-filter-bar';
import type { TaskFilterOptions, TaskListFilters, TaskListSort, TaskView } from '@/types/tasks';

const options: TaskFilterOptions = {
    completion: [
        { value: 'open', label: 'Open' },
        { value: 'done', label: 'Done' },
        { value: 'any', label: 'Any' },
    ],
    priorities: [
        { value: 'low', label: 'Low' },
        { value: 'medium', label: 'Medium' },
        { value: 'high', label: 'High' },
        { value: 'critical', label: 'Critical' },
    ],
    due: [
        { value: 'overdue', label: 'Overdue' },
        { value: 'today', label: 'Due today' },
        { value: 'next7', label: 'Next 7 days' },
        { value: 'none', label: 'No due date' },
    ],
    kinds: [
        { value: 'project', label: 'Project' },
        { value: 'standalone', label: 'Standalone' },
    ],
    sorts: [
        { value: 'due', label: 'Due date' },
        { value: 'priority', label: 'Priority' },
    ],
    projects: [{ id: 7, name: 'Alpha' }],
    milestones: [],
    assignees: [{ id: 5, name: 'Mia Member' }],
    organizations: [{ id: 3, name: 'Acme' }],
};
const none: TaskListFilters = {
    completion: 'open',
    priority: [],
    due: null,
    kind: null,
    project: null,
    milestone: null,
    assignee: null,
    organization: null,
    q: '',
};
const dueAsc: TaskListSort = { by: 'due', dir: 'asc' };

function setup(
    over: {
        view?: TaskView;
        filters?: Partial<TaskListFilters>;
        options?: Partial<TaskFilterOptions>;
        sort?: TaskListSort;
    } = {},
) {
    const onChange = vi.fn();
    const onClear = vi.fn();
    const utils = render(
        <TaskFilterBar
            view={over.view ?? 'mine'}
            filters={{ ...none, ...over.filters }}
            filterOptions={{ ...options, ...over.options }}
            sort={over.sort ?? dueAsc}
            onChange={onChange}
            onClear={onClear}
        />,
    );

    return { onChange, onClear, ...utils };
}

describe('TaskFilterBar controls', () => {
    it('labels every control and offers server-named options only', () => {
        setup();

        const group = screen.getByRole('group', { name: 'Filter tasks' });
        for (const label of [
            'Search tasks',
            'Completion',
            'Due',
            'Kind',
            'Project',
            'Organization',
            'Sort by',
            'Order',
        ]) {
            expect(within(group).getByLabelText(label)).toBeVisible();
        }
        expect(within(group).getByRole('group', { name: 'Priority' })).toBeVisible();
        expect(
            Array.from((screen.getByLabelText('Kind') as HTMLSelectElement).options).map(
                (o) => o.text,
            ),
        ).toEqual(['All kinds', 'Project', 'Standalone']);
        // No ticket option exists anywhere (Q6).
        expect(group).not.toHaveTextContent(/ticket/i);
    });

    it('renders the assignee control only in All Tasks', () => {
        const { unmount } = setup({ view: 'mine' });
        expect(screen.queryByLabelText('Assignee')).not.toBeInTheDocument();
        unmount();

        setup({ view: 'all' });
        expect(
            Array.from((screen.getByLabelText('Assignee') as HTMLSelectElement).options).map(
                (o) => o.text,
            ),
        ).toEqual(['All assignees', 'Unassigned', 'Mia Member']);
    });

    it('renders the milestone control only while a project is selected and milestones are offered', () => {
        const { unmount } = setup();
        expect(screen.queryByLabelText('Milestone')).not.toBeInTheDocument();
        unmount();

        setup({ filters: { project: 7 }, options: { milestones: [{ id: 2, name: 'Launch' }] } });
        expect(screen.getByLabelText('Milestone')).toBeVisible();
    });

    it('omits the organization control when none is offered and none is active', () => {
        setup({ options: { organizations: [] } });

        expect(screen.queryByLabelText('Organization')).not.toBeInTheDocument();
    });
});

describe('TaskFilterBar changes', () => {
    it('reports a select change as a single patch', async () => {
        const user = userEvent.setup();
        const { onChange } = setup({ view: 'all' });

        await user.selectOptions(screen.getByLabelText('Completion'), 'Any');
        expect(onChange).toHaveBeenLastCalledWith({ completion: 'any' });

        await user.selectOptions(screen.getByLabelText('Due'), 'Overdue');
        expect(onChange).toHaveBeenLastCalledWith({ due: 'overdue' });

        await user.selectOptions(screen.getByLabelText('Kind'), 'Project');
        expect(onChange).toHaveBeenLastCalledWith({ kind: 'project' });

        await user.selectOptions(screen.getByLabelText('Project'), 'Alpha');
        expect(onChange).toHaveBeenLastCalledWith({ project: 7 });

        await user.selectOptions(screen.getByLabelText('Assignee'), 'Unassigned');
        expect(onChange).toHaveBeenLastCalledWith({ assignee: 'none' });

        await user.selectOptions(screen.getByLabelText('Assignee'), 'Mia Member');
        expect(onChange).toHaveBeenLastCalledWith({ assignee: 5 });

        await user.selectOptions(screen.getByLabelText('Organization'), 'Acme');
        expect(onChange).toHaveBeenLastCalledWith({ organization: 3 });
    });

    it('clears a filter by choosing its empty option', async () => {
        const user = userEvent.setup();
        const { onChange } = setup({ filters: { due: 'today', project: 7 } });

        await user.selectOptions(screen.getByLabelText('Due'), 'Any due date');
        expect(onChange).toHaveBeenLastCalledWith({ due: null });

        await user.selectOptions(screen.getByLabelText('Project'), 'All projects');
        expect(onChange).toHaveBeenLastCalledWith({ project: null });
    });

    it('toggles priorities as a set', async () => {
        const user = userEvent.setup();
        const { onChange } = setup({ filters: { priority: ['high'] } });

        await user.click(screen.getByRole('button', { name: 'Low' }));
        expect(onChange).toHaveBeenLastCalledWith({ priority: ['low', 'high'] });

        await user.click(screen.getByRole('button', { name: 'High' }));
        expect(onChange).toHaveBeenLastCalledWith({ priority: [] });
    });

    it('commits a search on submit only, never per keystroke', async () => {
        const user = userEvent.setup();
        const { onChange } = setup();

        await user.type(screen.getByRole('searchbox', { name: 'Search tasks' }), 'report');
        expect(onChange).not.toHaveBeenCalled();

        await user.keyboard('{Enter}');
        expect(onChange).toHaveBeenLastCalledWith({ q: 'report' });
    });

    it('changes the sort field without sending a direction, and the direction on its own', async () => {
        const user = userEvent.setup();
        const { onChange } = setup();

        await user.selectOptions(screen.getByLabelText('Sort by'), 'Priority');
        expect(onChange).toHaveBeenLastCalledWith({ sort: 'priority' });

        await user.selectOptions(screen.getByLabelText('Order'), 'Descending');
        expect(onChange).toHaveBeenLastCalledWith({ dir: 'desc' });
    });

    it('shows the sort the server resolved', () => {
        setup({ sort: { by: 'priority', dir: 'desc' } });

        expect(screen.getByLabelText('Sort by')).toHaveValue('priority');
        expect(screen.getByLabelText('Order')).toHaveValue('desc');
    });
});

describe('TaskFilterBar active filters', () => {
    it('shows no chip and no Clear filters for the default state', () => {
        setup();

        expect(screen.queryByRole('list', { name: 'Active filters' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Clear filters' })).not.toBeInTheDocument();
    });

    it('chips every non-default filter with its server label', () => {
        setup({
            view: 'all',
            filters: {
                completion: 'done',
                priority: ['high', 'critical'],
                due: 'overdue',
                kind: 'standalone',
                project: 7,
                assignee: 'none',
                organization: 3,
            },
        });

        const chips = within(screen.getByRole('list', { name: 'Active filters' }))
            .getAllByRole('listitem')
            .map((item) => item.textContent?.replace(/\s+/g, ' ').trim());

        expect(chips).toEqual([
            'Completion: Done',
            'Priority: High',
            'Priority: Critical',
            'Due: Overdue',
            'Kind: Standalone',
            'Project: Alpha',
            'Assignee: Unassigned',
            'Organization: Acme',
        ]);
    });

    it('removes one filter from its chip', async () => {
        const user = userEvent.setup();
        const { onChange } = setup({ filters: { due: 'overdue', priority: ['high', 'low'] } });

        await user.click(screen.getByRole('button', { name: 'Remove filter: Due: Overdue' }));
        expect(onChange).toHaveBeenLastCalledWith({ due: null });

        await user.click(screen.getByRole('button', { name: 'Remove filter: Priority: High' }));
        expect(onChange).toHaveBeenLastCalledWith({ priority: ['low'] });
    });

    it('resets completion to open when its chip is removed', async () => {
        const user = userEvent.setup();
        const { onChange } = setup({ filters: { completion: 'any' } });

        await user.click(screen.getByRole('button', { name: 'Remove filter: Completion: Any' }));

        expect(onChange).toHaveBeenLastCalledWith({ completion: 'open' });
    });

    it('clears everything from Clear filters, including a search that has no chip', async () => {
        const user = userEvent.setup();
        const { onClear } = setup({ filters: { q: 'report' } });

        await user.click(screen.getByRole('button', { name: 'Clear filters' }));

        expect(onClear).toHaveBeenCalledTimes(1);
    });
});

describe('TaskFilterBar unlabeled ids (owner decision B)', () => {
    it.each([
        ['project', { project: 999 }, 'Project filter', 'Project'],
        ['organization', { organization: 998 }, 'Organization filter', 'Organization'],
    ] as const)(
        'keeps an unlabeled %s active and clearable with a type-only label',
        async (_name, filters, label, field) => {
            const user = userEvent.setup();
            const { onChange } = setup({ filters });

            expect(screen.getByText(label, { selector: 'span' })).toBeVisible();
            // The select still shows the active choice instead of silently reverting to "All".
            const select = screen.getByLabelText(field) as HTMLSelectElement;
            expect(select.selectedOptions[0]!.text).toBe(label);
            // No id and no invented name is shown.
            expect(document.body).not.toHaveTextContent(/99[89]/);

            await user.click(screen.getByRole('button', { name: `Remove filter: ${label}` }));
            expect(onChange).toHaveBeenLastCalledWith(
                field === 'Project' ? { project: null } : { organization: null },
            );
        },
    );

    it('does the same for an unoffered assignee in All Tasks', async () => {
        const user = userEvent.setup();
        const { onChange } = setup({ view: 'all', filters: { assignee: 4242 } });

        expect(
            (screen.getByLabelText('Assignee') as HTMLSelectElement).selectedOptions[0]!.text,
        ).toBe('Assignee filter');
        await user.click(screen.getByRole('button', { name: 'Remove filter: Assignee filter' }));

        expect(onChange).toHaveBeenLastCalledWith({ assignee: null });
        expect(document.body).not.toHaveTextContent('4242');
    });

    it('keeps an unlabeled organization control visible even when no organization is offered', () => {
        setup({ filters: { organization: 998 }, options: { organizations: [] } });

        expect(screen.getByLabelText('Organization')).toBeVisible();
    });
});
