<?php

namespace App\Services;

use App\Models\CrmContact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The Directory's People (Release 1.0). A Person is a Directory record of a human with ONE optional affiliation: an Organization. It is not a portal account,
 * and the affiliation is descriptive: it is never read as membership, portal access or authority (owner ruling D7: People are visible only to authorized
 * operators, through CrmContact's own scope).
 *
 * ORG-2 moved everything about the Organization itself (create, edit, classify, delete, initial enablement) to {@see OrganizationDirectory}; the Company
 * operations of this class are gone with the Company. Only the Person operations remain, until DIR-3 gives them their own service.
 *
 * LOCK ORDER. Organization BEFORE Person, always (ORG-0 section 6.1): an affiliation write takes the actor's user row (shared; `created_by` references it),
 * then the Organization row(s) SHARED and re-validates them under that lock (so the Organization cannot be deleted, which takes the row exclusively, between the
 * check and the write, and a missing or invalid one writes nothing), then the Person row.
 *
 * "Always" includes the Organization a Person is LEAVING (CP6, found by the two-connection test of Organization deletion against a Person moving away): the
 * deletion locks the Organization, then finds its People through the `organization_id` INDEX (the index record first, the row after). A writer that took the PERSON
 * row first and then changed `organization_id` (which rewrites that index record) would meet it from the other side, and InnoDB would resolve the cycle by killing
 * one of them. So a change of affiliation, and a Person's deletion, lock the Organization the Person is in NOW as well as the one it is going to, both shared and in
 * ascending id order, BEFORE the Person row. The current affiliation is read before the transaction, can be stale, and is re-checked under the Person lock: if it
 * moved while the request waited, the attempt starts over (at most {@see RESTART_ATTEMPTS} times) rather than hold a lock it did not plan. An update that touches
 * neither the affiliation nor its index takes no Organization lock.
 */
class CrmService
{
    /** Audit event of initial enablement; the event itself is written by {@see OrganizationDirectory::enablePortalAccess()}. Kept for the callers that named it here. */
    public const PORTAL_ENABLED = OrganizationDirectory::PORTAL_ENABLED;

    private const ATTEMPTS = 3;

    /** How often a Person write starts over because its affiliation changed while it waited for the Organization locks. */
    private const RESTART_ATTEMPTS = 5;

    // ── People ────────────────────────────────────────────

    public function createContact(User $creator, array $data): CrmContact
    {
        $organizationId = $this->organizationIdFrom($data['organization_id'] ?? null);

        return DB::transaction(function () use ($creator, $data, $organizationId): CrmContact {
            DB::table('users')->where('id', $creator->getKey())->sharedLock()->value('id');
            $this->lockOrganizationShared($organizationId);

            return CrmContact::create([
                'organization_id' => $organizationId,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'job_title' => $data['job_title'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $creator->id,
            ]);
        }, self::ATTEMPTS);
    }

    public function updateContact(CrmContact $contact, array $data): CrmContact
    {
        $changesAffiliation = array_key_exists('organization_id', $data);
        $organizationId = $changesAffiliation ? $this->organizationIdFrom($data['organization_id']) : null;

        $this->untilStable(fn () => $this->withPersonLocked($contact, $changesAffiliation, $organizationId, function (object $current) use ($contact, $data, $changesAffiliation, $organizationId): void {
            $contact->forceFill((array) $current)->syncOriginal();
            $contact->update([
                'organization_id' => $changesAffiliation ? $organizationId : $contact->organization_id,
                'first_name' => $data['first_name'] ?? $contact->first_name,
                'last_name' => $data['last_name'] ?? $contact->last_name,
                'email' => $data['email'] ?? $contact->email,
                'phone' => $data['phone'] ?? $contact->phone,
                'job_title' => $data['job_title'] ?? $contact->job_title,
                'notes' => $data['notes'] ?? $contact->notes,
            ]);
        }));

        return $contact->fresh();
    }

    public function deleteContact(CrmContact $contact): void
    {
        // Deleting the Person removes its `organization_id` index record: the same index the Organization's deletion walks, so the same order applies.
        $this->untilStable(fn () => $this->withPersonLocked($contact, true, null, function () use ($contact): void {
            $contact->delete();
        }, requireTarget: false));
    }

    // ── Private ───────────────────────────────────────────

    /**
     * One attempt at a Person write under the lock order: the Organization row(s) SHARED (the one the Person is in now and, when `$target` is given, the one it is going
     * to), ascending, in one statement -> the Person row, exclusively -> `$write`. Returns false (nothing written, nothing locked beyond the transaction) when the
     * Person's affiliation, re-read under its lock, is not the one the Organizations were locked for.
     *
     * @param  bool  $touchesAffiliation  whether this write changes or removes the affiliation (and so needs the Organization locks at all)
     * @param  callable(object): void  $write  receives the Person row as read under its lock
     * @param  bool  $requireTarget  whether `$target` must exist (a destination); a deletion has none
     */
    private function withPersonLocked(CrmContact $contact, bool $touchesAffiliation, ?int $target, callable $write, bool $requireTarget = true): bool
    {
        // Read WITHOUT a lock and outside any transaction of its own: a plain read inside the transaction would pin a snapshot older than the locks that follow.
        $now = $touchesAffiliation ? $this->affiliationOf($contact) : null;

        return DB::transaction(function () use ($contact, $touchesAffiliation, $target, $write, $now, $requireTarget): bool {
            if ($touchesAffiliation) {
                $locked = $this->lockOrganizationsShared(array_values(array_filter([$now, $target])));

                if ($requireTarget && $target !== null && ! in_array($target, $locked, true)) {
                    throw ValidationException::withMessages(['organization_id' => 'The selected organization is invalid.']);
                }
            }

            // The Person row after the Organization rows. Re-read: the model the request bound may be stale.
            $current = DB::table('crm_contacts')->where('id', $contact->getKey())->lockForUpdate()->first();
            abort_if($current === null, 404);

            if ($touchesAffiliation && ($current->organization_id === null ? null : (int) $current->organization_id) !== $now) {
                return false;   // it moved while this waited: start over with the right Organizations
            }

            $write($current);

            return true;
        }, self::ATTEMPTS);
    }

    /** The Organization the Person is affiliated with right now (a plain, unlocked read; may be stale by the time the locks are held). */
    private function affiliationOf(CrmContact $contact): ?int
    {
        $row = DB::table('crm_contacts')->where('id', $contact->getKey())->first(['organization_id']);
        abort_if($row === null, 404);

        return $row->organization_id === null ? null : (int) $row->organization_id;
    }

    /**
     * Lock the given Organization rows SHARED, ascending, in ONE statement, and return the ids that exist. A missing id is not an error here: the caller decides
     * (a destination must exist; the Organization a Person is leaving may already be gone).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function lockOrganizationsShared(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        if ($ids === []) {
            return [];
        }

        return DB::table('organizations')->whereIn('id', $ids)->orderBy('id')->sharedLock()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Run an attempt until it reports success. An attempt returns false when it noticed, under its locks, that the Person's affiliation had changed since it looked.
     *
     * @param  callable(): bool  $attempt
     */
    private function untilStable(callable $attempt): void
    {
        for ($tries = 0; $tries < self::RESTART_ATTEMPTS; $tries++) {
            if ($attempt()) {
                return;
            }
        }

        throw new ConflictHttpException('The person kept changing while this request was processed. Please try again.');
    }

    private function organizationIdFrom(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_scalar($value) || ! ctype_digit((string) $value)) {
            throw ValidationException::withMessages(['organization_id' => 'The selected organization is invalid.']);
        }

        return (int) $value;
    }

    /**
     * The Organization row, SHARED, read raw. A missing one is a validation failure with the message every other "no such Organization" uses, and nothing has been
     * written: this runs before any write of the transaction. A shared lock (not exclusive) because an affiliation only needs the Organization to keep existing.
     */
    private function lockOrganizationShared(?int $organizationId): void
    {
        if ($organizationId === null) {
            return;
        }

        if (DB::table('organizations')->where('id', $organizationId)->sharedLock()->value('id') === null) {
            throw ValidationException::withMessages(['organization_id' => 'The selected organization is invalid.']);
        }
    }
}
