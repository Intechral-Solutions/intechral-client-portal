import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TicketsCreatePage, type TicketsCreateProps } from '@/pages/tickets/create';
import { resetInertiaMock, setFormErrors, setFormProcessing, submitted } from '@/test/inertia';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

const props: TicketsCreateProps = {
    categories: ['General', 'Technical', 'Billing', 'Account', 'Other'],
    priorities: [
        { value: 'low', label: 'Low' },
        { value: 'medium', label: 'Medium' },
        { value: 'high', label: 'High' },
        { value: 'critical', label: 'Critical' },
    ],
    defaultPriority: 'medium',
    attachmentPolicy: { maxFiles: 10, maxSizeMb: 20, extensions: ['pdf', 'png', 'zip'] },
};

function fill() {
    fireEvent.change(screen.getByLabelText(/Subject/), { target: { value: 'Cannot log in' } });
    fireEvent.change(screen.getByLabelText(/Description/), {
        target: { value: 'It says my password is wrong.' },
    });
}

it('asks for the approved fields, each with a visible label, and offers no company', () => {
    render(<TicketsCreatePage {...props} />);

    expect(screen.getByRole('heading', { level: 1, name: 'New request' })).toBeInTheDocument();
    for (const label of [/Subject/, /Category/, /Priority/, /Description/, /Attachments/]) {
        expect(screen.getByLabelText(label)).toBeInTheDocument();
    }
    expect(screen.queryByLabelText(/compan/i)).not.toBeInTheDocument();
    expect(screen.getByText('Fields marked * are required.')).toBeInTheDocument();
    expect(screen.getByLabelText(/Subject/)).toBeRequired();
    expect(screen.getByLabelText(/Subject/)).toHaveAttribute('maxlength', '255');
});

it('defaults to the first category and to Medium, and offers Critical as before', () => {
    render(<TicketsCreatePage {...props} />);

    expect(screen.getByLabelText(/Category/)).toHaveValue('General');
    expect(screen.getByLabelText(/Priority/)).toHaveValue('medium');
    expect(screen.getByRole('option', { name: 'Critical' })).toBeInTheDocument();
});

it("states the attachment limits and types from the server's policy, tied to the field", () => {
    render(<TicketsCreatePage {...props} />);

    const input = screen.getByLabelText('Attachments');

    expect(input).toHaveAttribute('type', 'file');
    expect(input).toHaveAttribute('multiple');
    expect(input).toHaveAttribute('accept', '.pdf,.png,.zip');
    expect(
        screen.getByText(/Up to 10 files, 20 MB each\. Allowed types: pdf, png, zip\./),
    ).toHaveAttribute('id', 'create-attachments-hint');
    expect(input).toHaveAttribute(
        'aria-describedby',
        expect.stringContaining('create-attachments-hint'),
    );
});

it('submits multipart to the store route with the fields and the chosen files', async () => {
    const user = userEvent.setup();
    render(<TicketsCreatePage {...props} />);
    fill();
    await user.selectOptions(screen.getByLabelText(/Category/), 'Account');
    await user.selectOptions(screen.getByLabelText(/Priority/), 'high');
    const file = new File(['%PDF-1.4'], 'screenshot.pdf', { type: 'application/pdf' });
    await user.upload(screen.getByLabelText('Attachments'), file);
    await user.click(screen.getByRole('button', { name: 'Submit request' }));

    expect(submitted()).toHaveLength(1);
    expect(submitted()[0]).toMatchObject({
        method: 'post',
        url: '/tickets',
        options: { forceFormData: true },
        data: {
            title: 'Cannot log in',
            category: 'Account',
            priority: 'high',
            description: 'It says my password is wrong.',
            attachments: [file],
        },
    });
    expect(Object.keys(submitted()[0]!.data).sort()).toEqual([
        'attachments',
        'category',
        'description',
        'priority',
        'title',
    ]);
});

it('does not submit while a required field is empty', async () => {
    const user = userEvent.setup();
    render(<TicketsCreatePage {...props} />);

    await user.click(screen.getByRole('button', { name: 'Submit request' }));

    expect(submitted()).toHaveLength(0);
});

it('disables the button and says so while the request is in flight', () => {
    setFormProcessing(true);
    render(<TicketsCreatePage {...props} />);

    expect(screen.getByRole('button', { name: 'Submitting…' })).toBeDisabled();
});

it("shows the server's messages under their fields, verbatim and linked to them", () => {
    setFormErrors({
        title: 'The title field is required.',
        priority: 'The selected priority is invalid.',
    });
    render(<TicketsCreatePage {...props} />);

    const title = screen.getByLabelText(/Subject/);

    expect(title).toHaveAttribute('aria-invalid', 'true');
    expect(title).toHaveAttribute('aria-describedby', 'create-title-error');
    expect(screen.getByText('The title field is required.')).toHaveAttribute(
        'id',
        'create-title-error',
    );
    expect(screen.getByLabelText(/Priority/)).toHaveAttribute(
        'aria-describedby',
        'create-priority-error',
    );
    expect(screen.getByLabelText(/Category/)).not.toHaveAttribute('aria-describedby');
});

it('names the offending file in an attachment error, for the list and per file', async () => {
    setFormErrors({
        'attachments.0': 'This file type cannot be attached. Allowed types: pdf.',
        attachments: 'The attachments field must not have more than 10 items.',
    });
    // The picker's `accept` list only narrows the dialog; the server is the check, so the test hands over a file the list would hide.
    const user = userEvent.setup({ applyAccept: false });
    render(<TicketsCreatePage {...props} />);
    await user.upload(screen.getByLabelText('Attachments'), new File(['MZ'], 'setup.exe'));

    const alert = screen.getByRole('alert');

    expect(alert).toHaveTextContent('The attachments field must not have more than 10 items.');
    expect(alert).toHaveTextContent(
        'setup.exe: This file type cannot be attached. Allowed types: pdf.',
    );
    expect(screen.getByLabelText('Attachments')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByLabelText('Attachments').getAttribute('aria-describedby')).toContain(
        'create-attachments-error',
    );
});

it('moves focus to the first invalid field when the server refuses the submit', async () => {
    const user = userEvent.setup();
    render(<TicketsCreatePage {...props} />);
    fill();
    await user.click(screen.getByRole('button', { name: 'Submit request' }));

    submitted()[0]!.options.onError!({
        description: 'The description field is required.',
        category: 'The selected category is invalid.',
    });

    expect(screen.getByLabelText(/Category/)).toHaveFocus();
});

it('focuses the attachments field when only a file is refused', async () => {
    const user = userEvent.setup();
    render(<TicketsCreatePage {...props} />);
    fill();
    await user.click(screen.getByRole('button', { name: 'Submit request' }));

    submitted()[0]!.options.onError!({ 'attachments.0': 'The file failed to upload.' });

    expect(screen.getByLabelText('Attachments')).toHaveFocus();
});

it('links Cancel back to the list', () => {
    render(<TicketsCreatePage {...props} />);

    expect(screen.getByRole('link', { name: 'Cancel' })).toHaveAttribute('href', '/tickets');
});
