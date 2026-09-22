import * as Dialog from '@radix-ui/react-dialog';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { DialogShell } from '@/components/ui/dialog-shell';

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
            <DialogShell title={title} description={description}>
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
            </DialogShell>
        </Dialog.Root>
    );
}
