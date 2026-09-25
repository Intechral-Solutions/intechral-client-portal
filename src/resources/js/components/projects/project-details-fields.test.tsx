import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';

import {
    firstDetailsErrorField,
    ProjectDetailsFields,
    type ProjectDetailsData,
} from '@/components/projects/project-details-fields';

const blank: ProjectDetailsData = {
    name: '',
    description: '',
    start_date: '',
    target_date: '',
    status: 'active',
    budget: '',
};

function Harness({
    errors = {},
    onData,
    initial = blank,
}: {
    errors?: Record<string, string>;
    onData?: (data: ProjectDetailsData) => void;
    initial?: ProjectDetailsData;
}) {
    const [data, setData] = useState(initial);

    return (
        <ProjectDetailsFields
            idPrefix="t"
            data={data}
            errors={errors}
            onChange={(key, value) =>
                setData((current) => {
                    const next = { ...current, [key]: value };
                    onData?.(next);

                    return next;
                })
            }
        />
    );
}

it('offers every editable field as a native, labelled control', () => {
    render(<Harness />);

    expect(screen.getByLabelText(/Project name/)).toBeRequired();
    expect(screen.getByLabelText('Description').tagName).toBe('TEXTAREA');
    expect(screen.getByLabelText('Start date')).toHaveAttribute('type', 'date');
    expect(screen.getByLabelText('Target date')).toHaveAttribute('type', 'date');
    expect(screen.getByLabelText('Status').tagName).toBe('SELECT');
    expect(screen.getAllByRole('option').map((option) => option.textContent)).toEqual([
        'Active',
        'On hold',
        'Completed',
        'Archived',
    ]);
    expect(screen.getByLabelText('Budget ($)')).toHaveAttribute('step', '0.01');
});

it('keeps the budget and dates exactly as the strings entered and never converts them', () => {
    const onData = vi.fn();
    render(<Harness onData={onData} />);

    // fireEvent, not typing: jsdom re-parses numeric input, a browser keeps what was typed.
    fireEvent.change(screen.getByLabelText('Budget ($)'), { target: { value: '1200.50' } });
    fireEvent.change(screen.getByLabelText('Start date'), { target: { value: '2026-01-05' } });

    const last = onData.mock.calls.at(-1)![0] as ProjectDetailsData;
    expect(last.budget).toBe('1200.50'); // not 1200.5
    expect(last.start_date).toBe('2026-01-05');
    expect(typeof last.budget).toBe('string');
});

it('shows each field error on its own field and marks only that field invalid', () => {
    render(
        <Harness
            errors={{ name: 'The name field is required.', target_date: 'Target is before start.' }}
        />,
    );

    expect(screen.getByLabelText(/Project name/)).toHaveAccessibleDescription(
        'The name field is required.',
    );
    expect(screen.getByLabelText(/Project name/)).toBeInvalid();
    expect(screen.getByLabelText('Target date')).toHaveAccessibleDescription(
        'Target is before start.',
    );
    expect(screen.getByLabelText('Start date')).toBeValid();
    expect(screen.getByLabelText('Budget ($)')).toBeValid();
});

it('finds the first field with an error in form order', () => {
    expect(firstDetailsErrorField({})).toBeUndefined();
    expect(firstDetailsErrorField({ budget: 'x', target_date: 'y' })).toBe('target_date');
    expect(firstDetailsErrorField({ 'members.0.user_id': 'not this form' })).toBeUndefined();
});
