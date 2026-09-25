import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createRef } from 'react';

import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

it('is a labelled native textarea that accepts typing and native constraints', async () => {
    const user = userEvent.setup();
    render(
        <>
            <Label htmlFor="notes">Notes</Label>
            <Textarea id="notes" maxLength={5} placeholder="Say something" />
        </>,
    );

    const notes = screen.getByLabelText('Notes');
    expect(notes.tagName).toBe('TEXTAREA');

    await user.type(notes, 'abcdefgh');
    expect(notes).toHaveValue('abcde');
});

it('forwards its ref, merges class names, and honours disabled', () => {
    const ref = createRef<HTMLTextAreaElement>();
    render(<Textarea ref={ref} aria-label="Body" className="min-h-40" disabled />);

    const body = screen.getByLabelText('Body');
    expect(ref.current).toBe(body);
    expect(body).toBeDisabled();
    expect(body).toHaveClass('min-h-40');
    expect(body).not.toHaveClass('min-h-24');
});
