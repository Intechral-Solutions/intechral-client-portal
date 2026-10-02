import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TaskCompleteAction } from '@/components/tasks/task-complete-action';
import { inertiaSpies, resetInertiaMock } from '@/test/inertia';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

function renderAction(props: Partial<Parameters<typeof TaskCompleteAction>[0]> = {}) {
    const onError = vi.fn();
    render(
        <TaskCompleteAction
            taskId={4}
            done={false}
            canComplete
            canReopen
            onError={onError}
            {...props}
        />,
    );

    return { onError };
}

it('offers Complete task for an open task the server lets the viewer complete', () => {
    renderAction();

    expect(screen.getByRole('button', { name: 'Complete task' })).toBeInTheDocument();
});

it('offers Reopen task for a done task the server lets the viewer reopen', () => {
    renderAction({ done: true });

    expect(screen.getByRole('button', { name: 'Reopen task' })).toBeInTheDocument();
});

it('renders nothing when the matching ability is missing: no control that could only answer 403', () => {
    const { container } = render(
        <TaskCompleteAction
            taskId={4}
            done={false}
            canComplete={false}
            canReopen
            onError={vi.fn()}
        />,
    );
    expect(container).toBeEmptyDOMElement();

    const { container: reopen } = render(
        <TaskCompleteAction taskId={4} done canComplete canReopen={false} onError={vi.fn()} />,
    );
    expect(reopen).toBeEmptyDOMElement();
});

it('calls the WP2 endpoint for the task and invents no state', async () => {
    const user = userEvent.setup();
    renderAction();

    await user.click(screen.getByRole('button', { name: 'Complete task' }));

    expect(inertiaSpies.router.put).toHaveBeenCalledWith(
        '/tasks/4/complete',
        {},
        expect.objectContaining({ preserveScroll: true }),
    );
});

it('reopens through its own endpoint', async () => {
    const user = userEvent.setup();
    renderAction({ done: true });

    await user.click(screen.getByRole('button', { name: 'Reopen task' }));

    expect(inertiaSpies.router.put.mock.calls[0]![0]).toBe('/tasks/4/reopen');
});

it('is aria-disabled, not disabled, while in flight, and ignores a second press', async () => {
    const user = userEvent.setup();
    renderAction();
    const button = screen.getByRole('button', { name: 'Complete task' });

    await user.click(button);
    expect(button).toHaveAttribute('aria-disabled', 'true');
    expect(button).not.toBeDisabled();

    await user.click(button);
    expect(inertiaSpies.router.put).toHaveBeenCalledTimes(1);

    const options = inertiaSpies.router.put.mock.calls[0]![2] as { onFinish: () => void };
    await import('@testing-library/react').then(({ act }) => act(() => options.onFinish()));
    expect(button).not.toHaveAttribute('aria-disabled');
});

it('reports a configuration error by its key, and clears it on success', async () => {
    const user = userEvent.setup();
    const { onError } = renderAction();

    await user.click(screen.getByRole('button', { name: 'Complete task' }));
    const options = inertiaSpies.router.put.mock.calls[0]![2] as {
        onError: (errors: Record<string, string>) => void;
        onSuccess: () => void;
    };

    options.onError({ complete: "This project's board has no single Done column." });
    expect(onError).toHaveBeenLastCalledWith("This project's board has no single Done column.");

    options.onSuccess();
    expect(onError).toHaveBeenLastCalledWith(null);
});
