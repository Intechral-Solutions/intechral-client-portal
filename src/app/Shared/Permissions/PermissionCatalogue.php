<?php

namespace App\Shared\Permissions;

/**
 * Single source of truth for all platform permissions.
 *
 * Permissions follow the convention: {module}.{action}
 * Each module registers its own constant group.
 *
 * To add permissions for a new module:
 *   1. Add a constant block below.
 *   2. Add the constants to the ALL array.
 *   3. If the built-in `user` role should have it, add it to userDefaults(). `operator` carries the whole catalogue.
 *   4. Preview and apply the change with `php artisan roles:sync` (App\Shared\Permissions\BuiltInRoles owns the definitions).
 */
final class PermissionCatalogue
{
    // ── Users & Invitations ────────────────────────────────
    const USERS_VIEW = 'users.view';

    const USERS_INVITE = 'users.invite';

    const USERS_MANAGE = 'users.manage';

    const USERS_ADMIN = 'users.admin';

    // ── Roles ──────────────────────────────────────────────
    const ROLES_VIEW = 'roles.view';

    const ROLES_MANAGE = 'roles.manage';

    const ROLES_ADMIN = 'roles.admin';

    // ── Settings ───────────────────────────────────────────
    const SETTINGS_VIEW = 'settings.view';

    const SETTINGS_MANAGE = 'settings.manage';

    // ── Tickets ────────────────────────────────────────────
    const TICKETS_VIEW = 'tickets.view';

    const TICKETS_CREATE = 'tickets.create';

    const TICKETS_ASSIGN = 'tickets.assign';

    const TICKETS_ADMIN = 'tickets.admin';

    const TICKETS_VIEW_ORG = 'tickets.view_org';

    // ── Projects ───────────────────────────────────────────
    const PROJECTS_VIEW = 'projects.view';

    const PROJECTS_CREATE = 'projects.create';

    const PROJECTS_MANAGE = 'projects.manage';

    const PROJECTS_ADMIN = 'projects.admin';

    const PROJECTS_VIEW_ORG = 'projects.view_org';

    // ── Tasks ──────────────────────────────────────────────
    /**
     * Inert since EPIC-014 WP3: the "My organization" view it gated is retired (Q5). Kept in the
     * catalogue and the user defaults as recorded permission debt (EPIC-014 §22 P1).
     */
    const TASKS_VIEW_ORG = 'tasks.view_org';

    /**
     * Offers the All Tasks view (EPIC-014 Q3). A surface capability only: the rows inside it are
     * still exactly the ones the actor may view, never other users' standalone tasks.
     */
    const TASKS_VIEW_ALL = 'tasks.view_all';

    // ── Billing ────────────────────────────────────────────
    const BILLING_VIEW = 'billing.view';

    const BILLING_CREATE = 'billing.create';

    const BILLING_MANAGE = 'billing.manage';

    const BILLING_ADMIN = 'billing.admin';

    const BILLING_VIEW_ORG = 'billing.view_org';

    // ── Time Tracking ──────────────────────────────────────
    const TIME_LOG = 'time.log';

    const TIME_VIEW_OWN = 'time.view_own';

    const TIME_VIEW_ALL = 'time.view_all';

    const TIME_MANAGE = 'time.manage';

    const TIME_VIEW_ORG = 'time.view_org';

    // ── CRM ────────────────────────────────────────────────
    const CRM_VIEW = 'crm.view';

    const CRM_CREATE = 'crm.create';

    const CRM_MANAGE = 'crm.manage';

    const CRM_ADMIN = 'crm.admin';

    // ── Organizations (within CRM) ─────────────────────────
    const ORG_ADMIN = 'org.admin';

    const ORG_INVITE = 'org.invite';

    const ORG_MANAGE_ROLES = 'org.manage_roles';

    // ── Portal ─────────────────────────────────────────────
    /**
     * Operator ELIGIBILITY for the customer-surface boundary (R0.9-CS1): the one capability that exempts an account
     * from the customer restrictions. It is a trust designation, not a permission to do anything: every endpoint still
     * checks its own capability. The built-in `operator` role carries it through `roles:sync`; the built-in `user`
     * role never does, and no other permission implies it.
     */
    const PORTAL_OPERATOR = 'portal.operator';

    // ── CMS ────────────────────────────────────────────────
    const CMS_VIEW = 'cms.view';

