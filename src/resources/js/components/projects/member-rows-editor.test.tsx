import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';

import {
    MemberRowsEditor,
    newMemberRow,
    toMemberPayload,
    type MemberRow,
} from '@/components/projects/member-rows-editor';
import type { MemberCandidate } from '@/types/projects';

const HOSTILE = '</select><img src=x onerror="window.__xss=1">';

const candidates: MemberCandidate[] = [
    { id: 1, name: 'Ada Owner', email: 'ada@example.test' },
    { id: 2, name: 'Bea Builder', email: 'bea@example.test' },
    { id: 3, name: 'Cy Coder', email: 'cy@example.test' },
    { id: 4, name: HOSTILE, email: 'hostile@example.test' },
];
const owner = { id: 1, name: 'Ada Owner' };

function Harness({
    initial = [],
    errors = {},
    onRows,
}: {
    initial?: MemberRow[];
    errors?: Record<string, string>;
    onRows?: (rows: MemberRow[]) => void;
}) {
    const [rows, setRows] = useState<MemberRow[]>(initial);

    return (
        <MemberRowsEditor
            idPrefix="test"
            owner={owner}
            rows={rows}
            onChange={(next) => {
                setRows(next);
                onRows?.(next);
            }}
            candidates={candidates}
            errors={errors}
        />
    );
}

function userSelects() {
    return screen.queryAllByLabelText(/^Member \d+$/) as HTMLSelectElement[];
}

function optionNames(select: HTMLElement) {
    return within(select)
        .getAllByRole('option')
        .map((option) => option.textContent);
}

it('shows the owner as a locked manager with no controls, and starts with no other rows', () => {
    render(<Harness />);

    expect(screen.getByText('Ada Owner')).toBeInTheDocument();
    expect(screen.getByText('Owner')).toBeInTheDocument();
    expect(screen.getByText('Manager')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Remove/ })).not.toBeInTheDocument();
    expect(userSelects()).toHaveLength(0);
    expect(screen.getByText('No other members yet.')).toBeInTheDocument();
});

it('adds a row, focuses its user select, and limits roles to member and manager', async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.click(screen.getByRole('button', { name: 'Add member' }));

    const [select] = userSelects();
    expect(select).toHaveFocus();
    expect(optionNames(screen.getByRole('combobox', { name: 'Role for member 1' }))).toEqual([
        'Member',
        'Manager',
    ]);
    expect(screen.getByRole('combobox', { name: 'Role for member 1' })).toHaveValue('member');
});

it('never offers the owner, and never offers a user already chosen in another row', async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.click(screen.getByRole('button', { name: 'Add member' }));
    await user.click(screen.getByRole('button', { name: 'Add member' }));
    const [first, second] = userSelects();

    expect(optionNames(first!).join('|')).not.toContain('Ada Owner');

    await user.selectOptions(first!, '2');

    // Row 1 keeps its own choice; row 2 no longer offers it.
    expect(optionNames(first!)).toContain('Bea Builder (bea@example.test)');
    expect(optionNames(second!)).not.toContain('Bea Builder (bea@example.test)');
    expect(optionNames(second!)).toContain('Cy Coder (cy@example.test)');
});

it('disables Add member when every candidate is already used', async () => {
    const user = userEvent.setup();
    render(
        <Harness
            initial={[
                newMemberRow({ user_id: 2 }),
                newMemberRow({ user_id: 3 }),
                newMemberRow({ user_id: 4 }),
            ]}
        />,
    );

    expect(screen.getByRole('button', { name: 'Add member' })).toBeDisabled();

    await user.click(screen.getByRole('button', { name: 'Remove Cy Coder' }));
    expect(screen.getByRole('button', { name: 'Add member' })).toBeEnabled();
});

it('keeps every neighbouring row bound to its own state when a middle row is removed', async () => {
    const user = userEvent.setup();
    render(
        <Harness
            initial={[
                newMemberRow({ user_id: 2, role: 'manager' }),
                newMemberRow({ user_id: 3, role: 'member' }),
                newMemberRow({ user_id: 4, role: 'manager' }),
            ]}
        />,
    );
    const [firstBefore, , thirdBefore] = userSelects();

    await user.click(screen.getByRole('button', { name: 'Remove Cy Coder' }));

    const [firstAfter, thirdAfter] = userSelects();
    // The very same DOM nodes: React identity followed the row, not its index.
    expect(firstAfter).toBe(firstBefore);
    expect(thirdAfter).toBe(thirdBefore);
    expect(firstAfter).toHaveValue('2');
    expect(thirdAfter).toHaveValue('4');
    expect(screen.getByRole('combobox', { name: 'Role for member 1' })).toHaveValue('manager');
    expect(screen.getByRole('combobox', { name: 'Role for member 2' })).toHaveValue('manager');
});

it('returns focus to Add member after a removal', async () => {
    const user = userEvent.setup();
    render(<Harness initial={[newMemberRow({ user_id: 2 })]} />);

    await user.click(screen.getByRole('button', { name: 'Remove Bea Builder' }));

    await waitFor(() => expect(screen.getByRole('button', { name: 'Add member' })).toHaveFocus());
});

it('renders hostile names and emails only as text, never as markup', () => {
    render(<Harness initial={[newMemberRow({ user_id: 4 })]} />);

    expect(document.querySelector('img')).toBeNull();
    expect(document.querySelectorAll('select')).toHaveLength(2); // the breakout closed nothing
    expect(userSelects()[0]).toHaveDisplayValue(`${HOSTILE} (hostile@example.test)`);
    expect(screen.getByRole('button', { name: `Remove ${HOSTILE}` })).toBeInTheDocument();
    expect((window as unknown as { __xss?: number }).__xss).toBeUndefined();
});

it('shows nested server errors beside the row they belong to', () => {
    render(
        <Harness
            initial={[newMemberRow({ user_id: 2 }), newMemberRow({ user_id: '' })]}
            errors={{
                'members.1.user_id': 'The selected member is invalid.',
                'members.0.role': 'The selected role is invalid.',
                members: 'The members field must be an array.',
            }}
        />,
    );

    const [first, second] = userSelects();
    expect(second).toHaveAccessibleDescription('The selected member is invalid.');
    expect(second).toBeInvalid();
    expect(first).toBeValid();
    expect(screen.getByRole('combobox', { name: 'Role for member 1' })).toHaveAccessibleDescription(
        'The selected role is invalid.',
    );
    expect(screen.getByText('The members field must be an array.')).toBeInTheDocument();
});

it('reports the rows to the caller and strips client-only keys from the payload', async () => {
    const user = userEvent.setup();
    const onRows = vi.fn();
    render(<Harness onRows={onRows} />);

    await user.click(screen.getByRole('button', { name: 'Add member' }));
    await user.selectOptions(userSelects()[0]!, '3');
    await user.selectOptions(
        screen.getByRole('combobox', { name: 'Role for member 1' }),
        'manager',
    );

    const rows = onRows.mock.calls.at(-1)![0] as MemberRow[];
    expect(rows).toEqual([expect.objectContaining({ user_id: 3, role: 'manager' })]);
    expect(rows[0]!.key).toEqual(expect.any(String));
    expect(toMemberPayload(rows)).toEqual([{ user_id: 3, role: 'manager' }]);
});

it('gives every new row a distinct identity', () => {
    const keys = new Set(Array.from({ length: 50 }, () => newMemberRow().key));

    expect(keys.size).toBe(50);
});
