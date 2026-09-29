import { render, screen, within } from '@testing-library/react';

import { resetInertiaMock } from '@/test/inertia';

import { Rail } from './rail';
import { helpdesk, home, projects, resources, tasks } from './shell-fixtures';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

it('renders one link per workspace, in the order the server sent them', () => {
    render(
        <Rail
            workspaces={[home, projects, tasks, helpdesk, resources]}
            showToggle={false}
            onToggle={vi.fn()}
        />,
    );

    const rail = screen.getByRole('navigation', { name: 'Workspaces' });
    const links = within(rail).getAllByRole('link');

    expect(links.map((link) => link.textContent)).toEqual([
        'Home',
        'Projects',
        'Tasks',
        'Helpdesk',
        'Resources',
    ]);
});

it('renders the conditional Resources workspace no differently from the rest', () => {
    render(<Rail workspaces={[home, resources]} showToggle={false} onToggle={vi.fn()} />);

    const link = screen.getByRole('link', { name: 'Resources' });

    // G3 is a server authorization decision; presentation must not mark it out as provisional.
    expect(link).toHaveAttribute('href', '/pages');
    expect(link.className).toBe(screen.getByRole('link', { name: 'Home' }).className);
});

it('marks only the active workspace as the current page', () => {
    render(<Rail workspaces={[home, projects, tasks]} showToggle={false} onToggle={vi.fn()} />);

    expect(screen.getByRole('link', { name: 'Projects' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('link', { name: 'Home' })).not.toHaveAttribute('aria-current');
    expect(screen.getByRole('link', { name: 'Tasks' })).not.toHaveAttribute('aria-current');
    expect(
        screen.getAllByRole('link').filter((l) => l.getAttribute('aria-current') === 'page'),
    ).toHaveLength(1);
});

it('renders each destination with the visit mode the server assigned it', () => {
    render(<Rail workspaces={[projects, helpdesk]} showToggle={false} onToggle={vi.fn()} />);

    // The shared Link double marks Inertia visits; a document destination is a plain anchor.
    expect(screen.getByRole('link', { name: 'Projects' })).toHaveAttribute('data-inertia-link');
    expect(screen.getByRole('link', { name: 'Helpdesk' })).not.toHaveAttribute('data-inertia-link');
});

it('keeps primary navigation as plain tabbable links with no arrow-key behaviour', () => {
    const { container } = render(
        <Rail workspaces={[home, projects]} showToggle={false} onToggle={vi.fn()} />,
    );

    // L11 / Direction D §14.3: no roving tabindex, no keyboard interception.
    for (const link of screen.getAllByRole('link')) {
        expect(link).not.toHaveAttribute('tabindex');
    }

    expect(container.querySelector('[role="menu"], [role="menubar"], [role="tablist"]')).toBeNull();
});

it('offers the panel toggle only while the panel is collapsed', () => {
    const onToggle = vi.fn();
    const { rerender } = render(
        <Rail workspaces={[projects]} showToggle={false} onToggle={onToggle} />,
    );

    expect(screen.queryByRole('button', { name: 'Show workspace views' })).toBeNull();

    rerender(<Rail workspaces={[projects]} showToggle onToggle={onToggle} />);

    const toggle = screen.getByRole('button', { name: 'Show workspace views' });

    expect(toggle).toHaveAttribute('aria-expanded', 'false');
    expect(toggle).toHaveAttribute('aria-controls', 'shell-drawer');
});

it('renders an unknown icon key with the neutral fallback instead of throwing', () => {
    expect(() =>
        render(
            <Rail
                workspaces={[{ ...home, icon: 'not-a-real-icon' }]}
                showToggle={false}
                onToggle={vi.fn()}
            />,
        ),
    ).not.toThrow();

    expect(screen.getByRole('link', { name: 'Home' })).toBeInTheDocument();
});

it('lays out every rail item so that a half-parsed item is already in its final geometry', () => {
    render(<Rail workspaces={[home, projects, tasks]} showToggle={false} onToggle={vi.fn()} />);

    // Fixed 18px icon + 10px label tracks, centred as a block in the 46px tile, and a full-width label
    // with centred text. A centred flex column placed the icon by whatever children existed, so an
    // icon painted before its label (the parser pausing mid-item) sat 7px lower and jumped when the
    // label arrived; a shrink-wrapped label re-centred when its text arrived. Blade renders the same
    // tokens (BladeShellTest); the browser invariant is in blade-shell.spec.ts and shell.spec.ts.
    for (const link of screen.getAllByRole('link')) {
        const tokens = link.className.split(/\s+/);

        expect(tokens).toEqual(
            expect.arrayContaining([
                'grid',
                'h-[46px]',
                'w-[52px]',
                'grid-rows-[18px_10px]',
                'content-center',
                'justify-items-center',
                'gap-1',
            ]),
        );
        expect(tokens).not.toContain('flex');
        expect(tokens).not.toContain('justify-center');
        expect([...link.children].map((child) => child.tagName.toLowerCase())).toEqual([
            'svg',
            'span',
        ]);

        const label = link.querySelector('span')!.className.split(/\s+/);

        expect(label).toEqual(expect.arrayContaining(['w-full', 'text-center', 'truncate']));
        expect(label).not.toContain('max-w-full');
    }
});
