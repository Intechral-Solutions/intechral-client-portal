import * as Dialog from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';

type ConfirmationDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    confirmLabel: string;
    onConfirm: () => void;
    processing?: boolean;
    children: ReactNode;
};

export function ConfirmationDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel,
    onConfirm,
    processing = false,
    children,
}: ConfirmationDialogProps) {
    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Trigger asChild>{children}</Dialog.Trigger>
            <Dialog.Portal>
                <Dialog.Overlay className="fixed inset-0 z-50 bg-black/50" />
                <Dialog.Content className="fixed top-1/2 left-1/2 z-50 w-[min(28rem,calc(100vw-2rem))] -translate-x-1/2 -translate-y-1/2 rounded-md border border-border bg-background p-6 shadow-xl">
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
                    <div className="mt-6 flex justify-end gap-2">
                        <Dialog.Close asChild>
                            <Button type="button" variant="outline" disabled={processing}>
                                Cancel
                            </Button>
                        </Dialog.Close>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={processing}
                            onClick={onConfirm}
                        >
                            {processing ? 'Working...' : confirmLabel}
                        </Button>
                    </div>
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}
