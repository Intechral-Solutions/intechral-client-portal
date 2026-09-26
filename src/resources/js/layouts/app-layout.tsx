import * as Dialog from '@radix-ui/react-dialog';
import { Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, Menu, Moon, Sun, X } from 'lucide-react';
import type { PropsWithChildren } from 'react';

import { FlashRegion } from '@/components/feedback/flash-region';
import { NavigationLink } from '@/components/navigation/navigation-link';
import { RunningTimerBar } from '@/components/time/running-timer-bar';
import { TimerProvider } from '@/components/time/timer-provider';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/hooks/use-appearance';
import { dashboard, logout } from '@/routes';
import { show as profile } from '@/routes/profile';
import type { LegacyNavigationGroup, SharedPageProps } from '@/types';

function group(groups: LegacyNavigationGroup[], key: string) {
    return groups.find((candidate) => candidate.key === key)?.items ?? [];
}

export function AppLayout({ children }: PropsWithChildren) {
    const { app, auth, navigationLegacy, flash } = usePage<SharedPageProps>().props;
    const { appearance, toggleAppearance } = useAppearance();
    // The pre-WP4 header has no drawer, so it renders the flat compatibility projection of the
    // canonical `navigation` contract. WP4 replaces this whole layout with the Direction D shell,
    // which reads `navigation` directly.
    const primary = group(navigationLegacy, 'primary');
    const overflow = group(navigationLegacy, 'overflow');

    return (
        <TimerProvider enabled={auth.permissions.includes('time.log')}>
            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <header className="sticky top-0 z-40 border-b border-border bg-background shadow-sm">
                    <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                        <div className="flex items-center gap-1">
                            <Link
                                href={dashboard.url()}
                                className="mr-4 text-lg font-semibold text-foreground"
                                aria-label={`${app.name} home`}
                            >
                                Intechral Portal
                            </Link>
                            <nav
                                className="hidden items-center gap-0.5 md:flex"
                                aria-label="Primary navigation"
                            >
                                {primary.map((item) => (
                                    <NavigationLink key={item.key} item={item} />
                                ))}
                            </nav>
                        </div>

                        <div className="flex items-center gap-1">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label={`Switch to ${appearance === 'dark' ? 'light' : 'dark'} theme`}
                                onClick={toggleAppearance}
                            >
                                {appearance === 'dark' ? (
                                    <Sun aria-hidden="true" />
                                ) : (
                                    <Moon aria-hidden="true" />
                                )}
                            </Button>

                            {auth.user ? (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            aria-label="Open user menu"
                                        >
                                            <span className="hidden max-w-40 truncate sm:inline">
                                                {auth.user.name}
                                            </span>
                                            <ChevronDown className="h-4 w-4" aria-hidden="true" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" className="w-56">
                                        <div className="border-b border-border px-3 py-2">
                                            <p className="truncate text-sm font-medium">
                                                {auth.user.name}
                                            </p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {auth.user.email}
                                            </p>
                                        </div>
                                        <DropdownMenuItem asChild>
                                            <Link href={profile.url()} className="block">
                                                Profile
                                            </Link>
                                        </DropdownMenuItem>
                                        {/* Contextual destinations this flat shell cannot otherwise
                                            reach. The retired "Manage" grouping is gone: no
                                            heading, no label. WP4 moves these into the drawer. */}
                                        {overflow.length ? (
                                            <>
                                                <DropdownMenuSeparator />
                                                {overflow.map((item) => (
                                                    <DropdownMenuItem key={item.key} asChild>
                                                        <NavigationLink
                                                            item={item}
                                                            className="block rounded-sm"
                                                        />
                                                    </DropdownMenuItem>
                                                ))}
                                            </>
                                        ) : null}
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            onSelect={() => router.post(logout.url())}
                                        >
                                            Sign out
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            ) : null}

                            <Dialog.Root>
                                <Dialog.Trigger asChild>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="md:hidden"
                                        aria-label="Open navigation menu"
                                    >
                                        <Menu aria-hidden="true" />
                                    </Button>
                                </Dialog.Trigger>
                                <Dialog.Portal>
                                    <Dialog.Overlay className="fixed inset-0 z-40 bg-black/50" />
                                    <Dialog.Content className="fixed inset-y-0 right-0 z-50 w-[min(22rem,90vw)] border-l border-border bg-background p-5 shadow-xl">
                                        <div className="flex items-center justify-between">
                                            <Dialog.Title className="font-semibold">
                                                Navigation
                                            </Dialog.Title>
                                            <Dialog.Close asChild>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label="Close navigation menu"
                                                >
                                                    <X aria-hidden="true" />
                                                </Button>
                                            </Dialog.Close>
                                        </div>
                                        <nav
                                            className="mt-6 flex flex-col gap-1"
                                            aria-label="Mobile navigation"
                                        >
                                            {primary.map((item) => (
                                                <Dialog.Close key={item.key} asChild>
                                                    <NavigationLink
                                                        item={item}
                                                        className="block rounded-sm"
                                                    />
                                                </Dialog.Close>
                                            ))}
                                        </nav>
                                    </Dialog.Content>
                                </Dialog.Portal>
                            </Dialog.Root>
                        </div>
                    </div>
                </header>

                <RunningTimerBar />
                <FlashRegion flash={flash} />
                <main id="main-content" className="flex-1">
                    {children}
                </main>
                <footer className="border-t border-border bg-muted py-6 text-center">
                    <p className="text-sm text-muted-foreground">
                        &copy; {new Date().getFullYear()} Intechral Solutions. All rights reserved.
                    </p>
                </footer>
            </div>
        </TimerProvider>
    );
}