    const CMS_EDIT = 'cms.edit';

    const CMS_PUBLISH = 'cms.publish';

    const CMS_ADMIN = 'cms.admin';

    /**
     * All permissions — used by PermissionSeeder.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [
            // Users
            self::USERS_VIEW, self::USERS_INVITE, self::USERS_MANAGE, self::USERS_ADMIN,
            // Roles
            self::ROLES_VIEW, self::ROLES_MANAGE, self::ROLES_ADMIN,
            // Settings
            self::SETTINGS_VIEW, self::SETTINGS_MANAGE,
            // Tickets
            self::TICKETS_VIEW, self::TICKETS_CREATE, self::TICKETS_ASSIGN, self::TICKETS_ADMIN, self::TICKETS_VIEW_ORG,
            // Projects
            self::PROJECTS_VIEW, self::PROJECTS_CREATE, self::PROJECTS_MANAGE, self::PROJECTS_ADMIN, self::PROJECTS_VIEW_ORG,
            // Tasks
            self::TASKS_VIEW_ORG, self::TASKS_VIEW_ALL,
            // Billing
            self::BILLING_VIEW, self::BILLING_CREATE, self::BILLING_MANAGE, self::BILLING_ADMIN, self::BILLING_VIEW_ORG,
            // Time
            self::TIME_LOG, self::TIME_VIEW_OWN, self::TIME_VIEW_ALL, self::TIME_MANAGE, self::TIME_VIEW_ORG,
            // CRM
            self::CRM_VIEW, self::CRM_CREATE, self::CRM_MANAGE, self::CRM_ADMIN,
            // Organizations
            self::ORG_ADMIN, self::ORG_INVITE, self::ORG_MANAGE_ROLES,
            // CMS
            self::CMS_VIEW, self::CMS_EDIT, self::CMS_PUBLISH, self::CMS_ADMIN,
            // Portal
            self::PORTAL_OPERATOR,
        ];
    }

    /**
     * What the built-in 'user' role carried through Release 0.9 and no longer does (R1-1). They stay in the catalogue: an
     * authorized operator may still grant `billing.view` or `time.log` to a direct customer explicitly, so they remain
     * customer-safe capabilities (the pilot surface gate still refuses the pages behind them). The `*.view_org` ones are read
     * by no code path.
     */
    public const RETIRED_USER_DEFAULTS = [
        self::TICKETS_VIEW_ORG,
        self::PROJECTS_VIEW_ORG,
        self::TASKS_VIEW_ORG,
        self::BILLING_VIEW,
        self::BILLING_VIEW_ORG,
        self::TIME_LOG,
        self::TIME_VIEW_OWN,
        self::TIME_VIEW_ORG,
    ];

    /**
     * The 'user' role exactly as Release 0.9 defined it, for the migration rehearsal baseline, which models a 0.9 database
     * and must not drift with the current defaults.
     *
     * @return string[]
     */
    public static function release09UserDefaults(): array
    {
        return [
            self::TICKETS_VIEW,
            self::TICKETS_CREATE,
            self::TICKETS_VIEW_ORG,
            self::PROJECTS_VIEW,
            self::PROJECTS_VIEW_ORG,
            self::TASKS_VIEW_ORG,
            self::BILLING_VIEW,
            self::BILLING_VIEW_ORG,
            self::TIME_LOG,
            self::TIME_VIEW_OWN,
            self::TIME_VIEW_ORG,
            self::CMS_VIEW,
        ];
    }

    /**
     * Permissions granted to the built-in 'user' (platform user) role: the Release 1.0 customer minimum, own tickets and
     * member projects only (R1-1). It deliberately carries no billing, time or organization-wide permission, so a
     * managed customer organization never inherits one. A direct customer that should see its own invoices or log time is
     * granted `billing.view` / `time.log` through a custom role by an authorized operator; the existing functionality
     * behind those capabilities is unchanged. The `*.view_org` permissions stay in the catalogue as recorded debt (no
     * code path reads them) but are no longer defaults.
     *
     * @return string[]
     */
    public static function userDefaults(): array
    {
        return [
            self::TICKETS_VIEW,
            self::TICKETS_CREATE,
            self::PROJECTS_VIEW,
            self::CMS_VIEW,
        ];
    }
}
