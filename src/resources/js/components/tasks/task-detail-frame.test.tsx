import { render, screen, within } from '@testing-library/react';

import { TaskDetailFrame, TaskDetailsList } from '@/components/tasks/task-detail-frame';

it('is the Direction D detail grammar: a grid frame, an entity header and a labelled aside', () => {
    const { container } = render(
        <TaskDetailFrame
            overline="Task"
            title="Pack the van"
            status={<span>Open</span>}
            actions={<button type="button">Edit task</button>}
            aside={<p>aside body</p>}
        >
            <p>main body</p>
        </TaskDetailFrame>,
    );

    expect(container.querySelector('[data-page-frame="grid"]')).not.toBeNull();
    expect(screen.getByRole('heading', { level: 1, name: 'Pack the van' })).toBeInTheDocument();
    expect(container.querySelector('[data-strata]')).not.toBeNull();

    const aside = screen.getByRole('complementary', { name: 'Task details' });
    expect(within(aside).getByText('aside body')).toBeInTheDocument();
    // The main column is outside the aside: the frame puts the children before it.
    expect(within(aside).queryByText('main body')).not.toBeInTheDocument();
    expect(screen.getByText('main body')).toBeInTheDocument();
});

it('draws no breadcrumb or navigation landmark of its own: the shell owns the trail', () => {
    render(
        <TaskDetailFrame overline="Task" title="Pack the van" aside={<p>aside</p>}>
            <p>main</p>
        </TaskDetailFrame>,
    );

    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
});

it('lists the common details and slots in the kind-specific rows', () => {
    render(
        <TaskDetailsList
            assignee={<span>Max Member</span>}
            dueDate="2030-05-01"
            overdue={false}
            priority={{ label: 'High', value: 'high' }}
        >
            <TaskDetailsList.Row label="Milestone">Beta</TaskDetailsList.Row>
        </TaskDetailsList>,
    );

    const terms = screen.getAllByRole('term').map((term) => term.textContent);
    expect(terms).toEqual(['Assignee', 'Due date', 'Priority', 'Milestone']);
    expect(screen.getByText('Beta')).toBeInTheDocument();
    expect(screen.getByText('High')).toBeInTheDocument();
});

it('marks an overdue date with a cue that is not colour alone', () => {
    render(
        <TaskDetailsList
            assignee={<span>—</span>}
            dueDate="2020-01-01"
            overdue
            priority={{ label: 'Low', value: 'low' }}
        />,
    );

    expect(screen.getByText('Overdue:')).toBeInTheDocument();
    expect(screen.getByText('Jan 1, 2020', { exact: false })).toBeInTheDocument();
});

it('shows an em dash for no due date', () => {
    render(
        <TaskDetailsList
            assignee={<span>—</span>}
            dueDate={null}
            overdue={false}
            priority={{ label: 'Low', value: 'low' }}
        />,
    );

    expect(screen.getAllByText('—').length).toBeGreaterThan(0);
});
