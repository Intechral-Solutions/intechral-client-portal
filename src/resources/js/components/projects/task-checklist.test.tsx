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

// ── WP10 focus/labelling regressions ────────────────────────────────────────
// Every control here is disabled while its own request is in flight. Restoring focus from the
// request's own `onFinish` ran before React had committed the re-enable, so `.focus()` on a still
// disabled element was silently dropped and focus was stranded on <body> — reproduced in a real
// browser during the WP10 walkthrough, for both the add field and the toggled checkbox.

it('gives the add field a real accessible name, not just a placeholder', () => {
    renderChecklist({ canAuthor: true });

    expect(screen.getByRole('textbox', { name: 'Add a checklist item' })).toBe(
        screen.getByPlaceholderText('Add an item…'),
    );
});

it('returns focus to the toggled checkbox once it is re-enabled', async () => {
    const user = userEvent.setup();
    renderChecklist();

    const first = screen.getByRole('checkbox', { name: 'Write tests' });
    first.focus();
    await user.click(first);

    expect(first).toBeDisabled();
    // jsdom keeps focus on an element that becomes `disabled` (and ignores blur() on one); every
    // real browser moves focus to <body>, which is what stranded it before this fix and why only
    // the WP10 browser walkthrough caught it. Move focus away explicitly so this test exercises
    // the restore path instead of passing on jsdom's more forgiving behaviour.
    screen.getByRole('checkbox', { name: 'Ship it' }).focus();
    expect(first).not.toHaveFocus();

    optimisticSubmitted()[0]!.options.onFinish?.();

    await waitFor(() => expect(first).toBeEnabled());
    await waitFor(() => expect(first).toHaveFocus());
});

it('returns focus to the add field once the add finishes', async () => {
    const user = userEvent.setup();
    let finish: (() => void) | undefined;
    inertiaSpies.router.post.mockImplementation((_url, _data, options) => {
        finish = () => (options as { onFinish?: () => void }).onFinish?.();
    });
    renderChecklist({ canAuthor: true });

    const field = screen.getByPlaceholderText('Add an item…');
    await user.type(field, 'Review PR');
    await user.click(screen.getByRole('button', { name: 'Add' }));

    expect(field).toBeDisabled();
    // See the note above: real browsers move focus off a control that becomes disabled.
    screen.getByRole('checkbox', { name: 'Ship it' }).focus();
    expect(field).not.toHaveFocus();
    finish!();

    await waitFor(() => expect(field).toBeEnabled());
    await waitFor(() => expect(field).toHaveFocus());
});

it('moves focus to the neighbouring item after a remove, and to the add field for the last one', async () => {
    const user = userEvent.setup();
    let success: (() => void) | undefined;
    inertiaSpies.router.delete.mockImplementation((_url, options) => {
        success = () => {
            (options as { onSuccess?: () => void }).onSuccess?.();
            (options as { onFinish?: () => void }).onFinish?.();
        };
    });
    const { rerender } = renderChecklist({ canAuthor: true });

    await user.click(screen.getByRole('button', { name: 'Remove "Write tests"' }));
    success!();
    // The server's authoritative checklist prop arrives without the removed row.
    rerender(<TaskChecklist projectId={7} taskId={1} items={[items[1]!]} canAuthor={true} />);

    await waitFor(() => expect(screen.getByRole('checkbox', { name: 'Ship it' })).toHaveFocus());
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
