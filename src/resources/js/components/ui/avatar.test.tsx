import { render, screen } from '@testing-library/react';

import { Avatar, initialsOf } from '@/components/ui/avatar';

it.each([
    ['Ada Lovelace', 'AL'],
    ['ada lovelace-byron', 'AL'],
    ['Grace Brewster Murray Hopper', 'GH'],
    ['  Cher  ', 'C'],
    ['Émile Zola', 'ÉZ'],
    ['', '?'],
    ['   ', '?'],
])('derives %j -> %j deterministically', (name, initials) => {
    expect(initialsOf(name)).toBe(initials);
    expect(initialsOf(name)).toBe(initialsOf(name));
});

it('exposes the person as an image with the name, never the initials or an email', () => {
    render(<Avatar name="Ada Lovelace" />);

    const avatar = screen.getByRole('img', { name: 'Ada Lovelace' });
    expect(avatar).toHaveTextContent('AL');
    expect(avatar).not.toHaveAttribute('title');
});

it('hides itself from assistive technology when the name is printed beside it', () => {
    const { container } = render(<Avatar name="Ada Lovelace" decorative />);

    expect(screen.queryByRole('img')).not.toBeInTheDocument();
    expect(container.firstElementChild).toHaveAttribute('aria-hidden', 'true');
});

it('is a circle at every size, whatever the person is', () => {
    for (const size of ['sm', 'md', 'lg'] as const) {
        const { container, unmount } = render(<Avatar name="Operator Person" size={size} />);

        expect(container.firstElementChild).toHaveClass('rounded-full');
        unmount();
    }
});
