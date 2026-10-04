import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { EditProjectPage, type EditProjectProps } from '@/pages/projects/edit';
import {
    inertiaSpies,
    resetInertiaMock,
    setFormErrors,
    setFormProcessing,
    submitted,
} from '@/test/inertia';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

const HOSTILE = '</select><img src=x onerror="window.__xss=1">';

const project = {
    id: 7,
    name: 'Portal rebuild',
    description: 'Move the portal to React.',
    startDate: '2026-01-05',
    targetDate: '2026-06-30',
    status: 'active' as const,
    budget: '1200.50',
};
const members = [
    { id: 1, name: 'Ada Owner', role: 'manager' as const, isOwner: true },
    { id: 2, name: 'Bea Builder', role: 'member' as const, isOwner: false },
    { id: 3, name: HOSTILE, role: 'manager' as const, isOwner: false },
];
const companies = [
    { id: 11, name: 'Acme Co' },
    { id: 12, name: 'Globex' },
];
const candidates = [
    { id: 1, name: 'Ada Owner', email: 'ada@example.test' },
    { id: 2, name: 'Bea Builder', email: 'bea@example.test' },
    { id: 3, name: HOSTILE, email: 'hostile@example.test' },
    { id: 4, name: 'Cy Coder', email: 'cy@example.test' },
];

const managerProps: EditProjectProps = {
    project,
    members,
    companies,
    linkedCompanyIds: [11],
    abilities: { delete: true, editMembers: false },
};
const adminProps: EditProjectProps = {
    ...managerProps,
    abilities: { delete: true, editMembers: true },
    memberCandidates: candidates,
};

function section(name: string) {
    return screen.getByRole('heading', { level: 2, name }).closest('section') as HTMLElement;
}

it('prefills the details form from the project, keeping the budget and dates as the server strings', () => {
    render(<EditProjectPage {...managerProps} />);

    expect(screen.getByLabelText(/Project name/)).toHaveValue('Portal rebuild');
    expect(screen.getByLabelText('Description')).toHaveValue('Move the portal to React.');
    expect(screen.getByLabelText('Start date')).toHaveValue('2026-01-05');
    expect(screen.getByLabelText('Target date')).toHaveValue('2026-06-30');
    expect(screen.getByLabelText('Status')).toHaveValue('active');
    expect(screen.getByLabelText('Budget ($)')).toHaveValue(1200.5);
    expect((screen.getByLabelText('Budget ($)') as HTMLInputElement).value).toBe('1200.50');
    // EPIC-015 §11.3: the back link is a generic project link, so it opens the Overview.
    expect(screen.getByRole('link', { name: 'Back to project' })).toHaveAttribute(
        'href',
        '/projects/7',
    );
});

it('saves the details on their own: one PUT to projects.update in the updateProject bag', async () => {
    const user = userEvent.setup();
    render(<EditProjectPage {...managerProps} />);

    await user.clear(screen.getByLabelText(/Project name/));
    await user.type(screen.getByLabelText(/Project name/), 'Renamed');
    await user.click(
        within(section('Project details')).getByRole('button', { name: 'Save changes' }),
    );

    expect(submitted()).toHaveLength(1);
    expect(submitted()[0]).toMatchObject({
        method: 'put',
        url: '/projects/7',
        options: { errorBag: 'updateProject', preserveScroll: true },
        data: { name: 'Renamed', budget: '1200.50', target_date: '2026-06-30', status: 'active' },
    });
    // Nothing that belongs to the other sections rides along.
    expect(submitted()[0]!.data).not.toHaveProperty('companies');
    expect(submitted()[0]!.data).not.toHaveProperty('members');
});

