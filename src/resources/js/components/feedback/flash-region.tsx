import { X } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import type { FlashProps } from '@/types';

type FlashRegionProps = {
    flash: FlashProps;
};

// Each flash key maps to an Alert variant, which owns the glyph, the kind label and the live-region
// role (`danger` -> `alert`; `success`, `info` and `warning` -> `status`).
const variants = {
    success: 'success',
    status: 'info',
    warning: 'warning',
    error: 'danger',
} as const;

type FlashType = keyof typeof variants;

const flashTypes: FlashType[] = ['success', 'status', 'warning', 'error'];

export function FlashRegion({ flash }: FlashRegionProps) {
    const messages = useMemo(
        () =>
            flashTypes
                .map((type) => [type, flash[type]] as const)
                .filter((entry): entry is readonly [FlashType, string] => Boolean(entry[1])),
        [flash],
    );
    const signature = messages.map(([type, message]) => `${type}:${message}`).join('|');
    const [dismissedSignature, setDismissedSignature] = useState<string | null>(null);

    if (!messages.length || signature === dismissedSignature) {
        return null;
    }

    return (
        <div className="mx-auto w-full max-w-7xl space-y-2 px-4 pt-4 sm:px-6 lg:px-8">
            {messages.map(([type, message]) => {
                return (
                    <Alert
                        key={`${type}:${message}`}
                        variant={variants[type]}
                        action={
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="-my-2 -mr-2"
                                aria-label="Dismiss message"
                                onClick={() => setDismissedSignature(signature)}
                            >
                                <X className="h-4 w-4" aria-hidden="true" />
                            </Button>
                        }
                    >
                        {message}
                    </Alert>
                );
            })}
        </div>
    );
}
