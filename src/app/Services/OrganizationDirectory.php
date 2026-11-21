<?php

namespace App\Services;

use App\Exceptions\OrganizationDirectoryException;
use App\Exceptions\OrganizationRelationshipException;
use App\Models\Organization;
use App\Models\User;
use App\Support\Organizations\Classification;
use App\Support\Organizations\PortalState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The ONE service that creates, edits, classifies, deletes and initially enables an Organization (Release 1.0, ORG-2; ADR-011 sections 1, 2 and 12, ORG-0
 * section 12). There is one Organization entity: a Directory-only Organization (never enabled, no portal authority of any kind) and a portal Organization are the
 * same row in different states, and this class is the only production path that writes the Directory columns or deletes the row (a source-level guard,
 * OrganizationDeletionGuardTest, keeps it so).
 *
 * AUTHORITY. Three abilities, never collapsed (ADR-011 section 2): `manageDirectory` for create, profile edit, Classification and delete; `manageMembership` for
 * initial enablement; `manageRelationships` for disable and re-enable (that one lives in OrganizationRelationships). Each is asked of the Gate FIRST,
 * before any lock, so a call made with an explicit actor (a job, tinker) is held to it exactly like a route; and then asked AGAIN, from the TABLES, inside the
 * transaction under the actor's row lock ({@see OrganizationAuthority}), so a suspension or a revoked capability that committed first is seen. No Gate call and
 * no permission-cache load happens after the first lock. The routes' `can:crm.manage` is only a first gate.
 *
 * LOCK ORDER (one order for every Organization write, so no two of them can deadlock): the actor's user row (shared; `created_by`, `portal_enabled_by` and the
 * admin membership all reference it, and a suspension holds it exclusively) -> the Organization row (exclusive here; shared for an act that only needs it to
 * exist, see CrmService and ProjectService) -> the rows that depend on it (People, project links, members), in that order. A write is always the LAST thing
 * in the transaction. State, authority and eligibility are re-read from the locked rows; a model the caller loaded earlier is never believed (a stale request
 * model cannot overwrite a portal-state column: only the columns this class names are written, by column, never `save()`).
 *
 * CLASSIFICATION is descriptive. It is validated against {@see Classification} here (the column has no CHECK), is never read by a policy, scope or gate, and
 * changing it changes nothing else.
 *
 * AUDIT. Every real change is one entry in the activity log in the same transaction, with the actor and the previous and new values. A no-op writes nothing,
 * not even `updated_at`. The free-text `notes` are never copied into the log: an entry names the field (`internal_fields_changed` / `internal_fields_set`) and carries no value.
 */
final class OrganizationDirectory
{
    public const CREATED = 'organization created';

    public const PROFILE_UPDATED = 'organization profile updated';

    public const CLASSIFICATION_CHANGED = 'organization classification changed';

    public const DELETED = 'organization deleted';

    /** Audit event of initial enablement (subject: the Organization). Was CrmService::PORTAL_ENABLED before ORG-2, which still names it. */
    public const PORTAL_ENABLED = 'organization portal access enabled';

    /** The profile columns an update may change. `name` is required; the others are nullable and an explicit null clears them. */
    private const PROFILE = ['name', 'website', 'phone', 'address', 'notes'];

    /** The widest value each profile column holds (characters). `address` and `notes` are TEXT; these are the SAME ceilings the Directory form validates (CompanyController::validated), kept equal by a test. */
    private const MAX = ['name' => 255, 'website' => 255, 'phone' => 30, 'address' => 1000, 'notes' => 5000];

    /** Deadlock retries of a transaction (the engine picks a victim; the retry finds the settled state). */
    private const ATTEMPTS = 3;

    /** Bounded retries of an insert that loses a slug race (a unique key, never an unbounded loop). */
    private const SLUG_ATTEMPTS = 5;

    /** Taken slug candidates one attempt walks before it falls back to a random suffix. */
    private const SLUG_PROBES = 20;

    /** The column an audit entry names as touched and never copies. */
    private const NOT_LOGGED = 'notes';

    // ── Create ───────────────────────────────────────────────────────────────────────────────