it('saves the linked companies on their own, including an empty selection', async () => {
    const user = userEvent.setup();
    render(<EditProjectPage {...managerProps} />);
    const companiesSection = section('Linked companies');

    expect(within(companiesSection).getByRole('checkbox', { name: 'Acme Co' })).toBeChecked();
    await user.click(within(companiesSection).getByRole('checkbox', { name: 'Globex' }));
    await user.click(within(companiesSection).getByRole('button', { name: 'Update companies' }));
    expect(submitted()[0]).toMatchObject({
        method: 'put',
        url: '/projects/7/companies',
        options: { errorBag: 'syncCompanies', preserveScroll: true },
        data: { companies: [11, 12] },
    });

    await user.click(within(companiesSection).getByRole('checkbox', { name: 'Acme Co' }));
    await user.click(within(companiesSection).getByRole('checkbox', { name: 'Globex' }));
    await user.click(within(companiesSection).getByRole('button', { name: 'Update companies' }));
    expect(submitted()[1]!.data).toEqual({ companies: [] });
});

it('hides the companies section when the actor has no companies to link', () => {
    render(<EditProjectPage {...managerProps} companies={[]} linkedCompanyIds={[]} />);

    expect(screen.queryByRole('heading', { name: 'Linked companies' })).not.toBeInTheDocument();
});

it('uses a distinct error bag and a distinct endpoint for every independent form', async () => {
    const user = userEvent.setup();
    render(<EditProjectPage {...adminProps} />);

    await user.click(
        within(section('Project details')).getByRole('button', { name: 'Save changes' }),
    );
    await user.click(
        within(section('Linked companies')).getByRole('button', { name: 'Update companies' }),
    );
    await user.click(within(section('Members')).getByRole('button', { name: 'Update members' }));
    await user.click(screen.getByRole('button', { name: 'Delete project' }));
    await user.click(
        within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete project' }),
    );

    const bags = submitted().map((entry) => entry.options.errorBag);
    expect(bags).toEqual(['updateProject', 'syncCompanies', 'syncMembers', 'deleteProject']);
    expect(new Set(submitted().map((entry) => `${entry.method} ${entry.url}`)).size).toBe(4);
});

it('shows each error only in the form it belongs to', () => {
    setFormErrors({
        name: 'The name field is required.',
        companies: 'The companies field must be present.',
        'members.0.user_id': 'The selected member is invalid.',
    });
    render(<EditProjectPage {...adminProps} />);

    const details = section('Project details');
    const companiesSection = section('Linked companies');
    const membersSection = section('Members');

    expect(within(details).getByText('The name field is required.')).toBeInTheDocument();
    expect(within(details).queryByText(/companies field/)).not.toBeInTheDocument();
    expect(within(details).queryByText(/selected member/)).not.toBeInTheDocument();

    expect(
        within(companiesSection).getByText('The companies field must be present.'),
    ).toBeInTheDocument();
    expect(within(companiesSection).queryByText(/name field/)).not.toBeInTheDocument();

    expect(within(membersSection).getByLabelText('Member 1')).toHaveAccessibleDescription(
        'The selected member is invalid.',
    );
    expect(within(membersSection).queryByText(/name field/)).not.toBeInTheDocument();
});

it('focuses the first invalid details field after a failed save', async () => {
    const user = userEvent.setup();
    render(<EditProjectPage {...managerProps} />);
    await user.click(
        within(section('Project details')).getByRole('button', { name: 'Save changes' }),
    );

    submitted()[0]!.options.onError?.({ budget: 'bad', target_date: 'bad' });

    expect(screen.getByLabelText('Target date')).toHaveFocus();
});

it('keeps in-progress edits in other sections when one section is saved', async () => {
    const user = userEvent.setup();
    render(<EditProjectPage {...adminProps} />);

    await user.clear(screen.getByLabelText(/Project name/));
    await user.type(screen.getByLabelText(/Project name/), 'Half-typed rename');
    await user.click(within(section('Linked companies')).getByRole('checkbox', { name: 'Globex' }));
    await user.click(
        within(section('Linked companies')).getByRole('button', { name: 'Update companies' }),
    );

    expect(screen.getByLabelText(/Project name/)).toHaveValue('Half-typed rename');
});

