import { CircleAlert, CircleCheck, Info, TriangleAlert, X } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import type { FlashProps } from '@/types';

type FlashRegionProps = {
    flash: FlashProps;
};

// `legacy-success`/`legacy-warning` keep the pre-Direction D colours (EPIC-013 WP1c);
// `danger` is the Direction D token. WP2's Alert restyle replaces all four.
const variants = {
    success: {
        icon: CircleCheck,
        className: 'border-legacy-success text-legacy-success',
        role: 'status',
    },
    status: { icon: Info, className: 'border-info text-info', role: 'status' },
    warning: {
        icon: TriangleAlert,
        className: 'border-legacy-warning text-legacy-warning',
        role: 'status',
    },
    error: { icon: CircleAlert, className: 'border-danger text-danger', role: 'alert' },
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
                const variant = variants[type];
                const Icon = variant.icon;

                return (
                    <Alert
                        key={`${type}:${message}`}
                        className={`flex items-start gap-3 ${variant.className}`}
                        role={variant.role}
                    >
                        <Icon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                        <span className="flex-1">{message}</span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="-my-2 -mr-2"
                            aria-label="Dismiss message"
                            onClick={() => setDismissedSignature(signature)}
                        >
                            <X className="h-4 w-4" />
                        </Button>
                    </Alert>
                );
            })}
        </div>
    );
}
