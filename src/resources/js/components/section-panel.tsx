import type { ReactNode } from 'react';

type SectionPanelProps = {
    title: string;
    description?: string;
    children: ReactNode;
};

export function SectionPanel({ title, description, children }: SectionPanelProps) {
    return (
        <section className="grid gap-6 border-b border-border py-8 md:grid-cols-[minmax(12rem,16rem)_minmax(0,1fr)]">
            <div>
                <h2 className="text-base font-semibold text-foreground">{title}</h2>
                {description ? (
                    <p className="mt-1 text-sm text-muted-foreground">{description}</p>
                ) : null}
            </div>
            <div className="min-w-0">{children}</div>
        </section>
    );
}
