import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TaskAssigneeMenu } from '@/components/tasks/task-assignee-menu';
import { chooseRadio, openMenu } from '@/test/menu';

const choices = [
    { id: 1, label: 'Me' },
    { id: 2, label: 'Max Member' },
];

function renderMenu(overrides: Partial<Parameters<typeof TaskAssigneeMenu>[0]> = {}) {
    const onAssign = vi.fn();
    const utils = render(
        <TaskAssigneeMenu
            taskTitle="Pack the van"
            assignee={null}
            choices={choices}
            pending={false}
            onAssign={onAssign}
            {...overrides}
        />,
    );

    return { onAssign, ...utils };
}

it('names the control by who holds the task and which task it changes', () => {
    renderMenu({ assignee: { id: 2, name: 'Max Member' } });

    expect(
        screen.getByRole('button', {
            name: 'Assignee: Max Member. Change assignee of “Pack the van”',
        }),
    ).toBeInTheDocument();
});

it('says Unassigned when nobody holds the task', () => {
    renderMenu();

    expect(
        screen.getByRole('button', {
            name: 'Assignee: Unassigned. Change assignee of “Pack the van”',
        }),
    ).toBeInTheDocument();
});

it('opens a radio menu with Unassigned first and the current holder checked', async () => {
    const user = userEvent.setup();
    renderMenu({ assignee: { id: 2, name: 'Max Member' } });

    await openMenu(user, screen.getByRole('button', { name: /Change assignee/ }));

    const menu = await screen.findByRole('menu');
    const items = within(menu).getAllByRole('menuitemradio');
    expect(items.map((item) => item.textContent)).toEqual(['Unassigned', 'Me', 'Max Member']);
    expect(items[2]).toHaveAttribute('aria-checked', 'true');
    expect(items[0]).toHaveAttribute('aria-checked', 'false');
});

it('assigns the chosen person', async () => {
    const user = userEvent.setup();
    const { onAssign } = renderMenu();

    await openMenu(user, screen.getByRole('button', { name: /Change assignee/ }));
    await chooseRadio(user, 'Me');

    expect(onAssign).toHaveBeenCalledExactlyOnceWith(1);
});

it('releases the task when Unassigned is chosen', async () => {
    const user = userEvent.setup();
    const { onAssign } = renderMenu({ assignee: { id: 1, name: 'Dana Webb' } });

    await openMenu(user, screen.getByRole('button', { name: /Change assignee/ }));
    await chooseRadio(user, 'Unassigned');

    expect(onAssign).toHaveBeenCalledExactlyOnceWith(null);
});

it('sends nothing when the current value is chosen again', async () => {
    const user = userEvent.setup();
    const { onAssign } = renderMenu({ assignee: { id: 2, name: 'Max Member' } });

    await openMenu(user, screen.getByRole('button', { name: /Change assignee/ }));
    await chooseRadio(user, 'Max Member');

    expect(onAssign).not.toHaveBeenCalled();
});

it('is keyboard operable and hands focus back to the trigger when it closes', async () => {
    const user = userEvent.setup();
    const { onAssign } = renderMenu();
    const trigger = screen.getByRole('button', { name: /Change assignee/ });

    trigger.focus();
    await user.keyboard('{Enter}');
    await screen.findByRole('menu');
    await user.keyboard('{ArrowDown}{Enter}'); // Unassigned is focused first, then Me

    expect(onAssign).toHaveBeenCalledExactlyOnceWith(1);
    expect(trigger).toHaveFocus();
});

it('stays a tab stop but refuses to open while a request is in flight', async () => {
    const user = userEvent.setup();
    renderMenu({ pending: true });
    const trigger = screen.getByRole('button', { name: /Change assignee/ });

    expect(trigger).toHaveAttribute('aria-disabled', 'true');
    expect(trigger).toHaveAttribute('aria-busy', 'true');
    expect(trigger).not.toBeDisabled();

    await user.click(trigger);
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
});

