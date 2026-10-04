import { render, screen, within } from '@testing-library/react';

import { StagePath, stagePathWindow, type Stage, type StageState } from '@/components/ui/stage-path';

function stages(states: StageState[]): Stage[] {
    return states.map((state, index) => ({
        key: `s${index + 1}`,
        label: `Stage ${index + 1}`,
        state,
    }));
}

describe('semantics', () => {
    it('is a named list of stages with the current one marked as the current step', () => {
        render(<StagePath label="Release" stages={stages(['done', 'current', 'planned'])} />);

        const list = screen.getByRole('list', { name: 'Release' });
        const items = within(list).getAllByRole('listitem');

        expect(items).toHaveLength(3);
        expect(items[1]).toHaveAttribute('aria-current', 'step');
        expect(items[0]).not.toHaveAttribute('aria-current');
        expect(items[2]).not.toHaveAttribute('aria-current');
    });

    it('states every stage state in text, not colour or position alone (full)', () => {
        render(
            <StagePath
                label="Release"
                stages={[
                    { key: 'a', label: 'Kickoff', state: 'done', meta: 'Due Jan 1, 2026' },
                    { key: 'b', label: 'Build', state: 'current' },
                    { key: 'c', label: 'Launch', state: 'planned' },
                    { key: 'd', label: 'Audit', state: 'blocked' },
                ]}
            />,
        );

        const items = within(screen.getByRole('list', { name: 'Release' })).getAllByRole('listitem');
        expect(items.map((item) => item.textContent)).toEqual([
            'KickoffDone · Due Jan 1, 2026',
            'BuildCurrent',
            'LaunchPlanned',
            'AuditBlocked',
        ]);
    });

    it('keeps the state in (visually hidden) text in the compact variant', () => {
        render(
            <StagePath
                variant="compact"
                label="Milestones: 1 of 3 complete"
                stages={[
                    { key: 'a', label: 'Kickoff', state: 'done' },
                    { key: 'b', label: 'Build', state: 'current', meta: 'Overdue · due Feb 1, 2026' },
                    { key: 'c', label: 'Launch', state: 'planned' },
                ]}
            />,
        );

        const list = screen.getByRole('list', { name: 'Milestones: 1 of 3 complete' });
        expect(within(list).getByText('Kickoff: Done')).toHaveClass('sr-only');
        expect(within(list).getByText('Build: Current, Overdue · due Feb 1, 2026')).toHaveClass(
            'sr-only',
        );
        expect(within(list).getByText('Launch: Planned')).toBeInTheDocument();
    });

    it('hides the decorative segments from assistive technology', () => {
        const { container } = render(<StagePath label="Release" stages={stages(['done', 'current'])} />);

        for (const segment of container.querySelectorAll('[data-stage-segment]')) {
            expect(segment.closest('[aria-hidden="true"]')).not.toBeNull();
        }
    });
});

describe('geometry', () => {
    it('rises 6px, then +3px per step, capped at 24 (full) and 14 (compact)', () => {
        const states: StageState[] = ['done', 'done', 'current', 'planned', 'planned', 'planned', 'planned'];
        const { container, unmount } = render(<StagePath label="Full" stages={stages(states)} />);

        expect(
            [...container.querySelectorAll<HTMLElement>('[data-stage-segment]')].map((segment) =>
                segment.style.getPropertyValue('--stage-height'),
            ),
        ).toEqual(['6px', '9px', '12px', '15px', '18px', '21px', '24px']);
        unmount();

        const compact = render(<StagePath variant="compact" label="Compact" stages={stages(states)} />);
        expect(
            [...compact.container.querySelectorAll<HTMLElement>('[data-stage-segment]')].map(
                (segment) => segment.style.height,
            ),
        ).toEqual(['6px', '9px', '12px', '14px', '14px', '14px', '14px']);
    });

    it('styles each state with its own token, never cyan for anything but current', () => {
        const { container } = render(
            <StagePath label="Release" stages={stages(['done', 'current', 'planned', 'blocked'])} />,
        );
        const segments = [...container.querySelectorAll('[data-stage-segment]')];

        expect(segments[0]).toHaveClass('bg-progress-fill');
        expect(segments[1]).toHaveClass('bg-live');
        expect(segments[2]).toHaveClass('border-dashed', 'border-stage-future');
        expect(segments[3]).toHaveClass('bg-warning-glyph');
        expect(container.querySelectorAll('.bg-live')).toHaveLength(1);
    });

    it('lays the full path out as columns from S/M up and stacks it at phone widths', () => {
        render(<StagePath label="Release" stages={stages(['done', 'current'])} />);

        expect(screen.getByRole('list', { name: 'Release' })).toHaveClass('flex-col', 'sm:grid');
    });

    it('keeps the compact path as one row of columns at every width', () => {
        render(<StagePath variant="compact" label="Release" stages={stages(['done', 'current'])} />);

        const list = screen.getByRole('list', { name: 'Release' });
        expect(list).toHaveClass('grid');
        expect(list).not.toHaveClass('flex-col');
        expect(list.style.gridTemplateColumns).toBe('repeat(2, minmax(0, 1fr))');
    });
});

