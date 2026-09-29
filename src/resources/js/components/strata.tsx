import { cn } from '@/lib/utils';

/**
 * EPIC-013 WP7 — the strata rule (Direction D §17, and the rule table at §4.3).
 *
 * **Strata is a rule, not a container.** It is three stacked lines, heavier at the bottom — 1px
 * `rule-control`, 1px `text-faint` at 70%, 2px `rule-strong`, 2px apart — and it is the brand mark's
 * own geometry restated as a divider. It is not a card, a panel, a section or a surface, and nothing
 * nests inside it.
 *
 * It is also deliberately **rare**. Direction D §17 allows it under entity headers only — the project
 * workspace, the customer project headline, a Directory record, optionally a ticket header — and
 * explicitly forbids it on section headings, tables, cards, dialogs, list pages and **Home**. L8 says
 * the same: "strata motifs are rare and structural (entity headers only)". A motif that appears
 * everywhere stops meaning anything, and here it means *this page is a record*, which is why
 * `EntityHeader` draws it and no other component does.
 *
 * Purely decorative: it carries no text and no semantics, so it is hidden from assistive technology.
 * The identity it signals visually is carried for everyone else by the heading it sits under.
 */
export function Strata({ className }: { className?: string }) {
    return (
        <div data-strata aria-hidden="true" className={cn('flex flex-col gap-0.5', className)}>
            <span className="h-px bg-rule-control" />
            <span className="h-px bg-text-faint/70" />
            <span className="h-0.5 bg-rule-strong" />
        </div>
    );
}
