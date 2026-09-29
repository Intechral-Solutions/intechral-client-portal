import { cva, type VariantProps } from 'class-variance-authority';
import type { HTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

/**
 * The canonical Direction D status mark (EPIC-013 WP2, docs/design/direction-d-design-system.md
 * §10): an inline glyph, a text label and a semantic colour. Colour is never the signal on its own:
 * the glyph SHAPE differs by state and the label is always visible text.
 *
 * The primitive owns presentation only. It has no knowledge of tickets, tasks, projects or
 * invoices: domain code decides the `tone` (and, where its domain has a specific shape, the
 * `glyph`) and supplies the label as children. Statuses render inline, never as filled pills;
 * pills stay with `Badge` for tags and filter chips.
 *
 * Colour roles follow §2.3: the label uses the text-safe token (`success`, `warning`, `accent`,
 * `danger`, `live-text`), the glyph the shape-only token (`success-glyph`, `warning-glyph`,
 * `live`). `success` label text is AA on `canvas` and anything lighter (4.83:1 on canvas); do not
 * place it on a surface darker than canvas.
 */
const statusVariants = cva('inline-flex items-center gap-1.5 text-sm font-medium', {
    variants: {
        tone: {
            neutral: 'text-text-muted',
            info: 'text-accent',
            success: 'text-success',
            warning: 'text-warning',
            danger: 'text-danger',
            live: 'text-live-text',
        },
    },
    defaultVariants: { tone: 'neutral' },
});

export type StatusTone = NonNullable<VariantProps<typeof statusVariants>['tone']>;

const glyphColour: Record<StatusTone, string> = {
    neutral: 'text-text-muted',
    info: 'text-accent',
    success: 'text-success-glyph',
    warning: 'text-warning-glyph',
    danger: 'text-danger',
    live: 'text-live',
};

/** Shapes, so two tones never differ by colour alone. */
export type StatusGlyph = 'circle' | 'half' | 'dot' | 'check' | 'triangle' | 'square' | 'dashed';

const defaultGlyph: Record<StatusTone, StatusGlyph> = {
    neutral: 'circle',
    info: 'half',
    success: 'check',
    warning: 'triangle',
    danger: 'square',
    live: 'dot',
};

function Glyph({ glyph, className }: { glyph: StatusGlyph; className: string }) {
    const common = {
        viewBox: '0 0 12 12',
        className: cn('h-3 w-3 shrink-0', className),
        'aria-hidden': true,
        focusable: false,
    } as const;

    switch (glyph) {
        case 'circle':
            return (
                <svg {...common}>
                    <circle cx="6" cy="6" r="4.25" fill="none" stroke="currentColor" strokeWidth="1.5" />
                </svg>
            );
        case 'dashed':
            return (
                <svg {...common}>
                    <circle
                        cx="6"
                        cy="6"
                        r="4.25"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1.5"
                        strokeDasharray="2.2 1.6"
                    />
                </svg>
            );
        case 'half':
            return (
                <svg {...common}>
                    <circle cx="6" cy="6" r="4.25" fill="none" stroke="currentColor" strokeWidth="1.5" />
                    <path d="M6 1.75a4.25 4.25 0 0 1 0 8.5z" fill="currentColor" />
                </svg>
            );
        case 'dot':
            return (
                <svg {...common}>
                    <circle cx="6" cy="6" r="4.5" fill="currentColor" />
                </svg>
            );
        case 'check':
            return (
                <svg {...common}>
                    <circle cx="6" cy="6" r="4.25" fill="none" stroke="currentColor" strokeWidth="1.5" />
                    <path
                        d="M3.9 6.2 5.4 7.7 8.2 4.6"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1.4"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    />
                </svg>
            );
        case 'triangle':
            return (
                <svg {...common}>
                    <path d="M6 1.5 11 10.5H1z" fill="currentColor" strokeLinejoin="round" />
                </svg>
            );
        case 'square':
            return (
                <svg {...common}>
                    <rect x="2" y="2" width="8" height="8" rx="1" fill="currentColor" />
                </svg>
            );
    }
}

type StatusProps = Omit<HTMLAttributes<HTMLSpanElement>, 'children'> & {
    tone?: StatusTone;
    /** Override the tone's default shape where the domain has a specific one (e.g. `dashed` for on hold). */
    glyph?: StatusGlyph;
    /** The visible label. Rendered as text, so a hostile string is escaped, never parsed. */
    children: string;
};

export function Status({ tone = 'neutral', glyph, className, children, ...props }: StatusProps) {
    return (
        <span className={cn(statusVariants({ tone }), className)} {...props}>
            <Glyph glyph={glyph ?? defaultGlyph[tone]} className={glyphColour[tone]} />
            <span>{children}</span>
        </span>
    );
}
