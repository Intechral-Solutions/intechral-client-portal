import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';

import { CompanySelector } from '@/components/projects/company-selector';

const companies = [
    { id: 1, name: 'Acme Co' },
    { id: 2, name: 'Globex' },
];

function Harness({ initial = [], error }: { initial?: number[]; error?: string }) {
    const [selected, setSelected] = useState(initial);

    return (
        <CompanySelector
            idPrefix="test"
            companies={companies}
            selected={selected}
            onChange={setSelected}
            error={error}
        />
    );
}

it('is a labelled group of native checkboxes that toggle independently', async () => {
    const user = userEvent.setup();
    render(<Harness initial={[2]} />);

    expect(screen.getByRole('group', { name: 'Linked companies' })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: 'Acme Co' })).not.toBeChecked();
    expect(screen.getByRole('checkbox', { name: 'Globex' })).toBeChecked();

    await user.click(screen.getByRole('checkbox', { name: 'Acme Co' }));
    await user.click(screen.getByRole('checkbox', { name: 'Globex' }));

    expect(screen.getByRole('checkbox', { name: 'Acme Co' })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: 'Globex' })).not.toBeChecked();
});

it('says a company link is informational and grants no access', () => {
    render(<Harness />);

    const group = screen.getByRole('group', { name: 'Linked companies' });
    expect(group).toHaveAccessibleDescription(
        /does not grant that company's organization members access/,
    );
    expect(group).not.toHaveAccessibleDescription(/visibility/i);
});

it('shows a validation error and ties it to the group', () => {
    render(<Harness error="The selected company is invalid." />);

    expect(screen.getByRole('alert')).toHaveTextContent('The selected company is invalid.');
    expect(screen.getByRole('group', { name: 'Linked companies' })).toHaveAccessibleDescription(
        /The selected company is invalid\./,
    );
});