    /**
     * Create a Directory-only Organization: never enabled, no members, no portal authority. `created_by` is the actor. Accepts name (required), website, phone,
     * address, notes and classification (default `general`).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException the actor may not manage the Directory
     * @throws ValidationException the name is empty or the classification is not in the catalogue
     */
    public function create(User $actor, array $data): Organization
    {
        Gate::forUser($actor)->authorize('manageDirectory', Organization::class);

        $values = $this->profileValues($data, creating: true);
        $classification = $this->classificationFrom($data['classification'] ?? Classification::DEFAULT->value);

        return DB::transaction(function () use ($actor, $values, $classification): Organization {
            $this->lockActor($actor);
            $this->requireDirectoryManager($actor);

            $now = now();
            $id = $this->insertWithUniqueSlug($values['name'], [
                ...$values,
                'classification' => $classification->value,
                'created_by' => $actor->getKey(),
                'portal_enabled_at' => null,
                'portal_enabled_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $organization = Organization::withoutGlobalScopes()->findOrFail($id);

            activity()->causedBy($actor)->performedOn($organization)
                ->withProperties([
                    'new' => $this->logged([...$values, 'classification' => $classification->value]),
                    'portal_state' => PortalState::NotEnabled->value,
                    ...$this->internal($values, 'internal_fields_set', set: true),
                ])
                ->log(self::CREATED);

            return $organization;
        }, self::ATTEMPTS);
    }

    // ── Update ───────────────────────────────────────────────────────────────────────────────

    /**
     * Update the profile and/or the Classification. A key that is ABSENT keeps the stored value; an explicit null clears a nullable field (`name` and
     * `classification` cannot be cleared). Only the columns that actually differ are written, and a call that changes nothing writes nothing (no row, no audit
     * entry). The Organization is re-read under its row lock: a stale model neither decides nor overwrites anything, and no portal-state column is ever touched.
     *
     * @param  array<string, mixed>  $data
     * @return bool whether anything changed
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function update(Organization $organization, User $actor, array $data): bool
    {
        Gate::forUser($actor)->authorize('manageDirectory', Organization::class);

        $profile = $this->profileValues($data, creating: false);
        $classification = array_key_exists('classification', $data) ? $this->classificationFrom($data['classification']) : null;

        return DB::transaction(function () use ($organization, $actor, $profile, $classification): bool {
            $this->lockActor($actor);
            $row = $this->lockOrganization((int) $organization->getKey());
            $this->requireDirectoryManager($actor);

            $changes = [];

            foreach ($profile as $column => $value) {
                if ($row->{$column} !== $value) {
                    $changes[$column] = $value;
                }
            }

            $classChange = $classification !== null && $row->classification !== $classification->value ? $classification->value : null;

            if ($changes === [] && $classChange === null) {
                return false;
            }

            DB::table('organizations')->where('id', $row->id)->update([
                ...$changes,
                ...($classChange !== null ? ['classification' => $classChange] : []),
                'updated_at' => now(),
            ]);

            if ($changes !== []) {
                $this->audit($actor, $row->id, self::PROFILE_UPDATED, [
                    'old' => $this->logged(array_intersect_key((array) $row, $changes)),
                    'new' => $this->logged($changes),
                    ...$this->internal($changes, 'internal_fields_changed'),
                ]);
            }

            if ($classChange !== null) {
                $this->audit($actor, $row->id, self::CLASSIFICATION_CHANGED, [
                    'old' => ['classification' => $row->classification],
                    'new' => ['classification' => $classChange],
                ]);
            }

            // Keep the caller's model truthful without marking anything dirty. Only the columns this call wrote.
            $organization->forceFill([...$changes, ...($classChange !== null ? ['classification' => $classChange] : [])])->syncOriginal();

            return true;
        }, self::ATTEMPTS);
    }

    /** Change only the Classification. Returns false (and writes nothing) when it is already that value. */
    public function changeClassification(Organization $organization, User $actor, Classification|string $classification): bool
    {
        return $this->update($organization, $actor, ['classification' => $classification instanceof Classification ? $classification->value : $classification]);
    }

    // ── Delete ───────────────────────────────────────────────────────────────────────────────

    /**
     * Delete a NEVER-ENABLED Organization. The database protects every dependant with RESTRICT (a project link, a member, an invitation, a request, a
     * relationship); this method refuses first, with a business-level message and no side effect, and the foreign key remains the arbiter for anything that
     * slips between the check and the delete. The only dependants it removes are People's affiliations, which it clears deliberately and records.
     *
     * Order: the actor's row (shared) -> the Organization row (exclusive: no project can be linked, no Person affiliated, no member added, no enablement can commit
     * while it is held, because each of those takes the Organization row shared or exclusive FIRST) -> the dependants, read under shared locks -> the writes.
     *
     * @throws AuthorizationException
     * @throws OrganizationDirectoryException a portal history, a project link, or another dependant refuses it
     */
    public function delete(Organization $organization, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageDirectory', Organization::class);

        DB::transaction(function () use ($organization, $actor): void {
            $this->lockActor($actor);
            $row = $this->lockOrganization((int) $organization->getKey());
            $this->requireDirectoryManager($actor);

            if (! PortalState::of($row)->isNotEnabled()) {
                throw OrganizationDirectoryException::hasPortalHistory((string) $row->name);
            }

            if (($projects = $this->count('project_organization', 'organization_id', $row->id)) > 0) {
                throw OrganizationDirectoryException::hasProjects((string) $row->name, $projects);
            }

            $dependants = array_filter([
                'member(s)' => $this->count('organization_members', 'organization_id', $row->id),
                'invitation(s)' => $this->count('organization_invitations', 'organization_id', $row->id),
                'service request(s)' => $this->count('tickets', 'organization_id', $row->id),
                'managed organization(s)' => $this->count('organizations', 'managed_by_organization_id', $row->id),
            ]) + ($row->can_manage_customers || $row->managed_by_organization_id !== null ? ['relationship(s)' => 1] : []);

            if ($dependants !== []) {
                throw OrganizationDirectoryException::hasDependants((string) $row->name, $dependants);
            }

            // People after the Organization, never before it (the lock order). Their affiliation is cleared by this act, not left to the foreign key's SET NULL, so
            // the log can say whose it was.
            $people = DB::table('crm_contacts')->where('organization_id', $row->id)->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all();

            if ($people !== []) {
                DB::table('crm_contacts')->whereIn('id', $people)->update(['organization_id' => null, 'updated_at' => now()]);
            }

            try {
                DB::table('organizations')->where('id', $row->id)->delete();
            } catch (QueryException $e) {
                // MariaDB/MySQL 1451: a row in another table still refers to this one (something linked since the checks).
                if (($e->errorInfo[1] ?? null) === 1451) {
                    throw OrganizationDirectoryException::stillReferenced((string) $row->name);
                }

                throw $e;
            }

            $this->audit($actor, $row->id, self::DELETED, [
                'old' => ['name' => $row->name, 'slug' => $row->slug, 'classification' => $row->classification],
                'unaffiliated_person_ids' => $people,
            ], $organization);
        }, self::ATTEMPTS);
    }

    // ── Initial enablement ───────────────────────────────────────────────────────────────────

    /**
     * Initial enablement of portal access: the one transition Not enabled -> Enabled (ADR-011 section 2). It accepts ONLY a Not enabled Organization:
     *  - already Enabled: refused, controlled `already_enabled`, nothing written (it is not idempotent: a second call would re-record who and when);
     *  - Disabled: refused, with its disabled state untouched (re-enabling is `OrganizationRelationships::enable`, a different ability).
     *
     * AUTHORITY is `manageMembership` (not `manageRelationships`, not `manageDirectory`), asked of the Gate first and again from the tables under the locks.
     * One transaction: the actor's row (shared) -> the Organization row (exclusive, the serialization point) -> the enabling operator's `admin` membership
     * (retained: the operator raise-on-behalf question is still open, ORG-0 section 12). `portal_enabled_at` and the audit entry's `created_at` are one instant.
     *
     * @throws AuthorizationException
     * @throws OrganizationRelationshipException already enabled, or disabled
     */
    public function enablePortalAccess(Organization $organization, User $actor): Organization
    {
        Gate::forUser($actor)->authorize('manageMembership', Organization::class);

        return DB::transaction(function () use ($organization, $actor): Organization {
            $this->lockActor($actor);
            $row = $this->lockOrganization((int) $organization->getKey());

            if (! OrganizationAuthority::isMembershipManager((int) $actor->getKey())) {
                throw new AuthorizationException;
            }

            $state = PortalState::of($row);

            if ($state->isEnabled()) {
                throw OrganizationRelationshipException::alreadyEnabled((string) $row->name);
            }

            if (! $state->isNotEnabled()) {
                throw OrganizationRelationshipException::enablementRefused((string) $row->name, $state);
            }

            $now = now();
            DB::table('organizations')->where('id', $row->id)->update([
                'portal_enabled_at' => $now,
                'portal_enabled_by' => $actor->getKey(),
                'updated_at' => $now,
            ]);

            DB::table('organization_members')->insert([
                'organization_id' => $row->id,
                'user_id' => $actor->getKey(),
                'role' => 'admin',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // The audit entry shares the enablement instant: its `created_at` is `portal_enabled_at`.
            activity()->causedBy($actor)->performedOn($organization)
                ->withProperties([
                    'old' => ['portal_state' => PortalState::NotEnabled->value],
                    'new' => ['portal_state' => PortalState::Enabled->value, 'portal_enabled_at' => $now->toIso8601String(), 'portal_enabled_by' => $actor->getKey()],
                ])
                ->createdAt($now)
                ->log(self::PORTAL_ENABLED);

            $fresh = Organization::withoutGlobalScopes()->findOrFail($row->id);
            $organization->forceFill($fresh->getAttributes())->syncOriginal();

            return $fresh;
        }, self::ATTEMPTS);
    }

    // ── Locks and checks ─────────────────────────────────────────────────────────────────────

    /** The acting operator's user row, shared: the FIRST statement of every transaction here. */
    private function lockActor(User $actor): void
    {
        DB::table('users')->where('id', $actor->getKey())->sharedLock()->value('id');
    }

    /** The Gate answered before the transaction; under the locks the answer comes from the tables (never stale, never a cache load). */
    private function requireDirectoryManager(User $actor): void
    {
        if (! OrganizationAuthority::isDirectoryManager((int) $actor->getKey())) {
            throw new AuthorizationException;
        }
    }

    /** The Organization row, exclusively locked, read raw (no scope, no cast): the state every decision here rests on. */
    private function lockOrganization(int $id): object
    {
        $row = DB::table('organizations')->where('id', $id)->lockForUpdate()->first();
        abort_if($row === null, 404);

        $row->id = (int) $row->id;
        $row->can_manage_customers = (bool) ($row->can_manage_customers ?? false);
        $row->managed_by_organization_id = ($row->managed_by_organization_id ?? null) === null ? null : (int) $row->managed_by_organization_id;

        return $row;
    }

    private function count(string $table, string $column, int $id): int
    {
        return (int) DB::table($table)->where($column, $id)->sharedLock()->count();
    }

    // ── Input ────────────────────────────────────────────────────────────────────────────────

    /**
     * The profile values present in the input, trimmed, with '' read as null for the nullable columns. A key that is absent is absent from the result
     * (it preserves the stored value); `name` must be non-empty when present (and is required to create).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, ?string>
     */
    private function profileValues(array $data, bool $creating): array
    {
        $values = [];

        foreach (self::PROFILE as $column) {
            if (! array_key_exists($column, $data)) {
                if ($creating && $column !== 'name') {
                    $values[$column] = null;
                }

                continue;
            }

            $value = $data[$column];
            $value = $value === null ? null : trim((string) $value);

            if ($column === 'name') {
                if ($value === null || $value === '') {
                    throw ValidationException::withMessages(['name' => 'The name is required.']);
                }
            } elseif ($value === '') {
                $value = null;
            }

            // The column's own width: a value the database would truncate or refuse is refused here, as a validation message, never as a 500.
            if ($value !== null && mb_strlen($value) > self::MAX[$column]) {
                throw ValidationException::withMessages([$column => "The {$column} may not be greater than ".self::MAX[$column].' characters.']);
            }

            $values[$column] = $value;
        }

        if ($creating && ! isset($values['name'])) {
            throw ValidationException::withMessages(['name' => 'The name is required.']);
        }

        return $values;
    }

    private function classificationFrom(mixed $value): Classification
    {
        $classification = is_string($value) ? Classification::tryFrom($value) : null;

        if ($classification === null) {
            throw ValidationException::withMessages(['classification' => 'The selected classification is invalid.']);
        }

        return $classification;
    }

    // ── Slug ─────────────────────────────────────────────────────────────────────────────────

    /**
     * Insert the row under a slug that is non-empty and unique. The slug is `Str::slug($name)`, or `organization` when that is empty (a name in a script
     * the transliterator cannot read, or only punctuation); a taken one gets `-2`, `-3`... The probe is unscoped (a slug is unique across every tenant) and is
     * only a first guess: the UNIQUE KEY decides. An insert that loses the race to another request fails on `organizations_slug_unique` and tries the next
     * candidate, at most {@see SLUG_ATTEMPTS} times; ANY other database error, including a duplicate on another key, is rethrown at once.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function insertWithUniqueSlug(string $name, array $attributes): int
    {
        $base = Str::limit(Str::slug($name), 200, '');
        $base = $base === '' ? 'organization' : $base;
        $suffix = 1;

        for ($attempt = 1; $attempt <= self::SLUG_ATTEMPTS; $attempt++) {
            // The probe is bounded too (CP6, F-8): at most SLUG_PROBES taken candidates are walked; beyond that the suffix is random, so a crowded base never
            // costs an unbounded number of queries and still ends in the unique key, which decides.
            for ($probe = 0; $probe < self::SLUG_PROBES && DB::table('organizations')->where('slug', $this->candidate($base, $suffix))->exists(); $probe++) {
                $suffix++;
            }

            // The LAST attempt does not guess: a plain probe reads this transaction's snapshot and cannot see a row another request has just committed, so N
            // simultaneous creations of one name could otherwise outrun the attempts. A random suffix cannot collide in practice.
            $slug = $probe === self::SLUG_PROBES || $attempt === self::SLUG_ATTEMPTS ? $base.'-'.Str::lower(Str::random(8)) : $this->candidate($base, $suffix);

            try {
                return (int) DB::table('organizations')->insertGetId([...$attributes, 'slug' => $slug]);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) !== 1062 || ! str_contains($e->getMessage(), 'organizations_slug_unique')) {
                    throw $e;
                }

                $suffix++;
            }
        }

        throw new \RuntimeException('A unique slug could not be found for the Organization; try again.');
    }

    private function candidate(string $base, int $suffix): string
    {
        return $suffix === 1 ? $base : "{$base}-{$suffix}";
    }

    // ── Audit ────────────────────────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $properties */
    private function audit(User $actor, int $organizationId, string $event, array $properties, ?Organization $subject = null): void
    {
        $subject ??= (new Organization)->forceFill(['id' => $organizationId]);
        $subject->exists = true;

        activity()->causedBy($actor)->performedOn($subject)->withProperties($properties)->log($event);
    }

    /**
     * What an audit entry may carry of a profile: the values, WITHOUT the free-text notes (an entry in the log is read in places the Directory page is not). That
     * a note was written, changed or cleared is recorded separately, by name only, by {@see internal()}: an old or new value of "(changed)" for it would read the
     * same for a first write, an edit and a clear.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function logged(array $values): array
    {
        unset($values[self::NOT_LOGGED]);

        return $values;
    }

    /**
     * The property that says an internal field was touched, by NAME and never by value: `internal_fields_changed: ['notes']` on an update (a write, an edit or a
     * clear), `internal_fields_set: ['notes']` on a creation that supplied one. Empty (no property) when no internal field is involved.
     *
     * @param  array<string, mixed>  $values  the values written (the keys are what matters)
     * @return array<string, list<string>>
     */
    private function internal(array $values, string $property, bool $set = false): array
    {
        $touched = array_key_exists(self::NOT_LOGGED, $values) && (! $set || $values[self::NOT_LOGGED] !== null);

        return $touched ? [$property => [self::NOT_LOGGED]] : [];
    }
}
