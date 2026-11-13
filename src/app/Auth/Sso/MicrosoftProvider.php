<?php

namespace App\Auth\Sso;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User;
use LogicException;
use RuntimeException;

/**
 * Sign in with Microsoft (Microsoft identity platform v2.0, authorization-code flow with PKCE), on Socialite's own provider base class.
 *
 * `laravel/socialite` ships no Microsoft driver, and the community driver reads the Graph `/me` record, which carries no statement that the
 * address was verified. This driver reads the signed ID TOKEN instead and validates it before believing a single claim:
 *
 *  - the signature, against the keys Microsoft publishes (`firebase/php-jwt`, already a dependency of Socialite's Google driver), with
 *    `exp`/`nbf` enforced by the library. Only RSA signature keys are used and the algorithm is PINNED to RS256 whatever a key or the token
 *    says (Microsoft's key set carries no `alg`, which the library refuses without a default; pinning also rules out `none` and HS256 key
 *    confusion). The key set is cached for an hour and fetched again, once, when a token names a key id the cache does not hold;
 *  - the SIGNING KEY IS BOUND TO THE ISSUER: each published key names the issuer it may sign for (`{tenantid}` for Entra tenants, the personal
 *    accounts tenant for consumer keys), and that must equal the token's `iss`. A key that has no issuer, or one issued for another tenant, is
 *    refused. This is Microsoft's own guidance after a consumer signing key was used to mint work-tenant tokens;
 *  - the audience is this application's client id;
 *  - the tenant `tid` is a GUID, the issuer is exactly `https://login.microsoftonline.com/{tid}/v2.0`, and the tenant is one the configured
 *    `tenant` setting allows (a single tenant GUID, `organizations` = work or school accounts only, `consumers` = personal accounts only,
 *    `common` = both);
 *  - the object id `oid` is a GUID.
 *
 * The identity is `{tid}.{oid}`: an object id is unique only inside its tenant, so the tenant is part of the key. Anything that fails is an
 * exception, which the controller treats as a provider failure: nothing is trusted partially.
 * The mail address is the `email` claim ONLY (see {@see ProviderIdentity} for why the other address-like values are never read).
 */
class MicrosoftProvider extends AbstractProvider
{
    public const CONSUMERS_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';

    private const LOGIN_HOST = 'https://login.microsoftonline.com';

    protected $scopes = ['openid', 'profile', 'email'];

    protected $scopeSeparator = ' ';

    protected $usesPKCE = true;

