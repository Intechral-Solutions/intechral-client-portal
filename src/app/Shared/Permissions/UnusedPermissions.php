<?php

namespace App\Shared\Permissions;

/**
 * Catalogue permissions that no application code checks in this release (DIR-0, DIR-1 section G), each with the one-line reason the role
 * editor shows beside it. PRESENTATION ONLY: the catalogue, the built-in roles and `roles:sync` are unchanged, every permission here stays
 * assignable, and assigning one changes nothing today, which is exactly what the editor now says.
 *
 * The list is a claim about the code, so it is verified rather than trusted: `UnusedPermissionsTest` scans the application, the routes, the
 * views and the React sources for every name below and fails the moment something starts checking one. When a later release gives a
 * permission a use, remove it here in the same change.
 *
 * The shell presentation resolver's list of neutral capabilities also calls `tickets.admin`, `time.manage` and
 * `settings.manage` "never checked". They are deliberately NOT annotated here: they are outside the DIR-0 inventory and this package
 * does not widen it.
 */
final class UnusedPermissions
{
    public const LABEL = 'Not used in this release';

    private const ORGANIZATION = 'Customer-side organization delegation is a later release. Operators administer membership with crm.manage and portal.operator.';

    private const DIRECTORY = 'Directory routes check crm.manage only.';

    private const VIEW_ORG = 'Visibility is decided by organization and project membership; no code reads this permission.';

    /** @return array<string, string> permission name => why it is not used */
    public static function all(): array
    {
        return [
            PermissionCatalogue::ORG_ADMIN => self::ORGANIZATION,
            PermissionCatalogue::ORG_INVITE => self::ORGANIZATION,
            PermissionCatalogue::ORG_MANAGE_ROLES => self::ORGANIZATION,
            PermissionCatalogue::CRM_VIEW => self::DIRECTORY,
            PermissionCatalogue::CRM_CREATE => self::DIRECTORY,
            PermissionCatalogue::CRM_ADMIN => self::DIRECTORY,
            PermissionCatalogue::TICKETS_VIEW_ORG => self::VIEW_ORG,
            PermissionCatalogue::PROJECTS_VIEW_ORG => self::VIEW_ORG,
            PermissionCatalogue::TASKS_VIEW_ORG => self::VIEW_ORG,
            PermissionCatalogue::BILLING_VIEW_ORG => self::VIEW_ORG,
            PermissionCatalogue::TIME_VIEW_ORG => self::VIEW_ORG,
        ];
    }

    /** The reason this permission is not used, or null when it is (or is not catalogued). */
    public static function reason(string $permission): ?string
    {
        return self::all()[$permission] ?? null;
    }
}
