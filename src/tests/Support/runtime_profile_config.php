<?php

/**
 * A configuration that satisfies the production runtime profile (R0.9 WP3-A), as `config()` overrides. Shared by the runtime preflight
 * tests; the secrets in it are fake markers the redaction tests look for in command output.
 *
 * @return array<string, mixed>
 */
function rtpProductionConfig(): array
{
    return [
        'app.env' => 'production',
        'app.debug' => false,
        'app.timezone' => 'UTC',
        'app.cipher' => 'AES-256-CBC',
        'app.key' => 'base64:'.base64_encode(str_pad('KEYMARKER', 32, 'x')),
        'app.url' => 'https://portal.provider.invalid',
        'database.default' => 'mysql',
        'cache.default' => 'database',
        'cache.prefix' => 'portal_prod_',
        'cache.stores.database.connection' => null,
        'cache.stores.database.lock_connection' => null,
        'cache.stores.database.table' => 'cache',
        'cache.stores.database.lock_table' => null,
        'permission.cache.store' => 'default',
        'queue.default' => 'database',
        'queue.connections.database.connection' => null,
        'queue.connections.database.table' => 'jobs',
        'queue.connections.database.retry_after' => 300,
        'queue.failed.driver' => 'database-uuids',
        'queue.failed.database' => 'mysql',
        'queue.failed.table' => 'failed_jobs',
        'session.driver' => 'database',
        'session.connection' => null,
        'session.table' => 'sessions',
        'session.secure' => true,
        'session.http_only' => true,
        'session.same_site' => 'lax',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.transport' => 'smtp',
        'mail.mailers.smtp.url' => null,
        'mail.mailers.smtp.host' => 'smtp.provider.invalid',
        'mail.mailers.smtp.port' => 587,
        'mail.mailers.smtp.timeout' => 20.0,
        'mail.mailers.smtp.username' => 'MAILUSERMARKER',
        'mail.mailers.smtp.password' => 'MAILPWMARKER',
        'mail.from.address' => 'portal@provider.invalid',
        'helpdesk.notify_address' => 'helpdesk@provider.invalid',
    ];
}

/** Put the application on the production runtime profile, with a fresh cache store resolved under the production prefix. */
function rtpUseProductionProfile(array $override = []): void
{
    config(rtpProductionConfig() + ['cache.stores.database.table' => 'cache']);
    config($override);
    app()->instance('config_loaded_from_cache', true);
    app('cache')->forgetDriver();
}