describe('keyboard while a request is in flight', () => {
    function press(trigger: HTMLElement, init: KeyboardEventInit) {
        const event = new KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init });
        trigger.dispatchEvent(event);

        return event;
    }

    it.each(['Enter', ' ', 'ArrowDown', 'ArrowUp'])(
        'holds back %j, which would open the menu',
        (key) => {
            renderMenu({ pending: true });
            const trigger = screen.getByRole('button', { name: /Change assignee/ });
            trigger.focus();

            expect(press(trigger, { key }).defaultPrevented).toBe(true);
            expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        },
    );

    it.each([
        ['Tab', {}],
        ['Shift+Tab', { shiftKey: true }],
        ['Escape', {}],
        ['j', {}],
        ['x', {}],
    ])('does not prevent %s', (name, init) => {
        renderMenu({ pending: true });
        const trigger = screen.getByRole('button', { name: /Change assignee/ });
        trigger.focus();

        const key = name === 'Shift+Tab' ? 'Tab' : name;
        expect(press(trigger, { key, ...init }).defaultPrevented).toBe(false);
    });

    it('lets focus leave the control with Tab', async () => {
        const user = userEvent.setup();
        render(
            <>
                <TaskAssigneeMenu
                    taskTitle="Pack the van"
                    assignee={null}
                    choices={choices}
                    pending
                    onAssign={vi.fn()}
                />
                <button type="button">Next</button>
            </>,
        );
        screen.getByRole('button', { name: /Change assignee/ }).focus();

        await user.tab();

        expect(screen.getByRole('button', { name: 'Next' })).toHaveFocus();
    });

    it('opens normally once the request has finished', async () => {
        const user = userEvent.setup();
        renderMenu({ pending: false });

        expect(
            await openMenu(user, screen.getByRole('button', { name: /Change assignee/ })),
        ).toBeVisible();
    });
});

it('keeps a departed holder as a labelled, checked current value that is not a new choice', async () => {
    const user = userEvent.setup();
    const { onAssign } = renderMenu({
        assignee: { id: 99, name: 'Xi Departed' },
        choices: [
            ...choices,
            { id: 99, label: 'Xi Departed (no longer a project member)', current: true },
        ],
    });

    await openMenu(user, screen.getByRole('button', { name: /Change assignee/ }));
    const departed = await screen.findByRole('menuitemradio', {
        name: 'Xi Departed (no longer a project member)',
    });
    expect(departed).toHaveAttribute('aria-checked', 'true');

    departed.focus();
    await user.keyboard('{Enter}');
    expect(onAssign).not.toHaveBeenCalled();
});

it('shows the name beside the mark, which is decorative, not announced twice', () => {
    renderMenu({ assignee: { id: 2, name: 'Max Member' } });
    const trigger = screen.getByRole('button', { name: /Change assignee/ });

    expect(within(trigger).getByText('Max Member')).toBeInTheDocument();
    expect(within(trigger).queryByRole('img')).not.toBeInTheDocument();
});

it('shows the name beside the mark by default, so a detail page reads who holds the task at every width', () => {
    renderMenu({ assignee: { id: 2, name: 'Max Member' } });

    expect(screen.getByText('Max Member')).not.toHaveClass('max-md:sr-only');
});

it('draws the mark alone at S when asked (a list row, D9), the name staying for assistive technology', () => {
    renderMenu({ assignee: { id: 2, name: 'Max Member' }, compactAtSmall: true });

    expect(screen.getByText('Max Member')).toHaveClass('max-md:sr-only');
    expect(screen.getByRole('button', { name: /Assignee: Max Member/ })).toBeInTheDocument();
});

it('marks the current holder with a visible check as well as the checked state', async () => {
    const user = userEvent.setup();
    renderMenu({ assignee: { id: 2, name: 'Max Member' } });

    await openMenu(user, screen.getByRole('button', { name: /Change assignee/ }));
    const checked = await screen.findByRole('menuitemradio', { name: 'Max Member' });
    const unchecked = screen.getByRole('menuitemradio', { name: 'Me' });

    expect(checked.querySelector('svg')).not.toBeNull();
    expect(unchecked.querySelector('svg')).toBeNull();
});
