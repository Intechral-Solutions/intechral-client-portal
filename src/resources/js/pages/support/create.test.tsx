import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { SupportCreatePage, type SupportCreateProps } from '@/pages/support/create';
import { resetInertiaMock, setFormErrors, submitted } from '@/test/inertia';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

const charter = {
    id: 7,
    name: 'Charter College',
    contacts: [
        { id: 31, name: 'Rae Requester', email: 'rae@charter.test' },
        { id: 32, name: 'Sam Second', email: 'sam@charter.test' },
    ],
};
const empty = { id: 9, name: 'Empty Org', contacts: [] };

const props: SupportCreateProps = {
    customers: [charter],
    categories: ['General', 'Technical', 'Billing', 'Account', 'Other'],
    priorities: [
        { value: 'low', label: 'Low' },
        { value: 'medium', label: 'Medium' },
        { value: 'high', label: 'High' },
        { value: 'critical', label: 'Critical' },
    ],
    defaultPriority: 'medium',
    attachmentPolicy: { maxFiles: 10, maxSizeMb: 20, extensions: ['pdf', 'png'] },
};

function fill() {
    fireEvent.change(screen.getByLabelText(/Subject/), { target: { value: 'Cannot log in' } });
    fireEvent.change(screen.getByLabelText(/Description/), {
        target: { value: 'It says the password is wrong.' },
    });
}

it('states the one supported customer read-only and never defaults the contact', () => {
    render(<SupportCreatePage {...props} />);

    expect(screen.getByText('Charter College')).toBeInTheDocument();
    expect(screen.queryByLabelText(/Customer/)).not.toBeInTheDocument();
    expect(screen.getByLabelText(/Contact/)).toHaveValue('');
    expect(
        screen.getByRole('option', { name: 'Rae Requester (rae@charter.test)' }),
    ).toBeInTheDocument();
});

it('submits the named customer and contact and no submitter or requester field of its own', async () => {
    const user = userEvent.setup();
    render(<SupportCreatePage {...props} />);
    fill();
    await user.selectOptions(screen.getByLabelText(/Contact/), '32');
    await user.click(screen.getByRole('button', { name: 'Submit request' }));

    expect(submitted()).toHaveLength(1);
    expect(submitted()[0]).toMatchObject({
        method: 'post',
        url: '/support/requests',
        options: { forceFormData: true },
        data: { organization_id: '7', requester_id: '32', title: 'Cannot log in' },
    });
    expect(Object.keys(submitted()[0]!.data).sort()).toEqual([
        'attachments',
        'category',
        'description',
        'organization_id',
        'priority',
        'requester_id',
        'title',
    ]);
});

it('does not submit until a contact is chosen', async () => {
    const user = userEvent.setup();
    render(<SupportCreatePage {...props} />);
    fill();
    await user.click(screen.getByRole('button', { name: 'Submit request' }));

    expect(submitted()).toHaveLength(0);
});

it('requires a customer choice when there are several, and clears the contact when it changes', async () => {
    const user = userEvent.setup();
    render(<SupportCreatePage {...props} customers={[charter, empty]} />);

    expect(screen.getByLabelText(/Customer/)).toHaveValue('');
    expect(screen.getByLabelText(/Contact/)).toBeDisabled();

    await user.selectOptions(screen.getByLabelText(/Customer/), '7');
    await user.selectOptions(screen.getByLabelText(/Contact/), '31');
    expect(screen.getByLabelText(/Contact/)).toHaveValue('31');

    await user.selectOptions(screen.getByLabelText(/Customer/), '9');
    expect(screen.getByLabelText(/Contact/)).toHaveValue('');
    expect(
        screen.getByText(/Empty Org has no contact who can receive requests yet/),
    ).toBeInTheDocument();
});

it("shows the server's refusal beside the contact field, verbatim and linked to it", () => {
    setFormErrors({ requester_id: 'That contact is not available for this request.' });
    render(<SupportCreatePage {...props} />);

    const contact = screen.getByLabelText(/Contact/);
    const message = screen.getByText('That contact is not available for this request.');

    expect(contact).toHaveAttribute('aria-invalid', 'true');
    expect(contact.getAttribute('aria-describedby')).toContain(message.id);
});

it('explains instead of offering a form when no customer is supported', () => {
    render(<SupportCreatePage {...props} customers={[]} />);

    expect(
        screen.getByText('There is no customer you can raise a request for.'),
    ).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Submit request' })).not.toBeInTheDocument();
});
