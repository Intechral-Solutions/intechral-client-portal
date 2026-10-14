<?php

namespace App\Shared\Pilot;

use App\Http\Middleware\EnforceCustomerPilotSurface;
use App\Models\User;
use App\Shared\Navigation\NavigationBuilder;
use App\Shared\Permissions\PermissionCatalogue;
use Illuminate\Support\Facades\Log;

/**
 * The Release 0.9 customer-surface boundary (R0.9-CS1, docs/architecture/customer-pilot-surface.md).
 *
 * **What it decides.** Whether a request from a customer may reach a route at all, which navigation workspaces and
 * capabilities the browser is told about, and which presentation of the dashboard and of a project a customer
 * receives. It is enforced on the server by {@see EnforceCustomerPilotSurface}; hiding navigation
 * is never the control.
 *
 * **Who is a customer.** Any signed-in user who does not hold `portal.operator`
 * ({@see PermissionCatalogue::PORTAL_OPERATOR}). That is the ONLY test. It is explicit operator eligibility, a
 * privileged designation granted to the built-in `operator` role by `roles:sync` and to a custom role only by an
 * authorized operator. No other permission implies it: a user holding `settings.view`, `tasks.view_all` or every
 * other operational capability without it is a customer. A guest, and an unauthenticated external caller (the Stripe
 * webhook), is never touched.
 *
 * **Mode** ({@see CustomerSurfaceMode}) is resolved here and nowhere else, and fails closed: a missing, null, empty,
 * whitespace-only, boolean, non-string or unrecognised value is `closed`; `legacy` is honoured only in the `local` and
 * `testing` environments and resolves to `closed` (with a warning) anywhere else.
 *
 * **Allowlists.** Exact route names, per mode. An unlisted, unnamed or newly added route is denied to a customer until
 * a human adds it here and the pinned test changes.
 */
final class CustomerPilotGate
{
    /**
     * Routes a customer may reach in every mode but legacy: authentication, account management and sign-out, plus the
     * entry points (`/`, which only redirects, and the dashboard, which is the "not open yet" page in closed mode).
     * route name => the extra capability the gate requires (null when the route and its policy already decide).
     *
     * @var array<string, string|null>
     */
    public const ACCOUNT_ROUTES = [
        // Entry
        'root' => null,
        'dashboard' => null,

        // Account essentials: sign out, profile, password, two-factor, session management.
        'logout' => null,
        'profile.show' => null,
        'profile.password.confirm' => null,
        'profile.sessions.destroy' => null,
        'profile.social.unlink' => null,
        'password.confirm' => null,
        'password.confirm.store' => null,
        'password.confirmation' => null,
        'user-password.update' => null,
        'user-profile-information.update' => null,
        'two-factor.enable' => null,
        'two-factor.confirm' => null,
        'two-factor.disable' => null,
        'two-factor.qr-code' => null,
        'two-factor.secret-key' => null,
        'two-factor.recovery-codes' => null,
        'two-factor.regenerate-recovery-codes' => null,

        // Guest authentication routes. Their own `guest` middleware turns a signed-in user away.
        'login' => null,
        'login.store' => null,
        'password.request' => null,
        'password.email' => null,
        'password.reset' => null,
        'password.update' => null,
        'two-factor.login' => null,
        'two-factor.login.store' => null,
        'invitation.show' => null,
        'invitation.register' => null,
    ];

    /**
     * What pilot mode adds: the Helpdesk and the customer's own projects.
     *
     * @var array<string, string|null>
     */
    public const PILOT_ROUTES = [
        // The routes' own `can:` middleware and TicketPolicy still decide.
        'tickets.index' => null,
        'tickets.show' => null,
        'tickets.create' => null,
        'tickets.store' => null,
        'tickets.replies.store' => null,
        'tickets.attachment.download' => null,

        // The list and the overview, in the customer presentation only.
        'projects.index' => PermissionCatalogue::PROJECTS_VIEW,
        'projects.show' => PermissionCatalogue::PROJECTS_VIEW,
    ];

    /**
     * The navigation workspaces a customer is offered (keys of {@see NavigationBuilder}).
     *
     * @var array<string, list<string>>
     */
    public const WORKSPACES = [
        'closed' => ['home'],
        'pilot' => ['home', 'helpdesk', 'projects'],
    ];

