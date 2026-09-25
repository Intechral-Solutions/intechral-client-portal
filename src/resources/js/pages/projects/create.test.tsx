import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { CreateProjectPage, type CreateProjectProps } from '@/pages/projects/create';
import {
    inertiaSpies,
    resetInertiaMock,
    setFormErrors,
    setFormProcessing,
    setPageProps,
    submitted,
} from '@/test/inertia';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

const HOSTILE = '</select><img src=x onerror="window.__xss=1">';

const companies = [
    { id: 11, name: 'Acme Co' },
    { id: 12, name: 'Globex' },
];
const candidates = [
    { id: 1, name: 'Ada Admin', email: 'ada@example.test' },
    { id: 2, name: 'Bea Builder', email: 'bea@example.test' },
    { id: 3, name: HOSTILE, email: 'hostile@example.test' },
];

const managerProps: CreateProjectProps = { companies, abilities: { editMembers: false } };
const adminProps: CreateProjectProps = {
    companies,
    abilities: { editMembers: true },
    memberCandidates: candidates,
};

beforeEach(() => {
    setPageProps({
        auth: { user: { id: 1, name: 'Ada Admin', email: 'ada@example.test' }, permissions: [] },
    });
});

afterEach(resetInertiaMock);

it('submits the details and selected companies to projects.store in its own error bag', async () => {
    const user = userEvent.setup();
    render(<CreateProjectPage {...managerProps} />);

    // fireEvent, not user.type, for the free-text fields: typing "New portal" character by
    // character is real interaction but needlessly slow across a whole form, and a slow CI
    // worker can then trip vitest's per-test timeout (this test proved exactly that flake).
    fireEvent.change(screen.getByLabelText(/Project name/), { target: { value: 'New portal' } });
    fireEvent.change(screen.getByLabelText('Description'), { target: { value: 'Rebuild it.' } });
    fireEvent.change(screen.getByLabelText('Start date'), { target: { value: '2026-01-05' } });
    fireEvent.change(screen.getByLabelText('Target date'), { target: { value: '2026-06-30' } });
    fireEvent.change(screen.getByLabelText('Budget ($)'), { target: { value: '1500' } });
    await user.selectOptions(screen.getByLabelText('Status'), 'on_hold');
    await user.click(screen.getByRole('checkbox', { name: 'Globex' }));
    await user.click(screen.getByRole('button', { name: 'Create project' }));

    expect(submitted()).toHaveLength(1);
    expect(submitted()[0]).toMatchObject({
        method: 'post',
        url: '/projects',
        options: { errorBag: 'createProject' },
        data: {
            name: 'New portal',
            description: 'Rebuild it.',
            start_date: '2026-01-05',
            target_date: '2026-06-30',
            status: 'on_hold',
            budget: '1500',
            companies: [12],
        },
    });
});

it('does not submit while the required name is empty', async () => {
    const user = userEvent.setup();
    render(<CreateProjectPage {...managerProps} />);

    await user.click(screen.getByRole('button', { name: 'Create project' }));

    expect(submitted()).toHaveLength(0);
});

it('gives a non-admin manager no member editor, no candidate data, and sends no members field', async () => {
    const user = userEvent.setup();
    render(<CreateProjectPage {...managerProps} />);

    expect(screen.queryByRole('button', { name: 'Add member' })).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/^Member \d+$/)).not.toBeInTheDocument();
    expect(document.body).not.toHaveTextContent('@example.test');
    expect(
        screen.getByText(/Adding other members is done by an administrator/),
    ).toBeInTheDocument();

    await user.type(screen.getByLabelText(/Project name/), 'Solo');
    await user.click(screen.getByRole('button', { name: 'Create project' }));

    expect(submitted()[0]!.data).not.toHaveProperty('members');
});

it('never renders an editor when the candidate directory is absent, even if the ability were true', () => {
    render(<CreateProjectPage companies={companies} abilities={{ editMembers: true }} />);

    expect(screen.queryByRole('button', { name: 'Add member' })).not.toBeInTheDocument();
});

