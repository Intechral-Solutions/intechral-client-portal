<?php

namespace App\Http\Presenters;

use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationListFacts;
use App\Support\Organizations\PortalState;

/**
 * The one header of the Organization workspace (Release 1.0, ORG-3b): what every page of an Organization (Overview, People, Portal access) says about it, and the
 * routed page navigation between them. Built once, here, so the three pages cannot drift apart and no page decides a header fact itself.
 *
 * What it carries, and for whom:
 *  - the name, the Classification (a Directory label, shown only to an actor who already passed `manageDirectory` on the page) and the portal STATE
 *    ({@see PortalState}, drawn by the one badge); the Directory columns (website, phone, address, notes) are never in the header;
 *  - the partner and "managed by" facts only for an actor who passes `manageRelationships` (DIR-0 invariant S7). They come from
 *    {@see OrganizationListFacts::relationships()}, which returns null, reading nothing, for anyone else;
 *  - the tabs. Each destination here is one every viewer of this header may open (every tab asks `manageDirectory`, which the page already passed), so no
 *    tab leads to a refusal. There is no Projects tab: it is ORG-3c's, and a tab that does not exist is not drawn as a placeholder.
 *
 * It reads no member, invitation, approval or Project datum: those are built by the page that shows them, for the ability that shows them.
 */
final class OrganizationWorkspacePresenter
{
    public const OVERVIEW = 'overview';

    public const PEOPLE = 'people';

    public const PORTAL = 'portal';

    /**
     * @param  string  $current  one of the tab constants: the page being drawn
     * @return array{name: string, classification: string, state: PortalState, relationship: array{managedBy: array{id: int, name: string}|null, isPartner: bool, manages: int}|null, tabs: list<array{key: string, label: string, href: string}>, current: string}
     */
    public static function header(User $actor, Organization $organization, string $current): array
    {
        $facts = OrganizationListFacts::relationships($actor, [$organization->getKey()]);

        return [
            'name' => $organization->name,
            'classification' => $organization->classification->label(),
            'state' => $organization->portalState(),
            'relationship' => $facts[(int) $organization->getKey()] ?? null,
            'tabs' => [
                ['key' => self::OVERVIEW, 'label' => 'Overview', 'href' => route('organizations.show', $organization)],
                ['key' => self::PEOPLE, 'label' => 'People', 'href' => route('organizations.people', $organization)],
                ['key' => self::PORTAL, 'label' => 'Portal access', 'href' => route('organizations.portal', $organization)],
            ],
            'current' => $current,
        ];
    }
}
