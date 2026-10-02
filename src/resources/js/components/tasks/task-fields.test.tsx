import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import {
    DescriptionField,
    DueDateField,
    PriorityField,
    TitleField,
} from '@/components/tasks/task-fields';

const priorities = [
    { value: 'low' as const, label: 'Low' },
    { value: 'high' as const, label: 'High' },
];

it('labels each shared field and wires its error to it', () => {
    render(
        <>
            <TitleField id="t" value="x" onChange={vi.fn()} error="The title field is required." />
            <PriorityField
                id="p"
                value="low"
                onChange={vi.fn()}
                options={priorities}
                error="Bad."
            />
            <DueDateField id="d" value="" onChange={vi.fn()} error="Not a date." />
            <DescriptionField id="n" value="" onChange={vi.fn()} error="Too long." />
        </>,
    );

    for (const [label, message] of [
        [/Title/, 'The title field is required.'],
        ['Priority', 'Bad.'],
        ['Due date', 'Not a date.'],
        ['Description', 'Too long.'],
    ] as const) {
        const field = screen.getByLabelText(label);
        expect(field).toHaveAttribute('aria-invalid', 'true');
        expect(field).toHaveAccessibleDescription(message);
    }
});

it('marks a field valid and undescribed when it has no error', () => {
    render(<TitleField id="t" value="x" onChange={vi.fn()} />);

    const field = screen.getByLabelText(/Title/);
    expect(field).toHaveAttribute('aria-invalid', 'false');
    expect(field).not.toHaveAttribute('aria-describedby');
    expect(field).toBeRequired();
});

it('reports changes as plain values', async () => {
    const user = userEvent.setup();
    const onTitle = vi.fn();
    const onPriority = vi.fn();
    render(
        <>
            <TitleField id="t" value="" onChange={onTitle} />
            <PriorityField id="p" value="low" onChange={onPriority} options={priorities} />
        </>,
    );

    await user.type(screen.getByLabelText(/Title/), 'A');
    await user.selectOptions(screen.getByLabelText('Priority'), 'High');

    expect(onTitle).toHaveBeenCalledWith('A');
    expect(onPriority).toHaveBeenCalledWith('high');
});

it('offers exactly the server-named priorities', () => {
    render(<PriorityField id="p" value="low" onChange={vi.fn()} options={priorities} />);

    expect(screen.getAllByRole('option').map((option) => option.textContent)).toEqual([
        'Low',
        'High',
    ]);
});
