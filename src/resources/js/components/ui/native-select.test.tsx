import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createRef } from 'react';

import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';

it('is a labelled native select whose value follows the user', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(
        <>
            <Label htmlFor="priority">Priority</Label>
            <NativeSelect id="priority" defaultValue="medium" onChange={onChange}>
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
            </NativeSelect>
        </>,
    );

    const select = screen.getByLabelText('Priority');
    expect(select.tagName).toBe('SELECT');
    expect(select).toHaveValue('medium');

    await user.selectOptions(select, 'high');

    expect(select).toHaveValue('high');
    expect(onChange).toHaveBeenCalledTimes(1);
});

it('forwards its ref, merges class names, and honours disabled', () => {
    const ref = createRef<HTMLSelectElement>();
    render(
        <NativeSelect ref={ref} aria-label="Role" className="w-full" disabled>
            <option>Member</option>
        </NativeSelect>,
    );

    const select = screen.getByLabelText('Role');
    expect(ref.current).toBe(select);
    expect(select).toBeDisabled();
    expect(select).toHaveClass('w-full');
});
