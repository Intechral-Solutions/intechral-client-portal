<?php

/*
 * Release 1.0 (SSO completion): the Microsoft driver and the provider-identity rules, against a LOCAL signing key. This is automated CONTRACT
 * verification: it proves the driver builds the documented request and accepts or refuses an ID token for the documented reasons, using a key
 * pair generated here and a stubbed token endpoint and key set. It does not prove that Microsoft's real tenants emit the claims (notably the
 * optional `xms_edov`): that is the live end-to-end check recorded in docs/product/release-1.0-sso-completion.md.
 */

use App\Auth\Sso\MicrosoftProvider;
use App\Auth\Sso\ProviderIdentity;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

const MS_TID = '11111111-2222-3333-4444-555555555555';
const MS_OID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
const MS_CLIENT = 'phpunit-microsoft-client-id';

/** @return array{private: string, jwk: array<string, string>} */
function msKey(string $kid = 'kid-1'): array
{
    static $keys = [];

    if (! isset($keys[$kid])) {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $private);
        $details = openssl_pkey_get_details($resource);
        $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        // Shaped like Microsoft's real key set: NO `alg`, an `x5t`, and the issuer the key may sign for (a `{tenantid}` template).
        $keys[$kid] = ['private' => $private, 'public' => $details['key'], 'jwk' => ['kty' => 'RSA', 'use' => 'sig', 'kid' => $kid, 'x5t' => $kid, 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']), 'issuer' => 'https://login.microsoftonline.com/{tenantid}/v2.0']];
    }

    return $keys[$kid];
}

/** @param array<string, mixed> $claims */
function msIdToken(array $claims = [], string $kid = 'kid-1', ?string $signWith = null): string
{
    $claims = [...[
        'iss' => 'https://login.microsoftonline.com/'.MS_TID.'/v2.0', 'aud' => MS_CLIENT, 'tid' => MS_TID, 'oid' => MS_OID, 'sub' => 'pairwise',
        'name' => 'Pat Example', 'email' => 'pat@example.test', 'xms_edov' => true, 'iat' => time() - 10, 'nbf' => time() - 10, 'exp' => time() + 3600,
    ], ...$claims];

    return JWT::encode(array_filter($claims, fn ($v) => $v !== null), msKey($signWith ?? $kid)['private'], 'RS256', $kid);
}

/** The driver, with a stubbed token endpoint returning $tokenResponse, and the published key set stubbed. @return array{0: MicrosoftProvider, 1: ArrayObject} */
function msDriver(array|string|null $tokenResponse, array $query = ['code' => 'the-code', 'state' => 'the-state'], ?array $jwks = null, string $tenant = 'common', bool $stubKeys = true): array
{
    config(['services.microsoft.tenant' => $tenant]);
    if ($stubKeys) {
        Http::fake(['login.microsoftonline.com/common/discovery/v2.0/keys' => Http::response($jwks ?? ['keys' => [msKey()['jwk']]])]);
    }

    $request = Request::create('/auth/microsoft/callback', 'GET', $query);
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('state', 'the-state');
    $request->session()->put('code_verifier', 'verifier-for-test');

    $history = new ArrayObject;
    $handler = HandlerStack::create(new MockHandler([new Response(200, [], is_array($tokenResponse) ? json_encode($tokenResponse) : (string) $tokenResponse)]));
    $handler->push(Middleware::history($history));

    $driver = new MicrosoftProvider($request, MS_CLIENT, 'phpunit-microsoft-client-secret', 'http://localhost:4242/auth/microsoft/callback');
    $driver->setHttpClient(new Client(['handler' => $handler]));

    return [$driver, $history];
}

describe('the authorization request', function () {
    it('goes to the tenant v2.0 endpoint with the code flow, openid profile email, a state and a PKCE challenge', function () {
        config(['services.microsoft.tenant' => 'common']);
        $request = Request::create('/auth/microsoft/redirect');
        $request->setLaravelSession(app('session.store'));
        $driver = new MicrosoftProvider($request, MS_CLIENT, 's', 'http://localhost:4242/auth/microsoft/callback');

        $url = $driver->redirect()->getTargetUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect($url)->toStartWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?')
            ->and($query['client_id'])->toBe(MS_CLIENT)
            ->and($query['response_type'])->toBe('code')
            ->and($query['scope'])->toBe('openid profile email')
            ->and($query['redirect_uri'])->toBe('http://localhost:4242/auth/microsoft/callback')
            ->and($query['code_challenge_method'])->toBe('S256')
            ->and($query['code_challenge'])->not->toBe('')
            ->and($query['state'])->not->toBe('')
            ->and($request->session()->get('code_verifier'))->not->toBeEmpty();
    });

    it('uses the configured tenant', function () {
        config(['services.microsoft.tenant' => MS_TID]);
        $request = Request::create('/auth/microsoft/redirect');
        $request->setLaravelSession(app('session.store'));

        expect((new MicrosoftProvider($request, MS_CLIENT, 's', 'http://x/cb'))->redirect()->getTargetUrl())
            ->toStartWith('https://login.microsoftonline.com/'.MS_TID.'/oauth2/v2.0/authorize?');
    });
});

describe('a validated ID token', function () {
    it('becomes a user keyed by tenant and object id, carrying the verified-email claim', function () {
        [$driver, $history] = msDriver(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600, 'scope' => 'openid profile email', 'id_token' => msIdToken()]);

        $user = $driver->user();

        expect($user->getId())->toBe(MS_TID.'.'.MS_OID)
            ->and($user->getEmail())->toBe('pat@example.test')
            ->and($user->getName())->toBe('Pat Example')
            ->and($user->token)->toBe('at')
            ->and($user->getRaw()['xms_edov'])->toBeTrue();

        // The code exchange carried the PKCE verifier and the configured secret, to the tenant token endpoint.
        parse_str((string) $history[0]['request']->getBody(), $body);
        expect((string) $history[0]['request']->getUri())->toBe('https://login.microsoftonline.com/common/oauth2/v2.0/token')
            ->and($body['code_verifier'])->toBe('verifier-for-test')
            ->and($body['code'])->toBe('the-code')
            ->and($body['grant_type'])->toBe('authorization_code');

        $identity = ProviderIdentity::from('microsoft', $user);
        expect($identity->subject)->toBe(MS_TID.'.'.MS_OID)->and($identity->verifiedEmail)->toBe('pat@example.test');
    });

    it('is refused for every way it can be wrong', function (callable $make) {
        [$tokenResponse, $tenant, $jwks, $message] = $make();
        [$driver] = msDriver($tokenResponse, jwks: $jwks, tenant: $tenant);

        expect(fn () => $driver->user())->toThrow(Exception::class, $message);
    })->with([
        'no id_token in the response' => [fn () => [['access_token' => 'at'], 'common', null, 'carried no ID token']],
        'a garbled token' => [fn () => [['access_token' => 'at', 'id_token' => 'not.a.jwt'], 'common', null, 'Malformed UTF-8 characters']],
        'a kid Microsoft did not publish (also after a refetch)' => [fn () => [['access_token' => 'at', 'id_token' => msIdToken(kid: 'attacker')], 'common', ['keys' => [msKey()['jwk']]], '"kid" invalid, unable to lookup correct key']],
        'signed by another key under a published kid' => [fn () => [['access_token' => 'at', 'id_token' => msIdToken(kid: 'kid-1', signWith: 'kid-other')], 'common', ['keys' => [msKey()['jwk']]], 'Signature verification failed']],
        'alg none' => [function () {
            $b = fn (array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
            $token = $b(['alg' => 'none', 'typ' => 'JWT', 'kid' => 'kid-1']).'.'.$b(['iss' => 'https://login.microsoftonline.com/'.MS_TID.'/v2.0', 'aud' => MS_CLIENT, 'tid' => MS_TID, 'oid' => MS_OID, 'email' => 'pat@example.test', 'xms_edov' => true, 'exp' => time() + 3600]).'.';

            return [['access_token' => 'at', 'id_token' => $token], 'common', null, 'Algorithm not supported'];
        }],
        'HS256 with the published public key as the secret' => [fn () => [['access_token' => 'at', 'id_token' => JWT::encode(['iss' => 'https://login.microsoftonline.com/'.MS_TID.'/v2.0', 'aud' => MS_CLIENT, 'tid' => MS_TID, 'oid' => MS_OID, 'email' => 'pat@example.test', 'xms_edov' => true, 'exp' => time() + 3600], msKey()['public'], 'HS256', 'kid-1')], 'common', null, 'Incorrect key for this algorithm']],
        'a signing key published for another issuer (a personal-account key vouching for a work tenant)' => [function () {
            $key = msKey()['jwk'];
            $key['issuer'] = 'https://login.microsoftonline.com/'.MicrosoftProvider::CONSUMERS_TENANT.'/v2.0';

            return [['access_token' => 'at', 'id_token' => msIdToken()], 'common', ['keys' => [$key]], 'not published for this token issuer'];
        }],
        'a signing key that names no issuer' => [function () {
            $key = msKey()['jwk'];
            unset($key['issuer']);

            return [['access_token' => 'at', 'id_token' => msIdToken()], 'common', ['keys' => [$key]], 'not published for this token issuer'];
        }],
        'expired' => [fn () => [['access_token' => 'at', 'id_token' => msIdToken(['exp' => time() - 3600])], 'common', null, 'Expired token']],
        'not yet valid' => [fn () => [['access_token' => 'at', 'id_token' => msIdToken(['nbf' => time() + 3600, 'iat' => time() + 3600])], 'common', null, 'nbf']],
        'issued for another application' => [fn () => [['access_token' => 'at', 'id_token' => msIdToken(['aud' => 'someone-elses-client'])], 'common', null, 'audience is not this application']],
        'an issuer that does not match its tenant' => [fn () => [['access_token' => 'at', 'id_token' => msIdToken(['iss' => 'https://login.microsoftonline.com/99999999-2222-3333-4444-555555555555/v2.0'])], 'common', null, 'issuer does not match its tenant']],
        'an issuer on another host' => [fn () => [['access_token' => 'at', 'id_token' => msIdToken(['iss' => 'https://evil.example/'.MS_TID.'/v2.0'])], 'common', null, 'issuer does not match its tenant']],
        'no tenant id' => [fn () => [['access_token' => 'at', 'id_token' => msIdToken(['tid' => null])], 'common', null, 'no valid tenant or object id']],
        'a tenant id that is not a GUID' => [fn () => [['access_token' => 'at', 'id_token' => msIdToken(['tid' => 'contoso', 'iss' => 'https://login.microsoftonline.com/contoso/v2.0'])], 'common', null, 'no valid tenant or object id']],
        'no object id' => [fn () => [['access_token' => 'at', 'id_token' => msIdToken(['oid' => null])], 'common', null, 'no valid tenant or object id']],
        'an object id that is not a GUID' => [fn () => [['access_token' => 'at', 'id_token' => msIdToken(['oid' => 'admin'])], 'common', null, 'no valid tenant or object id']],
        'a tenant other than the single configured one' => [fn () => [['access_token' => 'at', 'id_token' => msIdToken()], '99999999-2222-3333-4444-555555555555', null, 'tenant is not allowed']],
        'a personal account where only work accounts are allowed' => [fn () => [['access_token' => 'at', 'id_token' => msIdToken(['tid' => MicrosoftProvider::CONSUMERS_TENANT, 'iss' => 'https://login.microsoftonline.com/'.MicrosoftProvider::CONSUMERS_TENANT.'/v2.0'])], 'organizations', null, 'tenant is not allowed']],
        'a work account where only personal accounts are allowed' => [fn () => [['access_token' => 'at', 'id_token' => msIdToken()], 'consumers', null, 'tenant is not allowed']],
        'a key set with no keys' => [fn () => [['access_token' => 'at', 'id_token' => msIdToken()], 'common', ['keys' => []], 'no usable signing keys']],
        'a key set with only non-RSA keys' => [fn () => [['access_token' => 'at', 'id_token' => msIdToken()], 'common', ['keys' => [['kty' => 'oct', 'kid' => 'kid-1', 'k' => 'c2VjcmV0']]], 'no usable signing keys']],
    ]);

    it('fetches the key set once, and again only when a token names a key id it does not hold', function () {
        Http::fake(['login.microsoftonline.com/common/discovery/v2.0/keys' => Http::sequence()
            ->push(['keys' => [msKey('kid-old')['jwk']]])
            ->push(['keys' => [msKey('kid-old')['jwk'], msKey()['jwk']]])]);
        [$first] = msDriver(['access_token' => 'at', 'id_token' => msIdToken()], stubKeys: false);

        expect($first->user()->getId())->toBe(MS_TID.'.'.MS_OID);
        Http::assertSentCount(2);

        // Same keys again: served from the cache, no further request.
        [$second] = msDriver(['access_token' => 'at', 'id_token' => msIdToken()], stubKeys: false);
        $second->user();
        Http::assertSentCount(2);
    });

    it('keys the identity by tenant and object id only: case-normalised, and never by the mutable address', function () {
        [$first] = msDriver(['access_token' => 'at', 'id_token' => msIdToken(['email' => 'one@example.test'])]);
        $firstId = $first->user()->getId();
        [$second] = msDriver(['access_token' => 'at', 'id_token' => msIdToken(['email' => 'changed@example.test', 'name' => 'Renamed', 'oid' => strtoupper(MS_OID), 'tid' => strtoupper(MS_TID), 'iss' => 'https://login.microsoftonline.com/'.strtoupper(MS_TID).'/v2.0'])], stubKeys: false);

        expect($firstId)->toBe(MS_TID.'.'.MS_OID)
            ->and($second->user()->getId())->toBe(MS_TID.'.'.MS_OID);
    });

    it('is refused when the state does not match', function () {
        [$driver] = msDriver(['access_token' => 'at', 'id_token' => msIdToken()], ['code' => 'the-code', 'state' => 'someone-elses-state']);

        expect(fn () => $driver->user())->toThrow(InvalidStateException::class);
    });

    it('is refused when the key set cannot be fetched', function () {
        Http::fake(['login.microsoftonline.com/*' => Http::response('', 500)]);
        [$driver] = msDriver(['access_token' => 'at', 'id_token' => msIdToken()], stubKeys: false);

        expect(fn () => $driver->user())->toThrow(Exception::class);
    });

    it('accepts the single configured tenant, and either account kind under the matching setting', function (string $tenant, string $tid) {
        [$driver] = msDriver(['access_token' => 'at', 'id_token' => msIdToken(['tid' => $tid, 'iss' => 'https://login.microsoftonline.com/'.$tid.'/v2.0'])], tenant: $tenant);

        expect($driver->user()->getId())->toBe($tid.'.'.MS_OID);
    })->with([
        'single tenant' => [MS_TID, MS_TID],
        'organizations' => ['organizations', MS_TID],
        'consumers' => ['consumers', MicrosoftProvider::CONSUMERS_TENANT],
        'common (work)' => ['common', MS_TID],
        'common (personal)' => ['common', MicrosoftProvider::CONSUMERS_TENANT],
    ]);
});

describe('what counts as verified-email evidence', function () {
    function msIdentity(string $provider, array $raw): ProviderIdentity
    {
        return ProviderIdentity::from($provider, SocialiteUser::fake(['id' => 'subject', 'name' => 'N', 'email' => 'a@example.test', ...$raw]));
    }

    it('accepts the documented spellings only', function (string $provider, array $raw, bool $verified) {
        expect(msIdentity($provider, $raw)->verifiedEmail)->toBe($verified ? 'a@example.test' : null);
    })->with([
        'google true' => ['google', ['email_verified' => true], true],
        'google "true"' => ['google', ['email_verified' => 'true'], true],
        'google false' => ['google', ['email_verified' => false], false],
        'google "True"' => ['google', ['email_verified' => 'True'], false],
        'google 1' => ['google', ['email_verified' => 1], false],
        'google missing' => ['google', [], false],
        'microsoft true' => ['microsoft', ['xms_edov' => true], true],
        'microsoft 1' => ['microsoft', ['xms_edov' => 1], true],
        'microsoft "1"' => ['microsoft', ['xms_edov' => '1'], true],
        'microsoft "true"' => ['microsoft', ['xms_edov' => 'true'], true],
        'microsoft 0' => ['microsoft', ['xms_edov' => 0], false],
        'microsoft "yes"' => ['microsoft', ['xms_edov' => 'yes'], false],
        'microsoft missing' => ['microsoft', [], false],
        'an unknown provider' => ['github', ['email_verified' => true, 'xms_edov' => true], false],
    ]);

    it('gives no verified address when the address is absent or not an address, but still names the subject', function (?string $email) {
        $identity = ProviderIdentity::from('google', SocialiteUser::fake(['email_verified' => true, 'id' => 'subject', 'email' => $email]));

        expect($identity->verifiedEmail)->toBeNull()->and($identity->subject)->toBe('subject');
    })->with([null, '', '   ', 'not-an-address', 'a@']);

    it('has no subject for an empty id', function (mixed $id) {
        expect(ProviderIdentity::from('google', SocialiteUser::fake(['email_verified' => true, 'id' => $id]))->subject)->toBeNull();
    })->with([null, '', '   ']);
});
