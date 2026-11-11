<?php

namespace App\Services;

use App\Models\User;
use App\Support\Helpdesk\TicketAttribution;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Release 1.0 (R1-2): may this authenticated actor create a Ticket for organization O, and which organization is it?
 *
 * The one answer, in one place, so no caller can create a Ticket without it. It decides ONLY creation, and it widens no read:
 * what a person may SEE is still TicketPolicy. Eligibility is **current membership** of the organization, for a customer and an
 * operator alike (an operator is not exempt because of what they can do). Nothing else is consulted: not the membership role,
 * not administrator approval, not a CRM company, not a partner or managed relationship.
 *
 * How it stays true under concurrency. It runs INSIDE the creation transaction, and its decisive reads are LOCKING reads, which in
 * InnoDB return the latest committed row, not the REPEATABLE READ snapshot an ordinary SELECT would give, and which hold the row until
 * the transaction ends. The order is the one {@see OrganizationMembership} uses to change a membership: the organization row first,
 * then the membership row. So a removal that has committed is seen (the request is refused), a removal that has not committed makes this
 * wait (it cannot slip in between the check and the INSERT), and the shared lock on the organization row means a removal that starts
 * after this transaction's check waits for the new Ticket to commit. Same order on both sides, so there is no deadlock.
 * A plain SELECT is used only to NOMINATE the organization when the request names none (nothing is granted by it: the locked read of
 * that organization's membership decides), and a stale nomination can only produce a refusal or a request to choose.
 *
 * Every read is on the tables directly, unscoped: no global scope, cache or earlier Eloquent relation may decide it.
 *
 * Refusals: an actor who may not create at all (suspended, or without `tickets.create`) is a 403. Everything about WHICH organization is
 * a 422 on `organization_id`, and a named organization that does not exist and one the actor is not a member of produce the SAME message,
 * so the field cannot be used to learn which organizations exist.
 */
final class ServiceRequestAccess
{
    public const NOT_AVAILABLE = 'That organization is not available for this request.';

    public const NOT_LINKED = 'Your account is not linked to an organization, so a request cannot be raised yet. Contact Intechral.';

    public const CHOOSE = 'Choose the organization this request is for.';

    /**
     * @param  int|null  $requestedOrganizationId  the organization the actor named, or null to let the server pick the only one
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function attributionFor(User $actor, ?int $requestedOrganizationId): TicketAttribution
    {
        // The locks only mean anything inside the transaction that INSERTs the Ticket; outside one they would be released at once.
        if (DB::transactionLevel() < 1) {
            throw new LogicException('ServiceRequestAccess must run inside the Ticket creation transaction.');
        }

        $this->authorizeActor($actor);

        $organizationId = $requestedOrganizationId ?? $this->soleOrganization($actor);

        // 1. The organization row, shared lock: a removal holds it exclusively, so it is either finished (and seen below) or must wait.
        $organization = DB::table('organizations')->where('id', $organizationId)->sharedLock()->first(['id']);

        if ($organization === null) {
            throw $this->refusal($requestedOrganizationId === null ? self::NOT_LINKED : self::NOT_AVAILABLE);
        }

        // 2. This actor's membership of it, shared lock: the current committed row, not a snapshot.
        $member = DB::table('organization_members')
            ->where('organization_id', $organization->id)->where('user_id', $actor->getKey())
            ->sharedLock()->first(['id']);

        if ($member === null) {
            throw $this->refusal($requestedOrganizationId === null ? self::NOT_LINKED : self::NOT_AVAILABLE);
        }

        // The submitter is the authenticated actor and nobody else: never an input.
        return new TicketAttribution((int) $organization->id, (int) $actor->getKey());
    }

    /**
     * The organizations this actor could file for RIGHT NOW, for the create page to offer: id and name, ordered by name. A convenience for
     * display only (an ordinary read, no lock): what the actor chose is decided again by {@see attributionFor()} inside the creation
     * transaction, so a stale page can only produce a refusal. It reads the actor's own membership rows and nothing else (no scope, no
     * relationship, no role), so it discloses no organization the actor does not belong to.
     *
     * @return list<array{id: int, name: string}>
     */
    public function eligibleOrganizations(User $actor): array
    {
        return DB::table('organization_members')
            ->join('organizations', 'organizations.id', '=', 'organization_members.organization_id')
            ->where('organization_members.user_id', $actor->getKey())
            ->orderBy('organizations.name')->orderBy('organizations.id')
            ->get(['organizations.id', 'organizations.name'])
            ->map(fn (object $row) => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();
    }

    /** The account is active NOW (read from the table, not the loaded model) and holds the creation capability. */
    private function authorizeActor(User $actor): void
    {
        $row = DB::table('users')->where('id', $actor->getKey())->first(['disabled_at']);

        if ($row === null || $row->disabled_at !== null || ! $actor->can('tickets.create')) {
            throw new AuthorizationException('This account may not raise a request.');
        }
    }

    /**
     * The organization to lock first when the request names none: the actor's only membership. Zero memberships and several are both
     * refused here, before any lock. This is a NOMINATION from an ordinary read; the locked reads that follow decide.
     */
    private function soleOrganization(User $actor): int
    {
        $organizations = DB::table('organization_members')->where('user_id', $actor->getKey())->orderBy('organization_id')->pluck('organization_id');

        return match ($organizations->count()) {
            0 => throw $this->refusal(self::NOT_LINKED),
            1 => (int) $organizations->first(),
            default => throw $this->refusal(self::CHOOSE),
        };
    }

    private function refusal(string $message): ValidationException
    {
        return ValidationException::withMessages(['organization_id' => $message]);
    }
}
