import { Link, router } from '@inertiajs/react';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Avatar } from '@/components/ui/avatar';
import { focusRing } from '@/components/ui/control-metrics';
import { useAppearance } from '@/hooks/use-appearance';
import type { Appearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { logout } from '@/routes';
import { show as profile } from '@/routes/profile';
import type { AuthUser } from '@/types';

/**
 * The account menu (Direction D §13, EPIC-013 §17).
 *
 * **Personal only.** Administration lives under the System workspace (L15), and the retired "Manage"
 * grouping is structurally impossible here: this component receives no navigation payload at all, so
 * there is nothing for an administrative entry to arrive through (§12.4).
 *
 * Items 2–4 anchor into the existing `profile/show` sections rather than new routes: splitting that
 * page is Fortify-adjacent product work for a later account epic (§17.2). Meta values ("MFA on",
 * "N active") are deliberately absent — shipping "Not set up" to someone who has MFA on would be
 * worse than shipping nothing, and no shared prop carries the truth.
 */
export function AccountMenu({ user }: { user: AuthUser }) {
    return (
        <DropdownMenu>
            <AccountTrigger user={user} />

            <DropdownMenuContent side="right" align="end" className="w-64">
                <div className="flex items-center gap-2.5 px-3 py-2.5">
                    <Avatar name={user.name} size="lg" decorative />
                    <div className="min-w-0">
                        <p className="truncate text-sm font-medium text-text">{user.name}</p>
                        <p className="truncate text-xs text-text-muted">{user.email}</p>
                    </div>
                </div>

                <DropdownMenuSeparator />

                <DropdownMenuItem asChild>
                    <Link href={profile.url()} className="block">
                        Profile
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <a href={`${profile.url()}#security`} className="block">
                        Security &amp; MFA
                    </a>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <a href={`${profile.url()}#connected-accounts`} className="block">
                        Connected accounts
                    </a>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <a href={`${profile.url()}#sessions`} className="block">
                        Sessions
                    </a>
                </DropdownMenuItem>

                <DropdownMenuSeparator />
                <AppearanceControl />
                <DropdownMenuItem
                    disabled
                    // A disabled row with a reason, never the mockups' FUTURE tag styling as
                    // product chrome (§17.2).
                    aria-disabled={true}
                    className="flex items-center justify-between"
                >
                    <span>Notifications</span>
                    <span className="text-xs text-text-faint">Not available yet</span>
                </DropdownMenuItem>

                <DropdownMenuSeparator />

                <DropdownMenuItem onSelect={() => router.post(logout.url())}>
                    Sign out
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * The rail account control: a 40×40 rounded-square tile containing a 28px circular avatar
 * (Direction D §13.1, L9). Avatar shape never encodes role, so the circle is the same in both
 * shells and the square is the *control*, not the identity.
 */
function AccountTrigger({ user }: { user: AuthUser }) {
    return (
        <DropdownMenuTrigger
            data-shell-account
            aria-label={`Account menu: ${user.name}`}
            className={cn(
                'flex h-10 w-10 shrink-0 items-center justify-center rounded-[6px] bg-surface ring-1 ring-control-edge transition-colors duration-motion-fast hover:bg-surface-hover',
                focusRing,
            )}
        >
            {/* The trigger already carries the accessible name, so the avatar is decorative. */}
            <Avatar name={user.name} size="md" decorative />
        </DropdownMenuTrigger>
    );
}

/**
 * Appearance absorbs the standalone theme toggle the pre-WP4 shell carried in its header (§17.2).
 * Light and Dark only — a System theme is NEXT and is not pulled into this foundation (L6).
 *
 * The options are the menu's own radio items (`group` > `menuitemradio`, as the Blade menu renders
 * them), so they sit in the menu's arrow-key focus. Plain radio buttons inside a menu were
 * unreachable from the keyboard: the menu owns focus, so Arrow keys skipped them and Tab could not
 * reach them (EPIC-013 WP8). Choosing one keeps the menu open, as the toggle it replaced did.
 */
function AppearanceControl() {
    const { appearance, setAppearance } = useAppearance();

    return (
        <div className="px-3 py-2">
            <DropdownMenuRadioGroup
                aria-label="Appearance"
                value={appearance}
                onValueChange={(value) => setAppearance(value as Appearance)}
                className="flex items-center gap-1 rounded-control bg-surface-sunken p-0.5"
            >
                {(['light', 'dark'] as const).map((option) => (
                    <DropdownMenuRadioItem
                        key={option}
                        value={option}
                        onSelect={(event) => event.preventDefault()}
                        className={cn(
                            'flex-1 cursor-pointer rounded-[3px] px-2 py-1 text-center text-xs font-medium capitalize transition-colors duration-motion-fast',
                            'text-text-secondary hover:text-text data-[highlighted]:text-text',
                            'data-[state=checked]:bg-ink data-[state=checked]:text-on-ink',
                            focusRing,
                        )}
                    >
                        {option}
                    </DropdownMenuRadioItem>
                ))}
            </DropdownMenuRadioGroup>
        </div>
    );
}