it('lets an administrator add and remove member rows and sends them without client keys', async () => {
    const user = userEvent.setup();
    render(<CreateProjectPage {...adminProps} />);

    // The actor is shown locked as the owner and cannot be picked again.
    expect(screen.getByText('Owner')).toBeInTheDocument();
    await user.type(screen.getByLabelText(/Project name/), 'Team project');
    await user.click(screen.getByRole('button', { name: 'Add member' }));
    await user.click(screen.getByRole('button', { name: 'Add member' }));

    const [first, second] = screen.getAllByLabelText(/^Member \d+$/);
    expect(within(first!).queryByRole('option', { name: /Ada Admin/ })).not.toBeInTheDocument();

    await user.selectOptions(first!, '2');
    await user.selectOptions(
        screen.getByRole('combobox', { name: 'Role for member 1' }),
        'manager',
    );
    expect(within(second!).queryByRole('option', { name: /Bea Builder/ })).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Remove member 2' }));
    await user.click(screen.getByRole('button', { name: 'Create project' }));

    expect(submitted()[0]!.data.members).toEqual([{ user_id: 2, role: 'manager' }]);
});

it('renders a hostile user name as inert text', async () => {
    const user = userEvent.setup();
    render(<CreateProjectPage {...adminProps} />);

    await user.click(screen.getByRole('button', { name: 'Add member' }));
    await user.selectOptions(screen.getByLabelText('Member 1'), '3');

    expect(document.querySelector('img')).toBeNull();
    expect(screen.getByLabelText('Member 1')).toHaveDisplayValue(
        `${HOSTILE} (hostile@example.test)`,
    );
    expect((window as unknown as { __xss?: number }).__xss).toBeUndefined();
});

it('shows validation errors beside their fields and focuses the first one', async () => {
    const user = userEvent.setup();
    setFormErrors({
        name: 'The name field is required.',
        target_date: 'The target date must be after the start date.',
        'members.0.user_id': 'The selected member is invalid.',
    });
    render(<CreateProjectPage {...adminProps} />);
    await user.click(screen.getByRole('button', { name: 'Add member' }));

    expect(screen.getByLabelText(/Project name/)).toHaveAccessibleDescription(
        'The name field is required.',
    );
    expect(screen.getByLabelText('Target date')).toHaveAccessibleDescription(
        'The target date must be after the start date.',
    );
    expect(screen.getByLabelText('Member 1')).toHaveAccessibleDescription(
        'The selected member is invalid.',
    );
    expect(screen.getByLabelText('Start date')).toBeValid();

    await user.type(screen.getByLabelText(/Project name/), 'x');
    await user.click(screen.getByRole('button', { name: 'Create project' }));
    submitted()[0]!.options.onError?.({ target_date: 'bad', 'members.0.user_id': 'bad' });

    expect(screen.getByLabelText('Target date')).toHaveFocus();
});

it('focuses the first invalid member row when only members failed', async () => {
    const user = userEvent.setup();
    setFormErrors({ 'members.0.user_id': 'The selected member is invalid.' });
    render(<CreateProjectPage {...adminProps} />);
    await user.click(screen.getByRole('button', { name: 'Add member' }));
    await user.type(screen.getByLabelText(/Project name/), 'x');
    await user.click(screen.getByRole('button', { name: 'Create project' }));

    screen.getByLabelText(/Project name/).focus();
    submitted()[0]!.options.onError?.({ 'members.0.user_id': 'bad' });

    expect(screen.getByLabelText('Member 1')).toHaveFocus();
});

it('says a company link is informational and hides the section when there are no companies', () => {
    const { unmount } = render(<CreateProjectPage {...managerProps} />);
    expect(screen.getByRole('group', { name: 'Linked companies' })).toHaveAccessibleDescription(
        /does not grant that company's organization members access/,
    );
    unmount();

    render(<CreateProjectPage companies={[]} abilities={{ editMembers: false }} />);
    expect(screen.queryByRole('group', { name: 'Linked companies' })).not.toBeInTheDocument();
});

it('disables the submit button while the request is in flight', () => {
    setFormProcessing(true);
    render(<CreateProjectPage {...managerProps} />);

    expect(screen.getByRole('button', { name: 'Creating...' })).toBeDisabled();
});

it('links Cancel and Back to the projects index', () => {
    render(<CreateProjectPage {...managerProps} />);

    expect(screen.getByRole('link', { name: 'Cancel' })).toHaveAttribute('href', '/projects');
    expect(screen.getByRole('link', { name: 'Back to projects' })).toHaveAttribute(
        'href',
        '/projects',
    );
    expect(inertiaSpies.router.visit).not.toHaveBeenCalled();
});
