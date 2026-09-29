import { render, screen } from '@testing-library/react';

import { Section } from '@/components/section';
import { SectionPanel } from '@/components/section-panel';

it('is a section named by its h2 with the strong rule under the title row', () => {
    render(
        <Section
            title="Members"
            description="People on the project."
            actions={<button>Add</button>}
        >
            <p>Body</p>
        </Section>,
    );

    const section = screen.getByRole('region', { name: 'Members' });
    expect(section.querySelector('h2')).toHaveTextContent('Members');
    expect(section).toHaveTextContent('People on the project.');
    expect(screen.getByRole('button', { name: 'Add' })).toBeInTheDocument();
    expect(section.querySelector('.border-rule-strong')).toContainElement(
        screen.getByRole('heading', { name: 'Members' }),
    );
});

it('keeps SectionPanel as the split layout: same content, no strong rule, no card', () => {
    render(
        <SectionPanel title="Password" description="Use a unique password.">
            <input aria-label="Current password" />
        </SectionPanel>,
    );

    const section = screen.getByRole('region', { name: 'Password' });
    expect(section).toHaveTextContent('Use a unique password.');
    expect(screen.getByLabelText('Current password')).toBeInTheDocument();
    expect(section.className).toContain('border-b');
    expect(section.className).not.toMatch(/rounded|shadow|rule-strong|bg-/);
});

it('gives two sections distinct accessible names even with the same title', () => {
    render(
        <>
            <Section title="Details">a</Section>
            <Section title="Details">b</Section>
        </>,
    );

    const [first, second] = screen.getAllByRole('region', { name: 'Details' });
    expect(first?.getAttribute('aria-labelledby')).not.toBe(
        second?.getAttribute('aria-labelledby'),
    );
});
