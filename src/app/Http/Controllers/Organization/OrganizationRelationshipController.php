<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\OrganizationRelationships;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Operator-only: designate partner organizations and link customer organizations to them (Release 1.0, MO-1A). An
 * administrative relationship; it grants no access to anything.
 *
 * Authorization is the FIRST statement of every action: OrganizationPolicy::manageRelationships needs `portal.operator`
 * AND `crm.manage`. The organizations are then resolved by id, deliberately unscoped, and never through route-model binding:
 * the tenant scope still keys on the `operator` role name and would answer 404 for a custom-role operator, and, worse, would
 * let a scoped lookup stand in for an integrity decision. The service re-checks the policy and every rule under lock.
 */
class OrganizationRelationshipController extends Controller
{
    public function __construct(private readonly OrganizationRelationships $relationships) {}

    public function designate(Request $request, int $organizationId): RedirectResponse
    {
        $this->authorize('manageRelationships', Organization::class);
        $organization = $this->organization($organizationId);

        $changed = $this->relationships->designatePartner($organization, $request->user());

        return $this->back($organization, $changed
            ? "\"{$organization->name}\" is now a partner."
            : "\"{$organization->name}\" is already a partner.");
    }

    public function remove(Request $request, int $organizationId): RedirectResponse
    {
        $this->authorize('manageRelationships', Organization::class);
        $organization = $this->organization($organizationId);

        $changed = $this->relationships->removePartner($organization, $request->user());

        return $this->back($organization, $changed
            ? "\"{$organization->name}\" is no longer a partner."
            : "\"{$organization->name}\" is not a partner.");
    }

    public function assign(Request $request, int $organizationId): RedirectResponse
    {
        $this->authorize('manageRelationships', Organization::class);
        $organization = $this->organization($organizationId);

        $data = $request->validate([
            'partner_id' => ['required', 'integer', Rule::exists('organizations', 'id')],
            'reassign' => ['sometimes', 'boolean'],
        ]);

        // `reassign` is sent only by the "Reassign" form, i.e. when the operator saw a current partner and chose to move
        // the customer. Without it this is a link, and a customer another partner now manages is refused.
        $partner = $this->organization((int) $data['partner_id']);
        $changed = $this->relationships->assignCustomer($organization, $partner, $request->user(), (bool) ($data['reassign'] ?? false));

        return $this->back($organization, $changed
            ? "\"{$organization->name}\" is now managed by \"{$partner->name}\"."
            : "\"{$organization->name}\" is already managed by \"{$partner->name}\".");
    }

    /** From the partner's own page: the id in the path is the PARTNER, the customer is in the body. Same service call as assign(). */
    public function link(Request $request, int $organizationId): RedirectResponse
    {
        $this->authorize('manageRelationships', Organization::class);
        $partner = $this->organization($organizationId);

        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('organizations', 'id')],
        ]);

        $customer = $this->organization((int) $data['customer_id']);
        $changed = $this->relationships->assignCustomer($customer, $partner, $request->user());

        return $this->back($partner, $changed
            ? "\"{$customer->name}\" is now managed by \"{$partner->name}\"."
            : "\"{$customer->name}\" is already managed by \"{$partner->name}\".");
    }

    public function unlink(Request $request, int $organizationId): RedirectResponse
    {
        $this->authorize('manageRelationships', Organization::class);
        $organization = $this->organization($organizationId);

        $changed = $this->relationships->unlinkCustomer($organization, $request->user());

        return $this->back($organization, $changed
            ? "\"{$organization->name}\" is no longer managed by a partner."
            : "\"{$organization->name}\" has no managing partner.");
    }

    private function organization(int $id): Organization
    {
        return Organization::withoutGlobalScopes()->findOrFail($id);
    }

    /**
     * Back to the organization's page when THIS actor can open it, otherwise to the organizations index. The check is
     * deliberately scoped: organizations.show still binds through the tenant scope, which keys on the `operator` role name, so a
     * custom-role operator who is not a member would be answered 404 for a change that just succeeded. The scopes are not widened.
     */
    private function back(Organization $organization, string $message): RedirectResponse
    {
        $target = Organization::query()->whereKey($organization->getKey())->exists()
            ? route('organizations.show', $organization)
            : route('organizations.index');

        return redirect($target)->with('success', $message);
    }
}
