import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TaskChecklist } from '@/components/projects/task-checklist';
import { inertiaSpies, optimisticSubmitted, resetInertiaMock } from '@/test/inertia';
import type { TaskChecklistItemData } from '@/types/projects';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

const items: TaskChecklistItemData[] = [
    { id: 1, title: 'Write tests', completed: false },
    { id: 2, title: 'Ship it', completed: true },
];

function renderChecklist(overrides: Partial<React.ComponentProps<typeof TaskChecklist>> = {}) {
    return render(
        <TaskChecklist projectId={7} taskId={1} items={items} canAuthor={false} {...overrides} />,
    );
}

it('renders native checkboxes with real labels and progress', () => {
    renderChecklist();

    const first = screen.getByRole('checkbox', { name: 'Write tests' });
    expect(first).not.toBeChecked();
    expect(screen.getByRole('checkbox', { name: 'Ship it' })).toBeChecked();
    expect(screen.getByText('1 of 2 complete')).toBeInTheDocument();
    expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '1');
});

it('lets a member toggle but shows no add/remove controls', () => {
    renderChecklist({ canAuthor: false });

    expect(screen.getByRole('checkbox', { name: 'Write tests' })).toBeEnabled();
    expect(screen.queryByRole('button', { name: /Remove/ })).not.toBeInTheDocument();
    expect(screen.queryByPlaceholderText('Add an item…')).not.toBeInTheDocument();
});

it('shows add and remove controls only for a manager (D5)', () => {
    renderChecklist({ canAuthor: true });

    expect(screen.getByRole('button', { name: 'Remove "Write tests"' })).toBeInTheDocument();
    expect(screen.getByPlaceholderText('Add an item…')).toBeInTheDocument();
});

it('toggles optimistically, then reconciles against the checklist prop', async () => {
    const user = userEvent.setup();
    renderChecklist();

    await user.click(screen.getByRole('checkbox', { name: 'Write tests' }));

    expect(inertiaSpies.router.optimistic).toHaveBeenCalledTimes(1);
    const submission = optimisticSubmitted()[0]!;
    expect(submission.method).toBe('put');
    expect(submission.url).toBe('/projects/7/tasks/1/checklist/1/toggle');
    expect(submission.data).toEqual({ completed: true });
    expect(submission.options).toMatchObject({ only: ['checklist'], preserveScroll: true });

    // The transform flips only the toggled item.
    const next = submission.transform({ checklist: items }) as {
        checklist: TaskChecklistItemData[];
    };
    expect(next.checklist.find((item) => item.id === 1)?.completed).toBe(true);
    expect(next.checklist.find((item) => item.id === 2)?.completed).toBe(true);
});

it('disables a checkbox while its own toggle is pending, but not the others', async () => {
    const user = userEvent.setup();
    renderChecklist();

    const first = screen.getByRole('checkbox', { name: 'Write tests' });
    await user.click(first);

    expect(first).toBeDisabled();
    expect(screen.getByRole('checkbox', { name: 'Ship it' })).toBeEnabled();
});

it('re-enables the checkbox once the toggle finishes', async () => {
    const user = userEvent.setup();
    renderChecklist();

    const first = screen.getByRole('checkbox', { name: 'Write tests' });
    await user.click(first);
    const submission = optimisticSubmitted()[0]!;
    submission.options.onFinish?.();

    await waitFor(() => expect(first).toBeEnabled());
});

it('adds an item through the server-confirmed form and keeps focus on it', async () => {
    const user = userEvent.setup();
    renderChecklist({ canAuthor: true });

    await user.type(screen.getByPlaceholderText('Add an item…'), 'Review PR');
    await user.click(screen.getByRole('button', { name: 'Add' }));

    expect(inertiaSpies.router.post).toHaveBeenCalledWith(
        '/projects/7/tasks/1/checklist',
        { title: 'Review PR' },
        expect.objectContaining({ only: ['checklist', 'flash'] }),
    );
});

it('shows a validation error inline when adding fails', async () => {
    const user = userEvent.setup();
    inertiaSpies.router.post.mockImplementation((_url, _data, options) => {
        (options as { onError?: (errors: Record<string, string>) => void }).onError?.({
            title: 'A task can have at most 100 checklist items.',
        });
    });
    renderChecklist({ canAuthor: true });

    await user.type(screen.getByPlaceholderText('Add an item…'), 'One too many');
    await user.click(screen.getByRole('button', { name: 'Add' }));

    expect(
        await screen.findByText('A task can have at most 100 checklist items.'),
    ).toBeInTheDocument();
});

it('removes an item through a server-confirmed delete', async () => {
    const user = userEvent.setup();
    renderChecklist({ canAuthor: true });

    await user.click(screen.getByRole('button', { name: 'Remove "Write tests"' }));

    expect(inertiaSpies.router.delete).toHaveBeenCalledWith(
        '/projects/7/tasks/1/checklist/1',
        expect.objectContaining({ only: ['checklist', 'flash'] }),
    );
});

it('shows an empty state with the add input for a manager when there are no items', () => {
    renderChecklist({ items: [], canAuthor: true });

    expect(screen.getByPlaceholderText('Add an item…')).toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
});

it('shows a plain empty state for a non-manager when there are no items', () => {
    renderChecklist({ items: [], canAuthor: false });

    expect(screen.getByText('No checklist items.')).toBeInTheDocument();
});
