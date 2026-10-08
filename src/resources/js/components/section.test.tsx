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

// EPIC-017 N6: only an anchor target pays for a scroll margin; every other section is unchanged.
describe('the anchor id', () => {
    it.each(['stacked', 'split'] as const)(
        'adds the id and a scroll margin in the %s layout',
        (layout) => {
            render(
                <Section id="sessions" layout={layout} title="Browser sessions">
                    body
                </Section>,
            );

            const section = screen.getByRole('region', { name: 'Browser sessions' });

            expect(section).toHaveAttribute('id', 'sessions');
            expect(section.className).toContain('scroll-mt-20');
        },
    );

    it.each(['stacked', 'split'] as const)(
        'adds neither without an id in the %s layout',
        (layout) => {
            render(
                <Section layout={layout} title="Details">
                    body
                </Section>,
            );

            const section = screen.getByRole('region', { name: 'Details' });

            expect(section).not.toHaveAttribute('id');
            expect(section.className).not.toMatch(/scroll-m/);
        },
    );

    it('is passed through by SectionPanel', () => {
        render(
            <SectionPanel id="security" title="Password">
                body
            </SectionPanel>,
        );

        const section = screen.getByRole('region', { name: 'Password' });

        expect(section).toHaveAttribute('id', 'security');
        expect(section.className).toContain('scroll-mt-20');
    });

    it('keeps a caller className alongside the margin', () => {
        render(
            <Section id="x" title="Keeps" className="custom-layout">
                body
            </Section>,
        );

        expect(screen.getByRole('region', { name: 'Keeps' }).className).toMatch(/custom-layout/);
    });
});
