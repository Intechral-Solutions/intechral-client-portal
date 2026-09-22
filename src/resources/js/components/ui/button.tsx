import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import type { ComponentProps } from 'react';

import { cn } from '@/lib/utils';

const buttonVariants = cva(
    'inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50',
    {
        variants: {
            variant: {
                default: 'bg-primary text-primary-foreground hover:opacity-90',
                secondary: 'bg-secondary text-secondary-foreground hover:opacity-80',
                outline: 'border border-border bg-background hover:bg-muted',
                ghost: 'hover:bg-muted',
                destructive: 'bg-destructive text-destructive-foreground hover:opacity-90',
            },
            size: {
                default: 'h-9 px-3',
                icon: 'h-9 w-9 p-0',
                sm: 'h-8 px-2.5 text-xs',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

// ComponentProps (not ButtonHTMLAttributes) so `ref` is accepted: React 19 passes it as a prop.
type ButtonProps = ComponentProps<'button'> &
    VariantProps<typeof buttonVariants> & {
        asChild?: boolean;
    };

export function Button({ className, variant, size, asChild, ...props }: ButtonProps) {
    const Component = asChild ? Slot : 'button';

    return <Component className={cn(buttonVariants({ variant, size }), className)} {...props} />;
}

export { buttonVariants };
