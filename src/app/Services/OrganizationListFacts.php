<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Support\Organizations\PortalState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only facts the two Organization lists show about portal organizations (Release 1.0, DIR-1). It writes nothing, takes no lock and
 * reads the tables directly, never through the `Organization` model: the tenant scope would silently drop rows for an account that is not
 * operator-eligible, and a list that undercounts is a wrong answer, not a shorter one (the same reason {@see OrganizationRelationships}
 * keeps its readers unscoped).
 *
 * TWO methods, two different sensitivities:
 *
 *  - {@see portalStatus()} is ordinary organization state (enabled or disabled) and is not gated here; the pages that call it are already
 *    behind `crm.manage`.
 *  - {@see relationships()} is a MANAGING-RELATIONSHIP fact (DIR-0 invariant S7). It is built only for an actor who passes
 *    `manageRelationships` (`portal.operator` AND `crm.manage`, active) and returns null for anyone else, so there is nothing to hide in
 *    the view: an unauthorized actor never causes a manager's name, a customer count or any derived value to be read, let alone rendered.
 *    The check lives here, not only in the controller, so a new caller cannot forget it.
 */
final class OrganizationListFacts
{
    public const ENABLED = 'enabled';

    public const DISABLED = 'disabled';

    /**
     * Whether each portal organization is enabled or disabled, keyed by organization id. An id with no row is absent from the result (the
     * caller treats that as "not enabled", which for a Directory profile with no portal organization is exactly right). ORG-1: so is an
     * Organization row that has never had portal access ({@see PortalState::NotEnabled}): a row existing is not portal access.
     *
     * @param  list<int|null>  $organizationIds
     * @return array<int, string>
     */
    public static function portalStatus(array $organizationIds): array
    {
        $ids = self::ids($organizationIds);

        if ($ids === []) {
            return [];
        }

        $status = [];

        foreach (DB::table('organizations')->whereIn('id', $ids)->get(['id', ...PortalState::columns()]) as $row) {
            $state = PortalState::of($row);

            if (! $state->isNotEnabled()) {
                $status[(int) $row->id] = $state->isEnabled() ? self::ENABLED : self::DISABLED;
            }
        }

        return $status;
    }

    /**
     * "Managed by" and "Manages N" for each organization, keyed by organization id, or NULL when the actor may not manage relationships.
     * Two bounded queries however many ids are asked for.
     *
     * @param  list<int|null>  $organizationIds
     * @return array<int, array{managedBy: array{id: int, name: string}|null, isPartner: bool, manages: int}>|null
     */
    public static function relationships(User $actor, array $organizationIds): ?array
    {
        if (! Gate::forUser($actor)->allows('manageRelationships', Organization::class)) {
            return null;
        }

        $ids = self::ids($organizationIds);

        if ($ids === []) {
            return [];
        }

        $counts = DB::table('organizations')
            ->whereIn('managed_by_organization_id', $ids)
            ->selectRaw('managed_by_organization_id as manager_id, count(*) as customers')
            ->groupBy('managed_by_organization_id')
            ->pluck('customers', 'manager_id');

        $facts = [];

        $rows = DB::table('organizations as o')
            ->leftJoin('organizations as manager', 'manager.id', '=', 'o.managed_by_organization_id')
            ->whereIn('o.id', $ids)
            ->get(['o.id', 'o.can_manage_customers', 'manager.id as manager_id', 'manager.name as manager_name']);

        foreach ($rows as $row) {
            $facts[(int) $row->id] = [
                'managedBy' => $row->manager_id === null ? null : ['id' => (int) $row->manager_id, 'name' => (string) $row->manager_name],
                'isPartner' => (bool) $row->can_manage_customers,
                'manages' => (int) ($counts[(int) $row->id] ?? 0),
            ];
        }

        return $facts;
    }

    /**
     * @param  list<int|null>  $ids
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_map('intval', array_filter($ids, fn ($id) => $id !== null))));
    }
}
