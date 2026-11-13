<?php

namespace App\Auth\Sso;

/**
 * Which identity providers this installation has been configured for. Credentials ARE the activation switch: with no client id and secret
 * a provider is simply not available (initiation answers with a plain message instead of failing), so merging this code activates nothing
 * and a provider is switched on in an environment only by an owner who puts its credentials there.
 */
final class SsoProviders
{
    public const SUPPORTED = ['google', 'microsoft'];

    public static function supported(string $provider): bool
    {
        return in_array($provider, self::SUPPORTED, true);
    }

    public static function configured(string $provider): bool
    {
        if (! self::supported($provider)) {
            return false;
        }

        $id = config("services.{$provider}.client_id");
        $secret = config("services.{$provider}.client_secret");
        $redirect = config("services.{$provider}.redirect");

        $complete = is_string($id) && trim($id) !== '' && is_string($secret) && trim($secret) !== '' && is_string($redirect) && trim($redirect) !== '';

        // A mistyped Microsoft tenant would silently refuse every sign-in; it is "not available" instead, which is visible.
        return $complete && ($provider !== 'microsoft' || self::validMicrosoftTenant((string) config('services.microsoft.tenant', 'common')));
    }

    /** `common` (also when empty), `organizations`, `consumers` or one tenant GUID. */
    public static function validMicrosoftTenant(string $tenant): bool
    {
        $tenant = strtolower(trim($tenant));

        // An empty value is the documented default, `common` (the driver reads it that way).
        return $tenant === '' || in_array($tenant, ['common', 'organizations', 'consumers'], true)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $tenant) === 1;
    }

    public static function anyConfigured(): bool
    {
        foreach (self::SUPPORTED as $provider) {
            if (self::configured($provider)) {
                return true;
            }
        }

        return false;
    }
}
