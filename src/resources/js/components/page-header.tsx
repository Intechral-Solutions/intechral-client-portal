import type { ReactNode } from 'react';

type PageHeaderProps = {
    title: string;
    description?: string;
    actions?: ReactNode;
};

export function PageHeader({ title, description, actions }: PageHeaderProps) {
    return (
        <header className="flex flex-col justify-between gap-4 border-b border-border pb-6 sm:flex-row sm:items-end">
            <div>
                <h1 className="text-2xl font-semibold tracking-normal text-foreground">{title}</h1>
                {description ? (
                    <p className="mt-1 max-w-2xl text-sm text-muted-foreground">{description}</p>
                ) : null}
            </div>
            {actions ? <div className="shrink-0">{actions}</div> : null}
        </header>
    );
}
