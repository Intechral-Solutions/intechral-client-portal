<?php

namespace App\Services;

/**
 * What an invitee brings to {@see OrganizationInvitations::accept()}: a display name and ONE credential, both already prepared.
 *
 * The password HASH, never the password: bcrypt is the slow step, and nothing slow may run while the issuer, organization and invitation
 * rows are locked, so the caller hashes before it asks (Hash::make). For an identity provider the provider's verified identity travels
 * instead; the core compares its address with the invitation's, by the same normalizer, and refuses a mismatch.
 */
final class InvitationAcceptance
{
    /**
     * @param  array{name: ?string, email: ?string, provider_id: string, token: ?string, refresh_token: ?string, token_expires_at: mixed}|null  $identity
     */
    private function __construct(
        public readonly string $name,
        public readonly ?string $passwordHash,
        public readonly ?string $provider,
        public readonly ?array $identity,
    ) {}

    public static function withPassword(string $name, string $passwordHash): self
    {
        return new self($name, $passwordHash, null, null);
    }

    /** @param  array{name: ?string, email: ?string, provider_id: string, token: ?string, refresh_token: ?string, token_expires_at: mixed}  $identity */
    public static function withProvider(string $provider, array $identity): self
    {
        return new self((string) ($identity['name'] ?? ''), null, $provider, $identity);
    }
}
