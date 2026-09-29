import { useId } from 'react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

type SectionProps = {
    title: string;
    description?: string;
    /** Section-level actions, right-aligned on the title row. */
    actions?: ReactNode;
    /**
     * `stacked` (default): title row over a `rule-strong` line, content below.
     * `split`: title and description in a left column, content on the right (the `SectionPanel`
     * form layout), closed by a hairline `rule`. It keeps the pre-Direction D geometry so the 26
     * unmigrated form sections do not change shape; only their colours move to tokens.
     */
    layout?: 'stacked' | 'split';
    className?: string;
    children: ReactNode;
};

/**
 * Direction D section (EPIC-013 WP2; §4.3 "Section: `rule-strong` under every section title", L10
 * "structure over boxes"). Separation is a rule and spacing, never a bordered card. The stacked layout puts the
 * `rule-strong` under the title, as the spec draws it. Renders a
 * `<section>` named by its `h2`, so it is a landmark-free grouping that assistive technology can
 * still list.
 */
export function Section({
    title,
    description,
    actions,
    layout = 'stacked',
    className,
    children,
}: SectionProps) {
    const headingId = useId();

    if (layout === 'split') {
        return (
            <section
                aria-labelledby={headingId}
                className={cn(
                    'grid gap-6 border-b border-rule py-8 md:grid-cols-[minmax(12rem,16rem)_minmax(0,1fr)]',
                    className,
                )}
            >
                <div>
                    <h2 id={headingId} className="text-base font-semibold text-text">
                        {title}
                    </h2>
                    {description ? (
                        <p className="mt-1 text-sm text-text-secondary">{description}</p>
                    ) : null}
                    {actions ? <div className="mt-3 flex flex-wrap gap-2">{actions}</div> : null}
                </div>
                <div className="min-w-0">{children}</div>
            </section>
        );
    }

    return (
        <section aria-labelledby={headingId} className={cn('space-y-4 py-2', className)}>
            <div className="flex items-end justify-between gap-4 border-b border-rule-strong pb-2">
                <div className="min-w-0">
                    <h2 id={headingId} className="text-base font-semibold text-text">
                        {title}
                    </h2>
                    {description ? (
                        <p className="mt-0.5 text-sm text-text-secondary">{description}</p>
                    ) : null}
                </div>
                {actions ? <div className="flex shrink-0 flex-wrap gap-2">{actions}</div> : null}
            </div>
            <div className="min-w-0">{children}</div>
        </section>
    );
}