    /**
     * The capabilities the browser is told a customer holds. The React shell keys its chrome off `auth.permissions`
     * (the timer provider polls when it sees `time.log`), so the list is cut to what the mode's surface uses.
     *
     * @var array<string, list<string>>
     */
    public const EXPOSED_PERMISSIONS = [
        'closed' => [],
        'pilot' => [
            PermissionCatalogue::TICKETS_VIEW,
            PermissionCatalogue::TICKETS_CREATE,
            PermissionCatalogue::PROJECTS_VIEW,
        ],
    ];

    /** Environments in which `legacy` is honoured. */
    public const LEGACY_ENVIRONMENTS = ['local', 'testing'];

    /** The raw value a legacy warning was last logged for, so a worker logs it once per value, not once per request. */
    private static ?string $warnedFor = null;

    /**
     * The effective mode. Fails closed on every input.
     */
    public static function mode(): CustomerSurfaceMode
    {
        $raw = config('portal.customer_surface_mode', 'closed');

        // env() turns "true", "false", "null", "empty" and their parenthesised forms into bool, null and ''; none of
        // them names a mode, so any non-string is closed.
        if (! is_string($raw)) {
            return CustomerSurfaceMode::Closed;
        }

        $mode = CustomerSurfaceMode::tryFrom(strtolower(trim($raw)));

        if ($mode === CustomerSurfaceMode::Legacy && ! app()->environment(self::LEGACY_ENVIRONMENTS)) {
            self::warnLegacyRefused($raw);

            return CustomerSurfaceMode::Closed;
        }

        return $mode ?? CustomerSurfaceMode::Closed;
    }

    /** Operator eligibility: the explicit `portal.operator` capability, nothing inferred. */
    public static function isOperator(?User $user): bool
    {
        return $user !== null && $user->can(PermissionCatalogue::PORTAL_OPERATOR);
    }

    /** A signed-in user without operator eligibility. */
    public static function isCustomer(?User $user): bool
    {
        return $user !== null && ! self::isOperator($user);
    }

    /**
     * Whether the boundary applies to this actor right now: not legacy, and a customer. The mode is decided first
     * because it costs nothing, while the eligibility check loads the account's roles and permissions: legacy mode and
     * a guest must not pay for it.
     */
    public static function restricts(?User $user): bool
    {
        return $user !== null && self::mode() !== CustomerSurfaceMode::Legacy && self::isCustomer($user);
    }

    /** SSO is deferred for customers in every mode but legacy, and an invitation always creates a customer. */
    public static function ssoDeferred(): bool
    {
        return self::mode() !== CustomerSurfaceMode::Legacy;
    }

    /** @return array<string, string|null> the allowlist for a mode (empty for legacy, which restricts nothing) */
    public static function routes(CustomerSurfaceMode $mode): array
    {
        return match ($mode) {
            CustomerSurfaceMode::Closed => self::ACCOUNT_ROUTES,
            CustomerSurfaceMode::Pilot => self::ACCOUNT_ROUTES + self::PILOT_ROUTES,
            CustomerSurfaceMode::Legacy => [],
        };
    }

    public static function allowsRoute(?string $routeName): bool
    {
        return $routeName !== null && array_key_exists($routeName, self::routes(self::mode()));
    }

    /** The extra capability an allowed route requires of a customer, or null. */
    public static function requiredCapability(string $routeName): ?string
    {
        return self::routes(self::mode())[$routeName] ?? null;
    }

    /** @return list<string> */
    public static function workspaces(): array
    {
        return self::WORKSPACES[self::mode()->value] ?? [];
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public static function exposedPermissions(?User $user, array $permissions): array
    {
        if (! self::restricts($user)) {
            return $permissions;
        }

        return array_values(array_intersect($permissions, self::EXPOSED_PERMISSIONS[self::mode()->value] ?? []));
    }

    /** Forget which value was warned about (tests). */
    public static function forgetWarnings(): void
    {
        self::$warnedFor = null;
    }

    private static function warnLegacyRefused(string $raw): void
    {
        if (self::$warnedFor === $raw) {
            return;
        }

        self::$warnedFor = $raw;

        Log::warning('PORTAL_CUSTOMER_SURFACE_MODE=legacy is only honoured in the local and testing environments; resolving to "closed".', [
            'environment' => app()->environment(),
        ]);
    }
}