describe('the window rule for more than seven stages (EPIC-015 §14.2)', () => {
    it('shows everything when there are seven or fewer', () => {
        expect(stagePathWindow(stages(['done', 'current', 'planned']))).toEqual({ start: 0, end: 3 });
        expect(stagePathWindow(stages(Array(7).fill('planned')))).toEqual({ start: 0, end: 7 });
    });

    it('keeps the current stage, the later ones, then backfills earlier ones (12 stages, current #11)', () => {
        const states: StageState[] = [...Array(10).fill('done'), 'current', 'planned'];

        // #6 to #12: the current stage, one later, and five earlier backfilled.
        expect(stagePathWindow(stages(states))).toEqual({ start: 5, end: 12 });
    });

    it('starts at the current stage when enough later stages follow it', () => {
        const states: StageState[] = ['done', 'done', 'current', ...Array(9).fill('planned')];

        expect(stagePathWindow(stages(states))).toEqual({ start: 2, end: 9 });
    });

    it('anchors on the first stage without a current one, and on the last when all are done', () => {
        expect(stagePathWindow(stages(Array(10).fill('planned')))).toEqual({ start: 0, end: 7 });
        expect(stagePathWindow(stages(Array(10).fill('done')))).toEqual({ start: 3, end: 10 });
    });

    it('decides by position and state only: an out-of-order done stage after current stays in order', () => {
        const states: StageState[] = [...Array(5).fill('done'), 'current', 'done', ...Array(5).fill('planned')];
        const { start, end } = stagePathWindow(states.map((state) => ({ state })));

        expect({ start, end }).toEqual({ start: 5, end: 12 });
    });

    it('renders at most seven stages, current always visible, in stable index order', () => {
        const states: StageState[] = [...Array(10).fill('done'), 'current', 'planned'];

        render(
            <StagePath
                label="Release"
                stages={stages(states)}
                hiddenBefore="5 earlier"
                hiddenAfter="never shown"
            />,
        );

        const items = within(screen.getByRole('list', { name: 'Release' })).getAllByRole('listitem');
        expect(items).toHaveLength(7);
        expect(items.map((item) => item.textContent?.replace(/(Done|Current|Planned)$/, ''))).toEqual([
            'Stage 6',
            'Stage 7',
            'Stage 8',
            'Stage 9',
            'Stage 10',
            'Stage 11',
            'Stage 12',
        ]);
        expect(items[5]).toHaveAttribute('aria-current', 'step');
    });

    it('shows only the caller-written summaries, and only on the side that is actually hidden', () => {
        const states: StageState[] = [...Array(10).fill('done'), 'current', 'planned'];
        const { rerender } = render(
            <StagePath label="Release" stages={stages(states)} hiddenBefore="5 earlier" hiddenAfter="3 later" />,
        );

        expect(screen.getByText('5 earlier')).toBeInTheDocument();
        // Nothing is hidden after the window here, so the after-summary is not drawn.
        expect(screen.queryByText('3 later')).not.toBeInTheDocument();

        const first: StageState[] = ['current', ...Array(8).fill('planned')];
        rerender(
            <StagePath label="Release" stages={stages(first)} hiddenBefore="never" hiddenAfter="2 later" />,
        );

        expect(screen.queryByText('never')).not.toBeInTheDocument();
        expect(screen.getByText('2 later')).toBeInTheDocument();
    });

    it('never adds wording of its own for hidden stages', () => {
        const { container } = render(
            <StagePath label="Release" stages={stages([...Array(10).fill('done'), 'current'])} />,
        );

        expect(container.querySelectorAll('p')).toHaveLength(0);
    });
});
