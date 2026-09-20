import { usePage } from '@inertiajs/react';
import { Moon, Sun } from 'lucide-react';
import type { PropsWithChildren, ReactNode } from 'react';

import { FlashRegion } from '@/components/feedback/flash-region';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';
import type { SharedPageProps } from '@/types';

type AuthLayoutProps = PropsWithChildren<{
    title: string;
    subtitle?: ReactNode;
}>;

export function AuthLayout({ title, subtitle, children }: AuthLayoutProps) {
    const { app, flash } = usePage<SharedPageProps>().props;
    const { appearance, toggleAppearance } = useAppearance();

    return (
        <main className="relative flex min-h-screen items-center justify-center bg-muted px-4 py-10 text-foreground sm:px-6">
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="absolute top-4 right-4"
                aria-label={`Switch to ${appearance === 'dark' ? 'light' : 'dark'} theme`}
                title={`Switch to ${appearance === 'dark' ? 'light' : 'dark'} theme`}
                onClick={toggleAppearance}
            >
                {appearance === 'dark' ? <Sun aria-hidden="true" /> : <Moon aria-hidden="true" />}
            </Button>
            <div className="w-full max-w-md space-y-6">
                <header className="text-center">
                    <a
                        href="/"
                        className="text-xl font-semibold text-foreground focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        aria-label={`${app.name} home`}
                    >
                        {app.name}
                    </a>
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
