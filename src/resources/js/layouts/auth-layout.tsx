import type { PropsWithChildren, ReactNode } from 'react';

import { FlashRegion } from '@/components/feedback/flash-region';
import type { FlashProps } from '@/types';

type AuthLayoutProps = PropsWithChildren<{
    title: string;
    subtitle?: ReactNode;
    flash: FlashProps;
}>;

export function AuthLayout({ title, subtitle, flash, children }: AuthLayoutProps) {
    return (
        <main className="flex min-h-screen items-center justify-center bg-muted p-4">
            <div className="w-full max-w-md space-y-6">
                <header className="text-center">
                    <p className="text-xl font-semibold text-foreground">Intechral Portal</p>
                    <h1 className="mt-4 text-2xl font-semibold text-foreground">{title}</h1>
                    {subtitle ? (
                        <div className="mt-1 text-sm text-muted-foreground">{subtitle}</div>
                    ) : null}
                </header>
                <FlashRegion flash={flash} />
                <section className="rounded-md border border-border bg-card p-6 shadow-sm">
                    {children}
                </section>
            </div>
        </main>
    );
}
