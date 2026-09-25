import * as Menu from '@radix-ui/react-dropdown-menu';
import type { ComponentPropsWithoutRef, ElementRef } from 'react';
import { forwardRef } from 'react';

import { cn } from '@/lib/utils';

export const DropdownMenu = Menu.Root;
export const DropdownMenuTrigger = Menu.Trigger;
export const DropdownMenuGroup = Menu.Group;

export const DropdownMenuContent = forwardRef<
    ElementRef<typeof Menu.Content>,
    ComponentPropsWithoutRef<typeof Menu.Content>
>(({ className, sideOffset = 6, ...props }, ref) => (
    <Menu.Portal>
        <Menu.Content
            ref={ref}
            sideOffset={sideOffset}
            className={cn(
                'z-50 min-w-40 rounded-md border border-border bg-card p-1 text-card-foreground shadow-lg',
                className,
            )}
            {...props}
        />
    </Menu.Portal>
));
DropdownMenuContent.displayName = 'DropdownMenuContent';

export const DropdownMenuItem = forwardRef<
    ElementRef<typeof Menu.Item>,
    ComponentPropsWithoutRef<typeof Menu.Item>
>(({ className, ...props }, ref) => (
    <Menu.Item
        ref={ref}
        className={cn(
            'cursor-pointer rounded-sm px-3 py-2 text-sm outline-none focus:bg-muted data-[disabled]:pointer-events-none data-[disabled]:opacity-50',
            className,
        )}
        {...props}
    />
));
DropdownMenuItem.displayName = 'DropdownMenuItem';

export const DropdownMenuLabel = forwardRef<
    ElementRef<typeof Menu.Label>,
    ComponentPropsWithoutRef<typeof Menu.Label>
>(({ className, ...props }, ref) => (
    <Menu.Label
        ref={ref}
        className={cn(
            'px-3 py-1 text-xs font-semibold text-muted-foreground uppercase',
            className,
        )}
        {...props}
    />
));
DropdownMenuLabel.displayName = 'DropdownMenuLabel';

export const DropdownMenuSeparator = forwardRef<
    ElementRef<typeof Menu.Separator>,
    ComponentPropsWithoutRef<typeof Menu.Separator>
>(({ className, ...props }, ref) => (
    <Menu.Separator ref={ref} className={cn('my-1 h-px bg-border', className)} {...props} />
));
DropdownMenuSeparator.displayName = 'DropdownMenuSeparator';
