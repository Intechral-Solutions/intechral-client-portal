import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { EntityHeader } from '@/components/entity-header';
import { Strata } from '@/components/strata';

/**
 * EPIC-013 WP7 — `EntityHeader` and the strata motif (Direction D §6, §17).
 *
 * The assertions worth making here are semantic: which heading level the record's name takes, that
 * the strata is decorative, that the component invents no breadcrumb, and that it makes no decision
 * about which actions exist. Its spacing is not a contract and is not asserted.
 */
describe('EntityHeader', () => {
    it('makes the record name the page h1 and closes the block with the strata', () => {
        const { container } = render(<EntityHeader title="Atlas migration" overline="Project" />);

        expect(screen.getByRole('heading', { level: 1, name: 'Atlas migration' })).toBeVisible();
        expect(screen.getByText('Project')).toBeVisible();

        // `[data-strata]` rather than the broader `[aria-hidden="true"]`: a `status` prop can also
        // carry decorative `aria-hidden` content (a status glyph), and that selector is exactly what
        // matched the wrong element during the Chromium measurement in A12.12.
        const strata = container.querySelector('[data-strata]')!;
        expect(strata).toBeInTheDocument();
        expect(strata).toHaveAttribute('aria-hidden', 'true');
        expect(strata.textContent).toBe('');
    });

    it('renders only the regions it is given', () => {
        const { container } = render(<EntityHeader title="Atlas migration" />);

        expect(screen.getByRole('heading', { level: 1 })).toBeVisible();
        expect(container.querySelector('p')).toBeNull();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('draws no breadcrumb of its own, because the shell owns that trail', () => {
        render(<EntityHeader title="Atlas migration" overline="Project" />);

        // WP4/WP5 put the breadcrumb in the utility bar. A second one here would be a duplicate
        // landmark and a second source of truth for where the user is.
        expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    });

    it('presents status and key facts without deciding either', () => {
        render(
            <EntityHeader
                title="Atlas migration"
                status={<span>Active</span>}
                meta={<span>12 open tasks · due 14 March</span>}
            />,
        );

        expect(screen.getByText('Active')).toBeVisible();
        expect(screen.getByText('12 open tasks · due 14 March')).toBeVisible();
    });

    it('renders exactly the actions it is handed and adds none', () => {
        // The caller has already filtered these by capability; the header must not add, reorder or
        // re-enable anything, because it has no basis on which to decide.
        render(
            <EntityHeader
                title="Atlas migration"
                actions={
                    <>
                        <a href="/milestones">Milestones</a>
                        <button type="button">Archive</button>
                    </>
                }
            />,
        );

        expect(screen.getByRole('link', { name: 'Milestones' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Archive' })).toBeVisible();
        expect(screen.getAllByRole('link')).toHaveLength(1);
        expect(screen.getAllByRole('button')).toHaveLength(1);
    });

    it('keeps a long record name readable rather than truncating its identity away', () => {
        const name = 'Atlas migration — phase two rollout across every regional data centre';

        render(<EntityHeader title={name} />);

        const heading = screen.getByRole('heading', { level: 1, name });
        expect(heading).toBeVisible();
        expect(heading.className).not.toMatch(/truncate/);
    });
});

describe('Strata', () => {
    it('is three lines, heaviest last, and is never announced', () => {
        const { container } = render(<Strata />);

        const root = container.firstElementChild!;
        expect(root).toHaveAttribute('aria-hidden', 'true');
        // Direction D §4.3: 1px, 1px, then 2px — heavier at the bottom.
        expect(root.children).toHaveLength(3);
        expect(root.children[2]!.className).toMatch(/h-0\.5/);
    });

    it('draws page tabs directly on the strata, inside the header (EPIC-015 §11.4)', () => {
        const { container } = render(
            <EntityHeader
                title="Atlas migration"
                navigation={<nav aria-label="Project">tabs</nav>}
            />,
        );

        const nav = screen.getByRole('navigation', { name: 'Project' });
        const strata = container.querySelector('[data-strata]')!;

        // Tabs then strata, as siblings with nothing between them, so the underline meets the rule.
        expect(nav.nextElementSibling).toBe(strata);
        expect(container.querySelectorAll('[data-strata]')).toHaveLength(1);
    });
});
