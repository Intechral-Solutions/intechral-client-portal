import * as Menu from '@radix-ui/react-dropdown-menu';
import type { ComponentPropsWithoutRef, ElementRef } from 'react';
import { forwardRef } from 'react';

import { cn } from '@/lib/utils';

/*
 * Direction D level-2 elevation: `shadow-overlay` on `surface`, radius 8 (the shadow carries the
 * 1px edge, so there is no border). The highlighted row uses `surface-hover` and is also the
 * roving keyboard focus; Radix supplies the menu semantics, typeahead and arrow-key navigation.
 * Disabled rows drop to `text-muted` (their label is still information) and stay in the DOM with `aria-disabled` / `data-disabled`.
 */
export const DropdownMenu = Menu.Root;
export const DropdownMenuTrigger = Menu.Trigger;
export const DropdownMenuGroup = Menu.Group;
/** Radio rows inside a menu (`group` > `menuitemradio`), so they join the menu's arrow-key focus. */
export const DropdownMenuRadioGroup = Menu.RadioGroup;
export const DropdownMenuRadioItem = Menu.RadioItem;
/** Renders its children only while its radio item is checked: the visible half of the checked state. */
export const DropdownMenuItemIndicator = Menu.ItemIndicator;

export const DropdownMenuContent = forwardRef<
    ElementRef<typeof Menu.Content>,
    ComponentPropsWithoutRef<typeof Menu.Content>
>(({ className, sideOffset = 6, ...props }, ref) => (
    <Menu.Portal>
        <Menu.Content
            ref={ref}
            sideOffset={sideOffset}
            className={cn(
                'z-50 min-w-40 rounded-overlay bg-surface p-1 text-text shadow-overlay data-[state=closed]:animate-menu-out data-[state=open]:animate-menu-in',
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
            'cursor-pointer rounded-control px-3 py-2 text-sm focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus data-[disabled]:pointer-events-none data-[disabled]:text-text-muted data-[highlighted]:bg-surface-hover',
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
            'px-3 py-1 text-xs font-semibold text-text-muted uppercase',
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
    <Menu.Separator ref={ref} className={cn('my-1 h-px bg-rule', className)} {...props} />
));
DropdownMenuSeparator.displayName = 'DropdownMenuSeparator';
