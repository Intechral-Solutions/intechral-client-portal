import { render, screen } from '@testing-library/react';

import { contextLinkModes, TimerContextLink } from '@/components/time/timer-context-link';
import { resetInertiaMock } from '@/test/inertia';

// The Inertia double renders `Link` as an anchor too, so the two paths are told apart by a marker.
vi.mock('@inertiajs/react', async () => {
    const mock = (await import('@/test/inertia')).inertiaReactMock();

    return {
        ...mock,
        Link: (props: React.ComponentProps<typeof mock.Link>) => (
            <mock.Link {...props} data-router="inertia" />
        ),
    };
});

const original = { ...contextLinkModes };

afterEach(() => {
    Object.assign(contextLinkModes, original);
    resetInertiaMock();
});

it('links a still-Blade kind with a plain document anchor, and a migrated kind with Inertia', () => {
    // WP5 flipped project (the board); WP7 flips task (the task detail page). ticket stays
    // Blade until EPIC-011F.
    expect(contextLinkModes).toEqual({
        project: 'inertia',
        task: 'inertia',
        ticket: 'document',
    });

    render(
        <>
            <TimerContextLink kind="Project" url="/projects/1/board" label="Portal" />
            <TimerContextLink kind="task" url="/projects/1/tasks/2" label="Task" />
            <TimerContextLink kind="Ticket" url="/tickets/3" label="Ticket" />
        </>,
    );

    expect(screen.getByRole('link', { name: 'Portal' })).toHaveAttribute('data-router', 'inertia');
    expect(screen.getByRole('link', { name: 'Task' })).toHaveAttribute('data-router', 'inertia');
    expect(screen.getByRole('link', { name: 'Ticket' })).not.toHaveAttribute('data-router');
    expect(screen.getByRole('link', { name: 'Portal' })).toHaveAttribute(
        'href',
        '/projects/1/board',
    );
});

it('uses an Inertia link only for a kind that has been flipped, and matches type case-insensitively', () => {
    render(
        <>
            <TimerContextLink kind="Project" url="/projects/1/board" label="From timer" />
            <TimerContextLink kind="project" url="/projects/1/board" label="From entry" />
            <TimerContextLink kind="Ticket" url="/tickets/3" label="Ticket" />
        </>,
    );

    expect(screen.getByRole('link', { name: 'From timer' })).toHaveAttribute(
        'data-router',
        'inertia',
    );
    expect(screen.getByRole('link', { name: 'From entry' })).toHaveAttribute(
        'data-router',
        'inertia',
    );
    expect(screen.getByRole('link', { name: 'Ticket' })).not.toHaveAttribute('data-router');
});

it('renders plain text, never a link, when there is no destination', () => {
    render(<TimerContextLink kind="Task" url={null} label="Unreachable task" />);

    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(screen.getByText('Unreachable task')).toBeInTheDocument();
});

it('shows custom children while keeping the full label as the tooltip', () => {
    render(
        <TimerContextLink kind="Project" url="/projects/1/board" label="A very long project name">
            Project: A very long project name
        </TimerContextLink>,
    );

    const link = screen.getByRole('link', { name: 'Project: A very long project name' });
    expect(link).toHaveAttribute('title', 'A very long project name');
});
