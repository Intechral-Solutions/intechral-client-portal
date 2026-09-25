import * as Dialog from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';

import { Button } from '@/components/ui/button';

type DialogShellProps = Pick<
    ComponentProps<typeof Dialog.Content>,
    'onOpenAutoFocus' | 'onCloseAutoFocus'
> & {
    title: string;
    description: string;
    children: ReactNode;
};

/**
 * The chrome shared by every modal in the app: overlay, centred panel, title, description, and the
 * close button. Render it inside a `Dialog.Root`; `children` is the body below the header.
 */
export function DialogShell({
    title,
    description,
    children,
    onOpenAutoFocus,
    onCloseAutoFocus,
}: DialogShellProps) {
    return (
        <Dialog.Portal>
            <Dialog.Overlay className="fixed inset-0 z-50 bg-black/50" />
            <Dialog.Content
                onOpenAutoFocus={onOpenAutoFocus}
                onCloseAutoFocus={onCloseAutoFocus}
                className="fixed top-1/2 left-1/2 z-50 max-h-[calc(100dvh-2rem)] w-[min(28rem,calc(100vw-2rem))] -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-md border border-border bg-background p-6 shadow-xl">
                <div className="pr-10">
                    <Dialog.Title className="text-lg font-semibold">{title}</Dialog.Title>
                    <Dialog.Description className="mt-2 text-sm text-muted-foreground">
                        {description}
                    </Dialog.Description>
                </div>
                <Dialog.Close asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="absolute top-4 right-4"
                        aria-label="Close dialog"
                    >
                        <X aria-hidden="true" />
                    </Button>
                </Dialog.Close>
                {children}
            </Dialog.Content>
        </Dialog.Portal>
    );
}
