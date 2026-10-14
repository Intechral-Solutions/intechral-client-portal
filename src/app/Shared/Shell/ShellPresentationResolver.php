<?php

namespace App\Shared\Shell;

use App\Models\User;
use App\Shared\Permissions\PermissionCatalogue;
use Illuminate\Http\Request;

/**
 * The one authority on which shell presentation a request renders (EPIC-017 §8.2, plan choice P1).
 *
 * **Rule.** A user who currently holds ANY operator-surface capability gets `Operational`; everyone
 * else gets `Focused`. A guest has no capabilities and keeps the Operational frame (an empty rail on
 * the one guest Blade page, `errors/403`), exactly as before this resolver existed.
 *
 * **What it is not.** It reads capabilities through the Gate and never a role name, adds no
 * permission, and is never consulted for authorization: no route, middleware, policy or query reads it
 * (`ShellSeamContractTest` pins the readers). A mixed-capability user is Operational because the rail
 * is the only shell that can reach the surfaces they hold.
 *
 * **Cost.** `can()` answers from the user's loaded roles and permissions (Spatie hydrates them once per
 * request, and `NavigationBuilder` and `auth.permissions` already require that), so resolving adds no
 * query. `forRequest()` memoizes on the request itself, so there is no static state and a reused
 * instance can never serve another request's answer.
 *
 * **Drift.** `ShellPresentationDriftGuardTest` proves every capability is in exactly one of three
 * sets: the customer defaults, `OPERATIONAL`, or `PRESENTATION_NEUTRAL`. A capability that gates an
 * operator, admin, CRM or System surface and is in neither of the last two fails that test until a
 * human classifies it.
 */
final class ShellPresentationResolver
{
    /**
     * Capabilities that gate an operator-surface route or unlock an operator-only navigation entry
     * or row scope. Holding any one of them selects the Operational shell.
     *
     * `settings.view` gates no surface today; it is kept as approved so the first Settings surface
     * cannot silently render a customer a top bar they cannot navigate from.
     *
     * @var list<string>
     */
    public const OPERATIONAL = [
        PermissionCatalogue::TICKETS_ASSIGN,
        PermissionCatalogue::CRM_MANAGE,
        PermissionCatalogue::BILLING_MANAGE,
        PermissionCatalogue::USERS_VIEW,
        PermissionCatalogue::ROLES_VIEW,
        PermissionCatalogue::SETTINGS_VIEW,
        PermissionCatalogue::CMS_EDIT,
        PermissionCatalogue::TIME_VIEW_ALL,
        PermissionCatalogue::TASKS_VIEW_ALL,
        PermissionCatalogue::PROJECTS_MANAGE,
        PermissionCatalogue::PROJECTS_ADMIN,
    ];

    /**
     * Capabilities that are neither customer defaults nor a reason to switch shell, each with why.
     * They either gate nothing at all today, or only act inside a surface that an
     * `OPERATIONAL` capability already unlocks (so holding one alone reaches no new surface).
     *
     * @var array<string, string>
     */
    public const PRESENTATION_NEUTRAL = [
        PermissionCatalogue::USERS_INVITE => 'Only POST /invitations; its UI sits on the users index, which needs users.view.',
        PermissionCatalogue::USERS_MANAGE => 'Every user-management route also requires users.view.',
        PermissionCatalogue::USERS_ADMIN => 'Defined and never checked anywhere.',
        PermissionCatalogue::ROLES_MANAGE => 'Every role-management route also requires roles.view.',
        PermissionCatalogue::ROLES_ADMIN => 'Role deletion; its routes also require roles.view.',
        PermissionCatalogue::SETTINGS_MANAGE => 'Defined and never checked anywhere (no Settings surface).',
        PermissionCatalogue::TICKETS_ADMIN => 'Defined and never checked anywhere.',
        PermissionCatalogue::PROJECTS_CREATE => 'Defined but not a gate: creation is gated by projects.manage.',
        PermissionCatalogue::BILLING_CREATE => 'InvoicePolicy only, behind routes that require billing.manage.',
        PermissionCatalogue::BILLING_ADMIN => 'InvoicePolicy only (draft deletion), behind billing.manage routes.',
        PermissionCatalogue::TIME_MANAGE => 'Defined and never checked anywhere.',
        PermissionCatalogue::CRM_VIEW => 'Defined and never checked anywhere; CRM routes gate on crm.manage.',
        PermissionCatalogue::CRM_CREATE => 'Defined and never checked anywhere; CRM routes gate on crm.manage.',
        PermissionCatalogue::CRM_ADMIN => 'Defined and never checked anywhere; CRM routes gate on crm.manage.',
        PermissionCatalogue::ORG_ADMIN => 'Defined and never checked anywhere.',
        PermissionCatalogue::ORG_INVITE => 'Defined and never checked anywhere.',
        PermissionCatalogue::ORG_MANAGE_ROLES => 'Defined and never checked anywhere.',
        PermissionCatalogue::CMS_PUBLISH => 'Checked inside operator/cms routes, which already require cms.edit.',
        PermissionCatalogue::CMS_ADMIN => 'Checked inside operator/cms routes, which already require cms.edit.',
        PermissionCatalogue::PORTAL_OPERATOR => 'Customer-surface eligibility (R0.9-CS1): gates no route and selects no shell; the built-in operator role holds every operational capability as well.',
    ];

    public function forUser(?User $user): ShellPresentation
    {
        if ($user === null) {
            return ShellPresentation::Operational;
        }

        foreach (self::OPERATIONAL as $capability) {
            if ($user->can($capability)) {
                return ShellPresentation::Operational;
            }
        }

        return ShellPresentation::Focused;
    }

    /**
     * The presentation for this request, resolved once. The memo is keyed by the user so a request
     * whose user changes mid-flight (a test acting as someone else) is recomputed, not served stale.
     */
    public function forRequest(Request $request): ShellPresentation
    {
        $user = $request->user();
        $identity = $user?->getAuthIdentifier();
        $memo = $request->attributes->get(self::class);

        if (is_array($memo) && $memo[0] === $identity) {
            return $memo[1];
        }

        $presentation = $this->forUser($user);
        $request->attributes->set(self::class, [$identity, $presentation]);

        return $presentation;
    }
}
