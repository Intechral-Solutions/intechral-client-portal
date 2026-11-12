<?php

namespace App\Services;

use App\Exceptions\OrganizationInvitationException;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\PasswordHistory;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Support\Database\DuplicateKey;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Organization-bound invitations (Release 1.0, R1-3A; docs/product/release-1.0-r1-3-organization-invitations.md). The ONLY writer of
 * `organization_invitations` (a source-level test pins that). An authorized operator invites a NEW person into ONE named organization; the
 * invitation is a hashed, expiring, revocable, single-use credential; accepting it atomically creates the account AND an ordinary
 * (never administrator, never approved) membership.
 *
 * STATE IS DERIVED. There is no status and no role column: accepted if `accepted_at`, revoked if `revoked_at`, expired if `expires_at` has
 * passed, otherwise pending. Time needs no write and no job.
 *
 * THE SLOT. `live_email` is the row currently holding the (organization, address) uniqueness slot. It is not an "unexpired" marker and is
 * not cleared by time. Acceptance, an operator's revocation and supersession clear it. The SYSTEM revocations (suspension, reactivation, the
 * role and permission hooks) do NOT (O4/R1: writing it would make InnoDB share-lock the organization row from inside a hook): a row they
 * revoke keeps the slot, exactly like an expired one, and ISSUANCE releases any holder that is no longer open under the organization lock
 * before it inserts. UNIQUE (organization_id, live_email) is only the backstop.
 *
 * LOCK ORDER. Every transaction here that will insert a row referencing a user takes a SHARED lock on that user's row as its FIRST
 * statement (the issuing operator, or the invitation's issuer), because InnoDB share-locks the referenced user row while checking the
 * foreign key, and suspension holds that row exclusively. The order is then:
 *
 *   user row (S)  ->  organization row (X)  ->  invitation row (X)  ->  the rest (users insert, membership, social account)
 *
 * and no consistent read may precede the first statement, so the transaction's snapshot begins after the locks. The revocation hooks run
 * inside the existing guarded transactions of suspension and role changes, take NO organization lock, and lock in this order instead
 * (O4 + R4, see {@see reconcileIssuers()}): users.manage -> acting user (S) -> permission-cache epoch advance (permission-mapping mutations)
 * -> invitation rows (X, primary key ascending) -> the mutation -> comparison -> revocation and audit. docs ... section 6.2.3 has the
 * acquisition table and states what the row-level argument does NOT cover: InnoDB also takes locks for secondary-index maintenance
 * (the unique `(organization_id, live_email)` key and the foreign-key indexes) and for foreign-key checks (a share lock on the
 * referenced user row for `revoked_by`, `invited_by`, `accepted_user_id`), beyond the primary-key record locks the argument is about, and
 * index-gap locks under REPEATABLE READ. No deadlock-freedom claim is made beyond the row level; a cycle ends in one atomic abort.
 *
 * AUTHORITY is read from the TABLES ({@see OrganizationAuthority::isMembershipManager()}), never the permission cache, wherever a locked
 * transaction decides. Issuance keeps the Gate (`manageMembership`), the existing approved boundary for a signed-in operator, and
 * re-checks the tables under lock.
 *
 * THE TOKEN. Only `hash('sha256', $token)` is stored. The plaintext exists in the queued, encrypted, after-commit mail and in the link.
 */
final class OrganizationInvitations
{
    /** One constant for `expires_at` and for the words in the mail. */
    public const LIFETIME_DAYS = 7;

    // Revocation reasons: a closed set of stable codes (C1). Descriptive only; `revoked_at` is the state.
    public const REASON_OPERATOR = 'operator';

    public const REASON_SUPERSEDED = 'superseded';

    public const REASON_ISSUER_SUSPENDED = 'issuer_suspended';

    public const REASON_ISSUER_AUTHORITY_LOST = 'issuer_authority_lost';

    public const REASON_ISSUER_REINSTATED = 'issuer_reinstated';

    public const REASONS = [
        self::REASON_OPERATOR,
        self::REASON_SUPERSEDED,
        self::REASON_ISSUER_SUSPENDED,
        self::REASON_ISSUER_AUTHORITY_LOST,
        self::REASON_ISSUER_REINSTATED,
    ];

    // Audit events (subject: the organization). Never the token or its hash.
    public const ISSUED = 'organization invitation issued';

    public const SUPERSEDED = 'organization invitation superseded';

    public const REVOKED = 'organization invitation revoked';

    // Where a system revocation came from (audit `source`).
    public const SOURCE_SUSPENSION = 'account suspension';

    public const SOURCE_REACTIVATION = 'account reactivation';

    public const SOURCE_USER_ROLES = 'user roles changed';

    public const SOURCE_ROLE_PERMISSIONS = 'role permissions changed';

    public const SOURCE_ROLES_SYNC = 'roles:sync';

    // Duplicate-key names (the violated key decides what a duplicate means).
    public const KEY_USER_EMAIL = 'users_email_unique';

    public const KEY_SOCIAL_IDENTITY = 'social_accounts_provider_provider_id_unique';

    public const KEY_SLOT = 'organization_invitations_org_live_email_unique';

    public const KEY_TOKEN_HASH = 'organization_invitations_token_hash_unique';

    private const ATTEMPTS = 3;

    /**
     * What the acceptance orchestrator has locked, set only by {@see acceptLocked()} and read by {@see holdsAcceptanceLocks()}. It is the
     * proof OrganizationMembership::addFromInvitation() requires: an arbitrary open transaction is NOT proof that its locks were taken in
     * the canonical order, so the membership seam refuses a caller that cannot show it was reached from here, at the transaction level at
     * which these locks were taken. Process-local; cleared in a `finally`.
     *
     * @var array{invitation: int, level: int, connection: string}|null
     */
    private static ?array $acceptanceLocks = null;

    /** @var array<int, Organization|null> */
    private array $subjects = [];

    public function __construct(private readonly OrganizationMembership $memberships) {}

    // ── Pure helpers ──────────────────────────────────────

    /**
     * THE normalizer for every address this feature writes, looks up or compares (issue, the existing-account check, the provider
     * comparison, the address a new account is created with). There is no other for `users.email`, which is protected only by its
     * case- and accent-insensitive collation, so equality is still decided BY THE DATABASE (`where email = ?`), never by a PHP string
     * comparison; this keeps the STORED values canonical.
     */
    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** A token is exactly 64 URL-safe characters; anything else cannot be one of ours and costs no query. */
    public static function wellFormedToken(string $token): bool
    {
        return preg_match('/\A[A-Za-z0-9]{64}\z/', $token) === 1;
    }

    /**
     * The seam's proof (see {@see $acceptanceLocks}): true only inside acceptance's own transaction level, for the invitation whose
     * locks it took, on the connection it took them on.
     */
    public static function holdsAcceptanceLocks(int $invitationId): bool
    {
        $connection = DB::connection();

        return self::$acceptanceLocks !== null
            && self::$acceptanceLocks['invitation'] === $invitationId
            && self::$acceptanceLocks['level'] === $connection->transactionLevel()
            && self::$acceptanceLocks['connection'] === $connection->getName();
    }

    // ── Issue / resend ────────────────────────────────────

    /**
     * Invite a NEW person into an organization, or re-issue for the same address (resend: a new token and expiry; an open predecessor is
     * superseded, an expired one only has its slot released, and keeps its Expired state). The mail is queued AFTER the transaction has
     * committed. Returns the stored row; the plaintext token is known only to the mail.
     *
     * @throws AuthorizationException the actor is not a membership manager
     * @throws OrganizationInvitationException the address already belongs to an account, or an invitation was issued concurrently
     */
    public function issue(Organization $organization, string $email, User $actor): OrganizationInvitation
    {
        $this->authorize($actor);
        $address = self::normalizeEmail($email);

        [$invitation, $token] = DB::transaction(function () use ($organization, $address, $actor) {
            // FIRST statement: share-lock the acting operator's row (a suspension holds it exclusively).
            $this->lockUserShared($actor->getKey());
            $org = $this->lockOrganization($organization->getKey()) ?? throw OrganizationInvitationException::notFound();

            // A Gate answer can be old for the cache's lifetime; under the locks the answer comes from the tables.
            if (! OrganizationAuthority::isMembershipManager($actor->getKey())) {
                throw new AuthorizationException;
            }

            if (DB::table('users')->where('email', $address)->exists()) {
                throw OrganizationInvitationException::accountExists();
            }

            $now = now();
            $holder = DB::table('organization_invitations')
                ->where('organization_id', $org->id)->where('live_email', $address)
                ->lockForUpdate()->first();

            $superseded = null;

            if ($holder !== null) {
                if ($this->isOpenRow($holder, $now)) {
                    $this->release($holder->id, ['revoked_at' => $now, 'revoked_by' => $actor->getKey(), 'revoked_reason' => self::REASON_SUPERSEDED]);
                    $superseded = $holder;
                } else {
                    // Not open: expired, or revoked by a hook (which never writes the slot, O4/R1) and still holding it. Clear ONLY the slot. An
                    // expired row keeps `revoked_at` null, so it stays Expired and is never relabelled "revoked"; a revoked row keeps its
                    // revocation; no audit entry is needed for a release.
                    $this->release($holder->id, []);
                }
            }

            [$invitation, $token] = $this->insertInvitation($org->id, $address, $actor, $now);

            if ($superseded !== null) {
                $this->audit($actor, $organization, self::SUPERSEDED, [
                    'invitation_id' => (int) $superseded->id,
                    'email' => $address,
                    'invited_by' => (int) $superseded->invited_by,
                    'reason' => self::REASON_SUPERSEDED,
                    'source' => 'reissue',
                    'replaced_by' => $invitation->id,
                ]);
            }

            $this->audit($actor, $organization, self::ISSUED, [
                'invitation_id' => $invitation->id,
                'email' => $address,
                'invited_by' => $actor->getKey(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ]);

            return [$invitation, $token];
        }, self::ATTEMPTS);

        // After commit, never inside the transaction. The notification is ShouldQueueAfterCommit too, so a caller that reached this method
        // inside an outer transaction still cannot enqueue before that commits.
        Notification::route('mail', $address)->notify(new OrganizationInvitationNotification($invitation->id, $token));

        return $invitation;
    }

    // ── Revoke ────────────────────────────────────────────

    /**
     * An operator revokes an OPEN invitation of this organization. Idempotent on a terminal row (accepted, revoked, or expired by time):
     * returns false and writes nothing, not even a second audit entry. An acceptance that has not locked the row is refused afterwards; one
     * that already committed is not undone.
     *
     * @throws OrganizationInvitationException NOT_FOUND when the invitation is not this organization's
     */
    public function revoke(Organization $organization, int $invitationId, User $actor): bool
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($organization, $invitationId, $actor): bool {
            $this->lockUserShared($actor->getKey());
            $org = $this->lockOrganization($organization->getKey()) ?? throw OrganizationInvitationException::notFound();

            if (! OrganizationAuthority::isMembershipManager($actor->getKey())) {
                throw new AuthorizationException;
            }

            $row = DB::table('organization_invitations')->where('id', $invitationId)->where('organization_id', $org->id)->lockForUpdate()->first()
                ?? throw OrganizationInvitationException::notFound();

            $now = now();

            if (! $this->isOpenRow($row, $now)) {
                return false;
            }

            $this->release($row->id, ['revoked_at' => $now, 'revoked_by' => $actor->getKey(), 'revoked_reason' => self::REASON_OPERATOR]);
            $this->audit($actor, $organization, self::REVOKED, [
                'invitation_id' => (int) $row->id,
                'email' => $row->email,
                'invited_by' => (int) $row->invited_by,
                'reason' => self::REASON_OPERATOR,
                'source' => 'operator',
            ]);

            return true;
        }, self::ATTEMPTS);
    }

    // ── Presentation ──────────────────────────────────────

    /**
     * The open invitation behind a token, for presenting the registration page: a plain read that decides nothing (acceptance re-checks
     * everything under lock). Null, for EVERY kind of dead link, when the token is malformed, unknown, accepted, revoked, expired, the
     * address already has an account, the issuer is no longer a membership manager, or the organization is gone. Carries
     * `organization_name`.
     */
    public function findOpen(string $token): ?OrganizationInvitation
    {
        if (! self::wellFormedToken($token)) {
            return null;
        }

        $invitation = OrganizationInvitation::query()
            ->join('organizations', 'organizations.id', '=', 'organization_invitations.organization_id')
            ->where('organization_invitations.token_hash', self::hashToken($token))
            ->first(['organization_invitations.*', 'organizations.name as organization_name']);

        if ($invitation === null || ! $invitation->isOpen() || $invitation->live_email !== $invitation->email) {
            return null;
        }

        if (DB::table('users')->where('email', $invitation->email)->exists() || ! OrganizationAuthority::isMembershipManager($invitation->invited_by)) {
            return null;
        }

        return $invitation;
    }

    // ── Accept ────────────────────────────────────────────

    /**
     * Accept by the plaintext token (the password path). Atomically creates the account (role `user` only), the ordinary unapproved
     * `member` membership and marks the invitation accepted. Any failure rolls back everything; a refused attempt writes nothing.
     *
     * @throws OrganizationInvitationException UNAVAILABLE for every dead link (see its `detail`), EMAIL_MISMATCH for a provider identity
     */
    public function accept(string $token, InvitationAcceptance $acceptance): User
    {
        if (! self::wellFormedToken($token)) {
            throw OrganizationInvitationException::unavailable('malformed');
        }

        return $this->acceptByHash(self::hashToken($token), $acceptance);
    }

    /**
     * Accept by the token's hash: the identity-provider callback, which holds only the hash (the plaintext never enters the session).
     * The core, shared by both paths.
     */
    public function acceptByHash(string $tokenHash, InvitationAcceptance $acceptance): User
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $tokenHash) !== 1) {
            throw OrganizationInvitationException::unavailable('malformed');
        }

        // NOMINATE (no locks, nothing decided): which organization and issuer to lock. It is a plain read OUTSIDE the transaction, so it
        // starts no snapshot the locks below would be older than.
        $nominated = DB::table('organization_invitations')->where('token_hash', $tokenHash)->first(['id', 'organization_id', 'invited_by']);

        if ($nominated === null) {
            throw OrganizationInvitationException::unavailable('unknown');
        }

        try {
            return DB::transaction(
                fn () => $this->acceptLocked((int) $nominated->id, (int) $nominated->organization_id, (int) $nominated->invited_by, $tokenHash, $acceptance),
                self::ATTEMPTS,
            );
        } catch (QueryException $exception) {
            // The violated KEY decides what a duplicate means; an unknown or unreadable one is rethrown (a visible failure, never a
            // silently "unavailable" link). Keys that cannot happen for a newly created account (accepted_user_id, the membership key)
            // are rethrown too: they would be a bug.
            throw match (DuplicateKey::name($exception)) {
                self::KEY_USER_EMAIL => OrganizationInvitationException::unavailable('account_exists'),
                self::KEY_SOCIAL_IDENTITY => OrganizationInvitationException::unavailable('identity_taken'),
                default => $exception,
            };
        }
    }

    private function acceptLocked(int $invitationId, int $organizationId, int $issuerId, string $tokenHash, InvitationAcceptance $acceptance): User
    {
        // 1. Issuer's user row S (the FIRST statement), then the organization row X.
        $this->lockUserShared($issuerId);
        $organization = $this->lockOrganization($organizationId) ?? throw OrganizationInvitationException::unavailable('organization_missing');

        // 2. The invitation row X, by id; it must still be the one nominated (organization and issuer are immutable).
        $invitation = DB::table('organization_invitations')->where('id', $invitationId)->lockForUpdate()->first();

        if ($invitation === null || (int) $invitation->organization_id !== $organizationId || (int) $invitation->invited_by !== $issuerId
            || ! hash_equals((string) $invitation->token_hash, $tokenHash)) {
            throw OrganizationInvitationException::unavailable('unknown');
        }

        $now = now();

        if ($invitation->accepted_at !== null) {
            throw OrganizationInvitationException::unavailable('accepted');
        }

        if ($invitation->revoked_at !== null) {
            throw OrganizationInvitationException::unavailable('revoked');
        }

        if (! $this->isOpenRow($invitation, $now)) {
            throw OrganizationInvitationException::unavailable('expired');
        }

        if ($invitation->live_email === null || $invitation->live_email !== $invitation->email) {
            throw OrganizationInvitationException::unavailable('slot_lost');
        }

        // 3. The issuer must still be a membership manager, from the tables.
        if (! OrganizationAuthority::isMembershipManager($issuerId)) {
            throw OrganizationInvitationException::unavailable('issuer_unauthorized');
        }

        // 4. A cheap pre-check; the unique index is the arbiter under a race (a duplicate is classified by key).
        if (DB::table('users')->where('email', $invitation->email)->exists()) {
            throw OrganizationInvitationException::unavailable('account_exists');
        }

        if ($acceptance->identity !== null && self::normalizeEmail((string) ($acceptance->identity['email'] ?? '')) !== $invitation->email) {
            throw OrganizationInvitationException::emailMismatch();
        }

        self::$acceptanceLocks = ['invitation' => $invitationId, 'level' => DB::connection()->transactionLevel(), 'connection' => DB::connection()->getName()];

        try {
            // 5. The account: the address from the ROW, the credential precomputed, the inviter recorded. `invited_by` is the foreign
            //    key whose check needs the issuer's row, which is why step 1 took it first.
            $user = User::create([
                'name' => $acceptance->identity !== null ? ($acceptance->name !== '' ? $acceptance->name : $invitation->email) : $acceptance->name,
                'email' => $invitation->email,
                'password' => $acceptance->passwordHash,
                'invited_by' => $issuerId,
            ]);

            $user->assignRole('user');

            if ($acceptance->identity !== null) {
                SocialAccount::create([
                    'user_id' => $user->id,
                    'provider' => $acceptance->provider,
                    'provider_id' => $acceptance->identity['provider_id'],
                    'token' => $acceptance->identity['token'],
                    'refresh_token' => $acceptance->identity['refresh_token'],
                    'token_expires_at' => $acceptance->identity['token_expires_at'],
                ]);
            } else {
                PasswordHistory::create(['user_id' => $user->id, 'password' => $user->password, 'created_at' => $now]);
            }

            // 6. The membership, derived from the invitation (two identifiers, nothing else), with the proof set above.
            $this->memberships->addFromInvitation($invitationId, $user);

            // 7. Consume the invitation and release the slot.
            $updated = DB::table('organization_invitations')
                ->where('id', $invitationId)->whereNull('accepted_at')->whereNull('revoked_at')
                ->update(['accepted_at' => $now, 'accepted_user_id' => $user->id, 'live_email' => null, 'updated_at' => $now]);

            if ($updated !== 1) {
                throw new LogicException('The locked invitation could not be marked accepted.');
            }
        } finally {
            self::$acceptanceLocks = null;
        }

        return $user;
    }

    // ── Revocation hooks (issuer authority loss, 6.2) ─────

    /**
     * Run a covered mutation (a role assignment, a role's permission list, the built-in synchronization) and revoke the open invitations of
     * every issuer whose {@see OrganizationAuthority::isMembershipManager()} CHANGED because of it, in either direction. Loss revokes; a change
     * upward revokes too (`issuer_reinstated`), so an invitation that survived a race with the loss cannot come back. The comparison is of the
     * predicate, not of what was edited, so it is correct whatever the mutation touched. Runs inside the caller's guarded transaction
     * ({@see AccountAdministrators::guard()}, whose first statement is the `users.manage` lock) and takes NO organization lock.
     *
     * LOCK SEQUENCE (the O4 + R4 remediation of the 18.3 deadlocks). The invitation rows are locked BEFORE the mutation touches a role or
     * permission table, so no other transaction can hold an invitation row while waiting on a lock this one took for the mutation:
     *
     *   users.manage (the guard)  ->  acting user's row (S)  ->  [R4: advance the permission-cache epoch]  ->  invitation rows (X, primary
     *   key ascending)  ->  the mutation  ->  comparison  ->  revocation + audit
     *
     *  - R3: the revocation writes `revoked_by`, and InnoDB share-locks the referenced user row for that foreign-key check, so the actor's
     *    row is share-locked first, exactly where issuance and acceptance take it.
     *  - R4 (`$fenceCache`): a mutation that can read the permission mapping (a role's permission list, the synchronization, which may create
     *    permissions) loads it through the shared cache, and a cache MISS writes a row on this connection. The epoch is replaced BEFORE the
     *    invitation locks, inside this transaction, so any load made inside it uses this transaction's own private epoch key and no other
     *    transaction can hold or wait on that row; a reader on the old epoch populates only the old key. A user's role assignment does not
     *    touch the permission mapping, so it does not fence.
     *  - R2: every open invitation of the candidates is locked (primary key ascending) whether or not it ends up revoked. When `$candidates`
     *    is null, every open invitation is locked (a role's edit changes holders the code does not enumerate).
     *
     * The revocation itself never writes `live_email` or `organization_id` (O4/R1): a revoked invitation keeps its uniqueness slot until the
     * next authorized issuance releases it under the organization lock, exactly like an expired one.
     *
     * WHAT THIS GUARANTEES, AND WHAT IT DOES NOT. O4 + R4 resolves the REPRODUCED row-level cycles (the hook's foreign-key lock on the
     * organization row, primary-key-first against index-first access to one invitation, the role-detach next-key lock, suspension against
     * membership work, and the hook / cold-cache Ticket / re-issue cycle through the epoch's cache row). It does NOT guarantee freedom from
     * every InnoDB gap-lock cycle. The framework's cache `forget` of the absent legacy key, and `forgetManyIfExpired` when a transaction reads
     * an EXPIRED permission copy, take gap locks on the cache table that the hook's private-key store can fall into. That re-forms the
     * three-way cycle if a Ticket entry point makes its FIRST permission-cache load inside a transaction that later locks an organization:
     * HTTP is saved only by the `can:tickets.create` middleware, which loads the copy first; a non-HTTP path calling TicketService::create()
     * is not. TicketService is deliberately unchanged here; see the design record, 6.2.3 limitation 2 and 18.8.
     *
     * @template T
     *
     * @param  list<int>|null  $candidates  the accounts that may be affected, or null for every issuer of an open invitation
     * @param  Closure(): T  $mutation
     * @return T
     */
    public function reconcileIssuers(?array $candidates, Closure $mutation, string $source, ?User $actor = null, bool $fenceCache = false): mixed
    {
        $this->requireTransaction(__FUNCTION__);

        if ($actor !== null) {
            $this->lockUserShared($actor->getKey());
        }

        if ($fenceCache) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $locked = $this->lockOpenInvitations($candidates);
        $accounts = $candidates ?? $locked->pluck('invited_by')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        $before = OrganizationAuthority::membershipManagerIds($accounts);

        $result = $mutation();

        $after = OrganizationAuthority::membershipManagerIds($accounts);
        $reasons = [];

        foreach (array_diff($before, $after) as $id) {
            $reasons[$id] = self::REASON_ISSUER_AUTHORITY_LOST;
        }

        foreach (array_diff($after, $before) as $id) {
            $reasons[$id] = self::REASON_ISSUER_REINSTATED;
        }

        $this->revokeLocked($locked, $reasons, $source, $actor);

        return $result;
    }

    /**
     * Permanently revoke the OPEN invitations of the given issuers, each with its reason (issuer id => reason code). For the callers that
     * mutate nothing the invitations depend on (suspension, reactivation); a role or permission mutation goes through
     * {@see reconcileIssuers()}, which locks first. Order: the acting user's row (S), then the invitation rows (X, primary key ascending),
     * then the writes. Returns how many were revoked.
     *
     * Only an invitation that is open AT THE MOMENT OF THE WRITE is changed: the rows are locked first, the instant is taken AFTER the
     * locks, and the update repeats the conditions with that same instant, so an invitation that expired between the selection and the update
     * is left exactly as it is (Expired; never relabelled revoked), and an accepted or already revoked one is not touched. The audit has one
     * entry per row actually revoked. `live_email` and `organization_id` are never written (O4/R1).
     *
     * @param  array<int, string>  $reasonByIssuer
     */
    public function revokeIssuedBy(array $reasonByIssuer, string $source, ?User $actor = null): int
    {
        $this->requireTransaction(__FUNCTION__);

        if ($reasonByIssuer === []) {
            return 0;
        }

        foreach ($reasonByIssuer as $reason) {
            $this->assertReason($reason);
        }

        if ($actor !== null) {
            $this->lockUserShared($actor->getKey());
        }

        return $this->revokeLocked($this->lockOpenInvitations(array_keys($reasonByIssuer)), $reasonByIssuer, $source, $actor);
    }

    /**
     * The invitations that are open now and were issued by the given accounts (all issuers when null), locked by PRIMARY KEY, ascending.
     * A plain read only nominates the ids (it decides nothing and takes no lock); the locking read that follows sees the latest committed
     * rows, so a row that changed in between is returned as it now is and the caller filters it. The set is small.
     *
     * @param  list<int>|null  $issuers
     * @return Collection<int, object>
     */
    private function lockOpenInvitations(?array $issuers): Collection
    {
        $nominated = DB::table('organization_invitations')
            ->when($issuers !== null, fn ($query) => $query->whereIn('invited_by', $issuers))
            ->whereNull('accepted_at')->whereNull('revoked_at')
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($nominated === []) {
            return collect();
        }

        return DB::table('organization_invitations')->whereIn('id', $nominated)->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * Revoke, from rows that are ALREADY locked, those still open at one instant taken now (after the locks), with one update per distinct
     * reason over an ascending list of primary keys. Writes `revoked_at`, `revoked_by`, `revoked_reason` and `updated_at`; never `live_email`
     * (the slot stays held until issuance releases it) and never `organization_id` (so no foreign-key check reaches the organization row,
     * the second 18.3 mechanism).
     *
     * @param  Collection<int, object>  $locked
     * @param  array<int, string>  $reasonByIssuer
     */
    private function revokeLocked(Collection $locked, array $reasonByIssuer, string $source, ?User $actor): int
    {
        if ($reasonByIssuer === [] || $locked->isEmpty()) {
            return 0;
        }

        foreach ($reasonByIssuer as $reason) {
            $this->assertReason($reason);
        }

        $now = now();
        $open = $locked
            ->filter(fn (object $row) => isset($reasonByIssuer[(int) $row->invited_by]) && $this->isOpenRow($row, $now))
            ->values();

        if ($open->isEmpty()) {
            return 0;
        }

        $revoked = 0;

        foreach ($open->groupBy(fn (object $row) => $reasonByIssuer[(int) $row->invited_by]) as $reason => $rows) {
            $revoked += DB::table('organization_invitations')
                ->whereIn('id', $rows->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all())
                ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', $now)
                ->update([
                    'revoked_at' => $now,
                    'revoked_by' => $actor?->getKey(),
                    'revoked_reason' => $reason,
                    'updated_at' => $now,
                ]);

            foreach ($rows as $row) {
                $this->audit($actor, $this->subject((int) $row->organization_id), self::REVOKED, [
                    'invitation_id' => (int) $row->id,
                    'email' => $row->email,
                    'invited_by' => (int) $row->invited_by,
                    'reason' => $reason,
                    'source' => $source,
                ]);
            }
        }

        // The rows are locked, so the statement cannot have missed one; a difference would mean a broken invariant.
        if ($revoked !== $open->count()) {
            throw new LogicException("Revoked {$revoked} invitations but expected {$open->count()}.");
        }

        return $revoked;
    }

    // ── Internals ─────────────────────────────────────────

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize('manageMembership', Organization::class);
    }

    private function requireTransaction(string $method): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException("OrganizationInvitations::{$method}() must run inside the transaction of the change it accompanies.");
        }
    }

    private function assertReason(string $reason): void
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new LogicException("Unknown invitation revocation reason \"{$reason}\".");
        }
    }

    private function lockUserShared(int $userId): void
    {
        DB::table('users')->where('id', $userId)->sharedLock()->value('id');
    }

    private function lockOrganization(int $organizationId): ?object
    {
        $org = DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first(['id', 'name']);

        if ($org !== null) {
            $org->id = (int) $org->id;
        }

        return $org;
    }

    /** Accepted, revoked and expired are terminal; open means none of them, as of the given instant. */
    private function isOpenRow(object $row, CarbonInterface $now): bool
    {
        return $row->accepted_at === null
            && $row->revoked_at === null
            && Carbon::parse($row->expires_at)->gt($now);
    }

    /**
     * Take an invitation out of the slot, optionally revoking it in the same write. Only ever sets `revoked_at` to a value, never to null.
     *
     * @param  array<string, mixed>  $revocation
     */
    private function release(int|string $id, array $revocation): void
    {
        if (isset($revocation['revoked_reason'])) {
            $this->assertReason($revocation['revoked_reason']);
        }

        DB::table('organization_invitations')->where('id', $id)->update($revocation + ['live_email' => null, 'updated_at' => now()]);
    }

    /**
     * Insert the new slot holder. A duplicate on the token hash regenerates the token ONCE; a second one is rethrown. A duplicate on the
     * slot means another invitation took it concurrently (the organization lock should make that unreachable). Any other duplicate is
     * unknown and rethrown.
     *
     * @return array{0: OrganizationInvitation, 1: string}
     */
    private function insertInvitation(int $organizationId, string $address, User $actor, CarbonInterface $now): array
    {
        for ($attempt = 1; ; $attempt++) {
            $token = Str::random(64);

            try {
                $invitation = OrganizationInvitation::query()->forceCreate([
                    'organization_id' => $organizationId,
                    'email' => $address,
                    'token_hash' => self::hashToken($token),
                    'invited_by' => $actor->getKey(),
                    'expires_at' => $now->copy()->addDays(self::LIFETIME_DAYS),
                    'live_email' => $address,
                ]);

                return [$invitation, $token];
            } catch (QueryException $exception) {
                $key = DuplicateKey::name($exception);

                if ($key === self::KEY_SLOT) {
                    throw OrganizationInvitationException::concurrentIssue();
                }

                if ($key === self::KEY_TOKEN_HASH && $attempt < 2) {
                    continue;
                }

                throw $exception;
            }
        }
    }

    private function subject(int $organizationId): ?Organization
    {
        return $this->subjects[$organizationId] ??= Organization::withoutGlobalScopes()->find($organizationId);
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(?User $actor, ?Organization $organization, string $event, array $properties): void
    {
        $entry = activity()->withProperties($properties);

        if ($actor !== null) {
            $entry->causedBy($actor);
        }

        if ($organization !== null) {
            $entry->performedOn($organization);
        }

        $entry->log($event);
    }
}