// ── Membership (D7-B) ────────────────────────────────────────────────────────

it('lets an administrator edit membership with the owner locked and existing members prefilled', async () => {
    const user = userEvent.setup();
    render(<EditProjectPage {...adminProps} />);
    const membersSection = section('Members');

    expect(within(membersSection).getByText('Ada Owner')).toBeInTheDocument();
    expect(within(membersSection).getByText('Owner')).toBeInTheDocument();
    expect(
        within(membersSection).queryByRole('button', { name: /Remove Ada Owner/ }),
    ).not.toBeInTheDocument();
    expect(within(membersSection).getByLabelText('Member 1')).toHaveValue('2');
    expect(within(membersSection).getByLabelText('Member 2')).toHaveValue('3');
    expect(within(membersSection).getByRole('combobox', { name: 'Role for member 2' })).toHaveValue(
        'manager',
    );

    // Give Bea the manager role, drop the hostile-named member, add Cy.
    await user.selectOptions(
        within(membersSection).getByRole('combobox', { name: 'Role for member 1' }),
        'manager',
    );
    await user.click(within(membersSection).getByRole('button', { name: `Remove ${HOSTILE}` }));
    await user.click(within(membersSection).getByRole('button', { name: 'Add member' }));
    await user.selectOptions(within(membersSection).getByLabelText('Member 2'), '4');
    await user.click(within(membersSection).getByRole('button', { name: 'Update members' }));

    const submission = submitted().at(-1)!;
    expect(submission).toMatchObject({
        method: 'put',
        url: '/projects/7/members',
        options: { errorBag: 'syncMembers', preserveScroll: true },
    });
    // No owner, no client keys, exactly the two edited rows in order.
    expect(submission.data).toEqual({
        members: [
            { user_id: 2, role: 'manager' },
            { user_id: 4, role: 'member' },
        ],
    });
});

it('focuses the first invalid member row after a failed membership save', async () => {
    const user = userEvent.setup();
    render(<EditProjectPage {...adminProps} />);
    await user.click(within(section('Members')).getByRole('button', { name: 'Update members' }));

    submitted()[0]!.options.onError?.({ 'members.1.role': 'bad' });

    expect(screen.getByRole('combobox', { name: 'Role for member 2' })).toHaveFocus();
});

it('gives a non-admin manager a read-only member list, with no editor, candidates or emails', () => {
    render(<EditProjectPage {...managerProps} />);
    const membersSection = section('Members');

    expect(
        within(membersSection).getByText('Project membership is managed by an administrator.'),
    ).toBeInTheDocument();
    const items = within(membersSection).getAllByRole('listitem');
    expect(items).toHaveLength(3);
    expect(items[0]).toHaveTextContent('Ada Owner');
    expect(items[0]).toHaveTextContent('Manager');
    expect(items[0]).toHaveTextContent('Owner');
    expect(items[1]).toHaveTextContent('Bea Builder');
    expect(items[1]).toHaveTextContent('Member');

    expect(within(membersSection).queryByRole('button')).not.toBeInTheDocument();
    expect(within(membersSection).queryByRole('combobox')).not.toBeInTheDocument();
    expect(within(membersSection).queryByRole('form')).not.toBeInTheDocument();
    expect(document.body).not.toHaveTextContent('@example.test');
    expect(document.querySelector('form[action*="members"]')).toBeNull();
});

