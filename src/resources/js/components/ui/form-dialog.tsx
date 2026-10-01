import * as Dialog from '@radix-ui/react-dialog';
import { useRef } from 'react';
import type { FormEvent, ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { DialogShell } from '@/components/ui/dialog-shell';

type FormDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    submitLabel: string;
    /** Called for a valid native submit. The default action is already prevented. */
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    processing?: boolean;
    /** Optional opener. Omit it when the caller controls `open` (e.g. an edit-row button). */
    trigger?: ReactNode;
    /** The fields, including their labels and error messages. */
    children: ReactNode;
};

export function FormDialog({
    open,
    onOpenChange,
    title,
    description,
    submitLabel,
    onSubmit,
    processing = false,
    trigger,
    children,
}: FormDialogProps) {
    const opener = useRef<HTMLElement | null>(null);
    const form = useRef<HTMLFormElement>(null);

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            {trigger ? <Dialog.Trigger asChild>{trigger}</Dialog.Trigger> : null}
            <DialogShell
                title={title}
                description={description}
                onOpenAutoFocus={(event) => {
                    opener.current =
                        document.activeElement instanceof HTMLElement ? document.activeElement : null;

                    // Direction D §14.2: a dialog opens on its first field. Radix would otherwise
                    // pick the first tabbable, which is the Close button that precedes the form.
                    const field = form.current?.querySelector<HTMLElement>(
                        'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])',
                    );
                    if (field) {
                        event.preventDefault();
                        field.focus();
                    }
                }}
                onCloseAutoFocus={(event) => {
                    // Radix returns focus only to its own Trigger. A dialog opened through
                    // controlled state (an edit button in a table row) has none, so focus would
                    // fall to <body>: return it to whatever opened the dialog instead.
                    if (trigger) return;

                    event.preventDefault();
                    if (opener.current?.isConnected) opener.current.focus();
                }}
            >
                <form
                    ref={form}
                    className="mt-4 space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        // The dialog is portalled, but React still bubbles its events to the
                        // component that rendered it. A dialog inside another form must not submit it.
                        event.stopPropagation();
                        onSubmit(event);
                    }}
                >
                    {children}
                    <div className="flex justify-end gap-2 pt-2">
                        <Dialog.Close asChild>
                            <Button type="button" variant="secondary" disabled={processing}>
                                Cancel
                            </Button>
                        </Dialog.Close>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : submitLabel}
                        </Button>
                    </div>
                </form>
            </DialogShell>
        </Dialog.Root>
    );
}
