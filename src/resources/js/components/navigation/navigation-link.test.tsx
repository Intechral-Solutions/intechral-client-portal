import { render, screen } from '@testing-library/react';
import { vi } from 'vitest';

import { NavigationLink } from './navigation-link';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: React.ComponentProps<'a'>) => (
        <a href={href} data-inertia-link="true" {...props}>
            {children}
        </a>
    ),
}));

const baseItem = {
    key: 'projects',
    label: 'Projects',
    href: '/projects',
    method: 'get' as const,
    activePatterns: ['projects.*'],
    isActive: false,
    children: [],
};

it('uses a document anchor for Blade destinations', () => {
    render(<NavigationLink item={{ ...baseItem, visit: 'document' }} />);

    expect(screen.getByRole('link', { name: 'Projects' })).not.toHaveAttribute('data-inertia-link');
});

it('uses an Inertia Link for Inertia destinations', () => {
    render(<NavigationLink item={{ ...baseItem, visit: 'inertia' }} />);

    expect(screen.getByRole('link', { name: 'Projects' })).toHaveAttribute(
        'data-inertia-link',
        'true',
    );
});