it('does not render the editor for a non-admin even when a candidate directory were somehow supplied', () => {
    render(
        <EditProjectPage
            {...managerProps}
            abilities={{ delete: true, editMembers: false }}
            memberCandidates={candidates}
        />,
    );

    expect(screen.queryByRole('button', { name: 'Add member' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Update members' })).not.toBeInTheDocument();
    expect(document.body).not.toHaveTextContent('@example.test');
});

it('renders hostile member names as inert text in both the list and the editor', () => {
    const { unmount } = render(<EditProjectPage {...managerProps} />);
    expect(screen.getByText(HOSTILE)).toBeInTheDocument();
    expect(document.querySelector('img')).toBeNull();
    unmount();

    render(<EditProjectPage {...adminProps} />);
    expect(screen.getByLabelText('Member 2')).toHaveDisplayValue(
        `${HOSTILE} (hostile@example.test)`,
    );
    expect(document.querySelector('img')).toBeNull();
    expect((window as unknown as { __xss?: number }).__xss).toBeUndefined();
});

// ── Delete ───────────────────────────────────────────────────────────────────

it('asks for confirmation before deleting and does nothing until it is given', async () => {
    const user = userEvent.setup();
    render(<EditProjectPage {...managerProps} />);

    await user.click(screen.getByRole('button', { name: 'Delete project' }));

    const dialog = screen.getByRole('dialog', { name: 'Delete this project?' });
    expect(dialog).toHaveAccessibleDescription(/Portal rebuild.*cannot be undone/);
    expect(submitted()).toHaveLength(0);

    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(submitted()).toHaveLength(0);
});

it('deletes through projects.destroy without removing anything locally', async () => {
    const user = userEvent.setup();
    render(<EditProjectPage {...managerProps} />);

    await user.click(screen.getByRole('button', { name: 'Delete project' }));
    await user.click(
        within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete project' }),
    );

    expect(submitted()).toEqual([
        expect.objectContaining({
            method: 'delete',
            url: '/projects/7',
            options: expect.objectContaining({ errorBag: 'deleteProject' }),
        }),
    ]);
    // The page and dialog stay until the server answers (a redirect, or an error).
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByLabelText(/Project name/)).toHaveValue('Portal rebuild');
});

it('shows the recorded-time refusal inside the dialog and keeps the project', async () => {
    const user = userEvent.setup();
    setFormErrors({ delete: 'This project has recorded time and cannot be deleted.' });
    render(<EditProjectPage {...managerProps} />);

    await user.click(screen.getByRole('button', { name: 'Delete project' }));

    expect(within(screen.getByRole('dialog')).getByRole('alert')).toHaveTextContent(
        'This project has recorded time and cannot be deleted.',
    );
    // The danger-zone copy already names the alternative.
    expect(screen.getByText(/set its status to Archived instead/)).toBeInTheDocument();
    expect(screen.getByLabelText(/Project name/)).toHaveValue('Portal rebuild');
});

it('clears the refusal when the dialog is dismissed', async () => {
    const user = userEvent.setup();
    setFormErrors({ delete: 'This project has recorded time and cannot be deleted.' });
    render(<EditProjectPage {...managerProps} />);

    await user.click(screen.getByRole('button', { name: 'Delete project' }));
    await user.keyboard('{Escape}');
    expect(inertiaSpies.form.clearErrors).toHaveBeenCalled();

    await user.click(screen.getByRole('button', { name: 'Delete project' }));
    expect(within(screen.getByRole('dialog')).queryByRole('alert')).not.toBeInTheDocument();
});

it('disables dialog actions while the delete is in flight', async () => {
    const user = userEvent.setup();
    setFormProcessing(true);
    render(<EditProjectPage {...managerProps} />);

    await user.click(screen.getByRole('button', { name: 'Delete project' }));

    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByRole('button', { name: 'Cancel' })).toBeDisabled();
    expect(within(dialog).getByRole('button', { name: 'Working...' })).toBeDisabled();
});

it('offers no danger zone to an actor without the delete ability', () => {
    render(<EditProjectPage {...managerProps} abilities={{ delete: false, editMembers: false }} />);

    expect(screen.queryByRole('heading', { name: 'Danger zone' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Delete project' })).not.toBeInTheDocument();
});

it('never nests one form inside another', () => {
    render(<EditProjectPage {...adminProps} />);

    expect(document.querySelectorAll('form form')).toHaveLength(0);
    expect(document.querySelectorAll('form')).toHaveLength(3); // details, companies, members
});