    protected function tenant(): string
    {
        $tenant = strtolower(trim((string) config('services.microsoft.tenant', 'common')));

        return $tenant === '' ? 'common' : $tenant;
    }

    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase(self::LOGIN_HOST.'/'.$this->tenant().'/oauth2/v2.0/authorize', $state);
    }

    protected function getTokenUrl()
    {
        return self::LOGIN_HOST.'/'.$this->tenant().'/oauth2/v2.0/token';
    }

    protected function getUserByToken($token)
    {
        throw new LogicException('The Microsoft driver reads the validated ID token, not a user endpoint.');
    }

    protected function mapUserToObject(array $user)
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['tid'].'.'.$user['oid'],
            'nickname' => null,
            'name' => Arr::get($user, 'name'),
            'email' => Arr::get($user, 'email'),
            'avatar' => null,
        ]);
    }

    public function user()
    {
        if ($this->user) {
            return $this->user;
        }

        if ($this->hasInvalidState()) {
            throw new InvalidStateException;
        }

        $response = $this->getAccessTokenResponse($this->getCode());
        $idToken = Arr::get($response, 'id_token');

        if (! is_string($idToken) || $idToken === '') {
            throw new RuntimeException('The Microsoft token response carried no ID token.');
        }

        $this->user = $this->mapUserToObject($this->validatedClaims($idToken));

        return $this->user
            ->setToken(Arr::get($response, 'access_token'))
            ->setRefreshToken(Arr::get($response, 'refresh_token'))
            ->setExpiresIn(Arr::get($response, 'expires_in'))
            ->setApprovedScopes(explode($this->scopeSeparator, Arr::get($response, 'scope', '')));
    }

    /** @return array<string, mixed> */
    protected function validatedClaims(string $idToken): array
    {
        $kid = $this->headerKeyId($idToken);
        $keys = $this->signingKeys(false);

        if (! $this->hasKey($keys, $kid)) {
            $keys = $this->signingKeys(true);
        }

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = 60;
        $headers = new \stdClass;

        try {
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($keys, 'RS256'), $headers);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        $guid = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';
        $tid = $claims['tid'] ?? null;
        $oid = $claims['oid'] ?? null;

        if (! is_string($tid) || preg_match($guid, $tid) !== 1 || ! is_string($oid) || preg_match($guid, $oid) !== 1) {
            throw new RuntimeException('The Microsoft ID token has no valid tenant or object id.');
        }

        if (($claims['iss'] ?? null) !== self::LOGIN_HOST.'/'.$tid.'/v2.0') {
            throw new RuntimeException('The Microsoft ID token issuer does not match its tenant.');
        }

        // The key that verified the signature must be one Microsoft published for THIS issuer.
        $keyIssuer = collect($keys['keys'])->first(fn ($key) => ($key['kid'] ?? null) === ($headers->kid ?? false))['issuer'] ?? null;

        if (! is_string($keyIssuer) || str_replace('{tenantid}', $tid, $keyIssuer) !== $claims['iss']) {
            throw new RuntimeException('The Microsoft signing key is not published for this token issuer.');
        }

        $audience = $claims['aud'] ?? null;

        if (! is_string($audience) || ! hash_equals((string) $this->clientId, $audience)) {
            throw new RuntimeException('The Microsoft ID token audience is not this application.');
        }

        if (! $this->tenantAllowed(strtolower($tid))) {
            throw new RuntimeException('The Microsoft ID token tenant is not allowed by this installation.');
        }

        $claims['tid'] = strtolower($tid);
        $claims['oid'] = strtolower($oid);

        return $claims;
    }

    private function tenantAllowed(string $tid): bool
    {
        return match ($this->tenant()) {
            'common' => true,
            'organizations' => $tid !== self::CONSUMERS_TENANT,
            'consumers' => $tid === self::CONSUMERS_TENANT,
            default => hash_equals($this->tenant(), $tid),
        };
    }

    /** The key id the token's header names (untrusted: it only selects which published key to try; the library verifies the signature). */
    private function headerKeyId(string $idToken): ?string
    {
        $header = json_decode((string) base64_decode(strtr(explode('.', $idToken)[0], '-_', '+/'), true), true);

        return is_array($header) && is_string($header['kid'] ?? null) ? $header['kid'] : null;
    }

    /** @param  array<string, mixed>  $keys */
    private function hasKey(array $keys, ?string $kid): bool
    {
        return $kid !== null && collect($keys['keys'] ?? [])->contains(fn ($key) => is_array($key) && ($key['kid'] ?? null) === $kid);
    }

    /**
     * The published signing keys, restricted to RSA signature keys, cached for an hour. The same keys serve every tenant; the tenant-less
     * endpoint is the documented source for a multi-tenant application. A fetch failure or an empty set throws (nothing is trusted).
     *
     * @return array{keys: array<int, array<string, mixed>>}
     */
    protected function signingKeys(bool $refresh = false): array
    {
        $cacheKey = 'sso.microsoft.signing-keys';

        if (! $refresh && is_array($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $published = Http::timeout(10)->acceptJson()->get(self::LOGIN_HOST.'/common/discovery/v2.0/keys')->throw()->json();

        $keys = collect(is_array($published) ? ($published['keys'] ?? []) : [])
            ->filter(fn ($key) => is_array($key) && ($key['kty'] ?? null) === 'RSA' && in_array($key['use'] ?? 'sig', ['sig'], true) && is_string($key['kid'] ?? null))
            ->values()
            ->all();

        if ($keys === []) {
            throw new RuntimeException('Microsoft published no usable signing keys.');
        }

        Cache::put($cacheKey, ['keys' => $keys], now()->addHour());

        return ['keys' => $keys];
    }
}
