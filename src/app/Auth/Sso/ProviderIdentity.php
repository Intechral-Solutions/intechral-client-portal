<?php

namespace App\Auth\Sso;

use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * What the portal is willing to believe about a signed-in provider account (Release 1.0, SSO completion).
 *
 * Two different questions are answered here, and they must not be confused:
 *
 *  - WHO is it: {@see $subject}, the provider's stable account identifier. This, and nothing else, finds or links a portal account. The email
 *    address never does (an address is an attribute a provider may let someone else choose; a subject is not).
 *  - IS THE ADDRESS THE PROVIDER'S CLAIM TO BE TRUSTED: {@see $verifiedEmail}. It is non-null only when the provider itself asserted, in the
 *    claim this provider is documented to use, that it verified the address. Anything else (the claim missing, false, a string that is not
 *    exactly the documented spelling, the address missing or not an address) is null, and a null means "do not use this address for
 *    anything": invitation acceptance refuses. It fails closed because absence of evidence is the case that matters.
 *
 * Provider rules (the claim that carries the evidence is read from the provider's own user data, never from a request parameter):
 *
 *  - Google: the OpenID `email_verified` claim must be the boolean `true` (or the string "true"); a number is not evidence.
 *  - Microsoft: the id token's `xms_edov` claim (the email domain owner was verified) must be true AND the `email` claim must be present. The
 *    `mail`, `preferred_username` and `userPrincipalName` values are never read: for an account from a tenant its holder administers they
 *    can be chosen by that holder (the "nOAuth" class of account takeover). `xms_edov` is an OPTIONAL claim, so a Microsoft app registration
 *    that does not add it makes every Microsoft invitation acceptance refuse: that is the intended failure direction.
 */
final class ProviderIdentity
{
    private function __construct(
        public readonly string $provider,
        public readonly ?string $subject,
        public readonly ?string $name,
        public readonly ?string $verifiedEmail,
    ) {}

    public static function from(string $provider, SocialiteUser $user): self
    {
        $raw = method_exists($user, 'getRaw') ? (array) $user->getRaw() : [];

        $subject = $user->getId();
        $subject = is_string($subject) || is_int($subject) ? trim((string) $subject) : '';

        $email = $user->getEmail();
        $email = is_string($email) ? trim($email) : '';
        $hasAddress = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

        $evidence = match ($provider) {
            'google' => self::isTrue($raw['email_verified'] ?? null, false),
            'microsoft' => self::isTrue($raw['xms_edov'] ?? null, true),
            default => false,
        };

        $name = $user->getName();

        return new self(
            $provider,
            $subject === '' ? null : $subject,
            is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : null,
            $evidence && $hasAddress ? $email : null,
        );
    }

    /**
     * The provider data never says "verified" loosely: the boolean true, the string "true", and (only where the provider documents a numeric
     * flag, which Microsoft's optional claims do) 1 or "1". Everything else, including "yes", "True " and any other number, is not evidence.
     */
    private static function isTrue(mixed $value, bool $numericFlag): bool
    {
        return $value === true
            || (is_string($value) && $value === 'true')
            || ($numericFlag && ($value === 1 || $value === '1'));
    }
}
