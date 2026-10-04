import { render, screen, within } from '@testing-library/react';

import { PageTabs } from '@/components/page-tabs';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

const tabs = [
    { key: 'one', label: 'One', href: '/things/1' },
    { key: 'two', label: 'Two', href: '/things/1/two' },
];

it('is a labelled navigation landmark of ordinary links', () => {
    render(<PageTabs label="Thing" tabs={tabs} current="one" />);

    const nav = screen.getByRole('navigation', { name: 'Thing' });
    const links = within(nav).getAllByRole('link');

    expect(links.map((link) => [link.textContent, link.getAttribute('href')])).toEqual([
        ['One', '/things/1'],
        ['Two', '/things/1/two'],
    ]);
});

it('marks only the current page, with aria-current="page" and the ink underline', () => {
    render(<PageTabs label="Thing" tabs={tabs} current="two" />);

    expect(screen.getByRole('link', { name: 'Two' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('link', { name: 'Two' })).toHaveClass('border-text', 'font-semibold');
    expect(screen.getByRole('link', { name: 'One' })).not.toHaveAttribute('aria-current');
    expect(screen.getByRole('link', { name: 'One' })).toHaveClass('border-transparent');
});

it('uses page-link semantics, never the ARIA tab widget (EPIC-015 §11.4, P3)', () => {
    const { container } = render(<PageTabs label="Thing" tabs={tabs} current="one" />);

    expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
    expect(screen.queryByRole('tab')).not.toBeInTheDocument();
    expect(container.querySelector('[aria-selected]')).toBeNull();
});

it('scrolls horizontally at narrow widths rather than wrapping', () => {
    render(<PageTabs label="Thing" tabs={tabs} current="one" />);

    expect(screen.getByRole('navigation', { name: 'Thing' })).toHaveClass('overflow-x-auto');
    expect(screen.getByRole('list')).toHaveClass('whitespace-nowrap');
});

it('keeps a visible focus indicator on every tab', () => {
    render(<PageTabs label="Thing" tabs={tabs} current="one" />);

    for (const link of screen.getAllByRole('link')) {
        expect(link).toHaveClass('focus-visible:outline-2', 'focus-visible:outline-focus');
    }
});
